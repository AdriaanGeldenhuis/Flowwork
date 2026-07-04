<?php
// /crm/ajax/followups_list.php
// Upcoming follow-ups for a CRM account or opportunity.
// GET: linked_type (account|opportunity), linked_id
// → { ok: true, events: [{id, title, start_datetime, channels}] }

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

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

    $stmt = $DB->prepare("
        SELECT
            e.id,
            e.title,
            e.start_datetime,
            GROUP_CONCAT(DISTINCT r.channel) AS channels
        FROM calendar_event_links l
        JOIN calendar_events e ON e.id = l.event_id
        LEFT JOIN calendar_event_reminders r ON r.event_id = e.id
        WHERE l.linked_type = ?
          AND l.linked_id = ?
          AND e.company_id = ?
          AND e.start_datetime >= NOW()
        GROUP BY e.id, e.title, e.start_datetime
        ORDER BY e.start_datetime ASC
        LIMIT 10
    ");
    $stmt->execute([$linkedType, $linkedId, $companyId]);

    $events = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'start_datetime' => $row['start_datetime'],
            'channels' => $row['channels'] ? explode(',', $row['channels']) : [],
        ];
    }

    echo json_encode(['ok' => true, 'events' => $events]);

} catch (Throwable $e) {
    error_log('CRM followups_list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}
