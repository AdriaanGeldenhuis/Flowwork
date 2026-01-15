<?php
// /payroll/ajax/run.post_gl.php
// Posts a locked payroll run to the general ledger by creating a journal entry
// and updating the pay run status to 'posted'. This script requires that
// the run be in the 'locked' state and that the user has appropriate
// permissions (admin or bookkeeper). On success, it returns a JSON
// response with ok: true. On failure it returns ok: false and an
// error message.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? '';

// Only allow admins and bookkeepers to post to finance
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$runId = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
if (!$runId) {
    echo json_encode(['ok' => false, 'error' => 'Missing run_id']);
    exit;
}

try {
    // Begin transaction for status update
    $DB->beginTransaction();

    // Load the run and validate
    $stmt = $DB->prepare("SELECT * FROM pay_runs WHERE id = ? AND company_id = ?");
    $stmt->execute([$runId, $companyId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$run) {
        echo json_encode(['ok' => false, 'error' => 'Run not found']);
        $DB->rollBack();
        exit;
    }

    if ($run['status'] !== 'locked') {
        echo json_encode(['ok' => false, 'error' => 'Only locked runs can be posted']);
        $DB->rollBack();
        exit;
    }

    // Include PostingService to create journal entries
    require_once __DIR__ . '/../../finances/lib/PostingService.php';

    // Instantiate posting service
    $postingService = new PostingService($DB, (int)$companyId, (int)$userId);

    // Perform posting; this will create the journal entry and link it to the run
    $postingService->postPayrollRun((int)$runId);

    // After posting, update run status to posted and record who posted
    $stmt = $DB->prepare(
        "UPDATE pay_runs SET status = 'posted', posted_by = ?, posted_at = NOW(), updated_at = NOW() WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$userId, $runId, $companyId]);

    // Log audit entry
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'payrun_post_gl', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId, $userId,
        json_encode(['run_id' => $runId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    // Roll back on failure
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Payroll post GL error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to post run: ' . $e->getMessage()]);
}
