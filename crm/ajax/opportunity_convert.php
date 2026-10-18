<?php
// /crm/ajax/opportunity_convert.php
// Converts a "won" opportunity into a project through the Projects engine
// (ProjectRepo::createProject): the project gets its PRJ- number, the boards
// of the chosen template (POST template = blank | construction_standard |
// service | empty; default construction_standard), the opportunity's account
// as its client and the amount as its budget. The converting user owns and
// manages it; the opportunity owner is added as a project owner.
// The stage enum has no 'converted', so the opportunity stays 'won'; the
// link to the project is kept in audit_log (see crm_opp_project()) and a
// second convert returns the project already made.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];
$role      = (string)($_SESSION['role'] ?? 'viewer');

// Members and up can convert opportunities
crm_require_min_role('member');

$oppId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($oppId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid opportunity ID']);
    exit;
}
$template = isset($_POST['template']) ? (is_string($_POST['template']) ? trim($_POST['template']) : '') : 'construction_standard';
if (!isset(Templates::projects()[$template])) {
    echo json_encode(['ok' => false, 'error' => 'Unknown project template']);
    exit;
}

// One conversion per opportunity at a time (the engine runs its own
// transaction, so a row lock cannot span the whole conversion).
$lock = 'fwcrm:opp-convert:' . $companyId . ':' . $oppId;
$gotLock = false;
$repo = null;
$projectId = 0;
try {
    $gotLock = (bool)$DB->query("SELECT GET_LOCK(" . $DB->quote($lock) . ", 5)")->fetchColumn();
    if (!$gotLock) {
        throw new Exception('This opportunity is already being converted. Try again in a moment.');
    }

    // Fetch opportunity details
    $stmt = $DB->prepare("SELECT * FROM crm_opportunities WHERE id = ? AND company_id = ?");
    $stmt->execute([$oppId, $companyId]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$opp) {
        throw new Exception('Opportunity not found');
    }
    // Same rule as editing it (opportunity_update.php): an admin, or the opportunity's owner
    if (crm_role_rank($role) < crm_role_rank('admin') && (int)$opp['owner_id'] !== $userId) {
        throw new Exception('Only an admin or the opportunity owner can convert it');
    }

    $existing = crm_opp_project($DB, $companyId, $oppId);
    if ($existing) {
        // Already converted: hand back that project
        $response = ['ok' => true, 'project_id' => (int)$existing['project_id'],
                     'url' => ProjectLink::projectUrl((int)$existing['project_id']), 'existing' => true];
    } else {
        if ($opp['stage'] !== 'won') {
            throw new Exception('Only won opportunities can be converted');
        }

        // Create the project (the engine validates client, dates and budget)
        $repo = ProjectLink::repo($DB, $companyId, $userId, $role);
        $fields = [
            'name'       => $opp['title'],
            'client_id'  => (int)$opp['account_id'],
            'budget'     => max(0, (float)$opp['amount']),
            'start_date' => isset($_POST['start_date']) ? (is_string($_POST['start_date']) ? $_POST['start_date'] : 'invalid') : date('Y-m-d'),
            'template'   => $template,
        ];
        if (isset($_POST['end_date'])) {
            $fields['end_date'] = is_string($_POST['end_date']) ? $_POST['end_date'] : 'invalid';
        }
        if (isset($repo->people()[$userId])) {
            $fields['manager_id'] = $userId;
        }
        $projectId = $repo->createProject($fields);

        // Opportunity owner joins as a project owner (the engine notifies them)
        $ownerId = (int)$opp['owner_id'];
        if ($ownerId > 0 && $ownerId !== $userId && isset($repo->people()[$ownerId])) {
            $repo->setMember($projectId, $ownerId, 'owner');
        }

        $DB->beginTransaction();
        // Audit logs
        // Opportunity conversion log (also the opportunity → project link)
        $stmt = $DB->prepare("INSERT INTO audit_log (
                company_id, user_id, action, entity_type, entity_id, details, created_at
            ) VALUES (?, ?, 'convert', 'crm_opportunity', ?, ?, NOW())");
        $stmt->execute([
            $companyId,
            $userId,
            $oppId,
            json_encode(['project_id' => $projectId, 'template' => $template])
        ]);
        // Project creation log
        $stmt = $DB->prepare("INSERT INTO audit_log (
                company_id, user_id, action, entity_type, entity_id, details, created_at
            ) VALUES (?, ?, 'create', 'project', ?, ?, NOW())");
        $stmt->execute([
            $companyId,
            $userId,
            $projectId,
            json_encode(['from_opportunity' => $oppId])
        ]);
        $DB->commit();

        $response = ['ok' => true, 'project_id' => $projectId, 'url' => ProjectLink::projectUrl($projectId)];
    }

} catch (Throwable $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    // Remove a project whose conversion did not complete
    if ($projectId > 0 && $repo) {
        try {
            $repo->deleteProject($projectId);
        } catch (Throwable $e2) {
            error_log('Opportunity convert cleanup error: ' . $e2->getMessage());
        }
    }
    error_log('Opportunity convert error: ' . $e->getMessage());
    $response = ['ok' => false, 'error' => $e instanceof ProjectUserError ? $e->getMessage() : crm_public_error($e)];
} finally {
    if ($gotLock) {
        try { $DB->query("SELECT RELEASE_LOCK(" . $DB->quote($lock) . ")"); } catch (Throwable $e) {}
    }
}

echo json_encode($response);
