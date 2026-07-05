<?php
// /crm/ajax/followup_create.php
// Create a follow-up reminder for a CRM account or opportunity.
// POST: linked_type (account|opportunity), linked_id, title, due_datetime,
//       minutes_before (default 60), channel (in_app|email|both)
// → { ok: true, event_id }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../includes/calendar_followup.php';

header('Content-Type: application/json');

crm_require_min_role('member');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $linkedType = trim($_POST['linked_type'] ?? '');
    $linkedId = (int)($_POST['linked_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $dueDatetime = trim($_POST['due_datetime'] ?? '');
    $minutesBefore = (int)($_POST['minutes_before'] ?? 60);
    $channelChoice = $_POST['channel'] ?? 'in_app';

    if (!$linkedId) {
        throw new Exception('Target record is required');
    }
    if ($dueDatetime === '') {
        throw new Exception('Follow-up date/time is required');
    }

    // The linked record must belong to this company
    if ($linkedType === 'account') {
        $stmt = $DB->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ?");
    } elseif ($linkedType === 'opportunity') {
        $stmt = $DB->prepare("SELECT title AS name FROM crm_opportunities WHERE id = ? AND company_id = ?");
    } else {
        throw new Exception('Invalid follow-up target type');
    }
    $stmt->execute([$linkedId, $companyId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        throw new Exception('Record not found');
    }

    if ($title === '') {
        $title = 'Follow up: ' . $record['name'];
    }

    $channels = $channelChoice === 'both' ? ['email', 'in_app'] : [$channelChoice];

    $eventId = crm_create_followup(
        $DB, $companyId, $userId,
        $linkedType, $linkedId,
        $title, $dueDatetime, $minutesBefore, $channels
    );

    echo json_encode(['ok' => true, 'event_id' => $eventId]);

} catch (Throwable $e) {
    error_log('CRM followup_create error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}
