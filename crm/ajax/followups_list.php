<?php
// /crm/ajax/followups_list.php
// Upcoming follow-ups (and other calendar events) linked to a CRM account or opportunity.
// GET: linked_type (account|opportunity), linked_id
// → { ok: true, events: [{id, title, start_datetime, all_day, channels, url}] }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../calendar/lib/autoload.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

try {
    $linkedType = trim($_GET['linked_type'] ?? 'account');
    $linkedId = (int)($_GET['linked_id'] ?? 0);

    if (!in_array($linkedType, ['account', 'opportunity'])) {
        throw new Exception('Invalid follow-up target type');
    }
    if (!$linkedId) {
        throw new Exception('Target record is required');
    }

    // Ownership check via the linked record's company
    $table = $linkedType === 'account' ? 'crm_accounts' : 'crm_opportunities';
    $stmt = $DB->prepare("SELECT id FROM `$table` WHERE id = ? AND company_id = ?");
    $stmt->execute([$linkedId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Record not found');
    }

    // Only events this user may see (private events of colleagues stay hidden).
    $events = CalendarLink::upcomingFor($DB, $companyId, $userId, (string)($_SESSION['role'] ?? 'viewer'), $linkedType, $linkedId, 10);

    echo json_encode(['ok' => true, 'events' => $events]);

} catch (Throwable $e) {
    error_log('CRM followups_list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}
