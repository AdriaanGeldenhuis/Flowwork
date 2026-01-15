<?php
// /calendar/ajax/integration_invoice_due.php
// Sync invoice due dates to calendar
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Check user setting for invoice due integration
    $stmt = $DB->prepare("SELECT enable_invoice_due FROM calendar_settings WHERE company_id = ? AND user_id = ?");
    $stmt->execute([$companyId, $userId]);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($setting && (int)$setting['enable_invoice_due'] === 0) {
        echo json_encode(['ok' => true, 'synced' => 0, 'disabled' => true]);
        return;
    }
    // Get finance calendar
    $stmt = $DB->prepare("
        SELECT id FROM calendars 
        WHERE company_id = ? AND calendar_type = 'team' AND name = 'Finance'
    ");
    $stmt->execute([$companyId]);
    $cal = $stmt->fetch();

    if (!$cal) {
        $stmt = $DB->prepare("
            INSERT INTO calendars (company_id, calendar_type, name, color, owner_id, ics_token, created_at)
            VALUES (?, 'team', 'Finance', '#10b981', ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, bin2hex(random_bytes(32))]);
        $calendarId = $DB->lastInsertId();
    } else {
        $calendarId = $cal['id'];
    }

    // Fetch unpaid invoices
    $stmt = $DB->prepare("
        SELECT i.id, i.invoice_number, i.due_date, i.total, a.name as customer_name
        FROM invoices i
        JOIN crm_accounts a ON i.customer_id = a.id
        WHERE i.company_id = ? AND i.status IN ('sent', 'viewed', 'overdue')
        AND i.due_date >= CURDATE()
    ");
    $stmt->execute([$companyId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $synced = 0;

    foreach ($invoices as $inv) {
        // Check if exists
        $stmt = $DB->prepare("
            SELECT id FROM calendar_events e
            JOIN calendar_event_links l ON e.id = l.event_id
            WHERE l.linked_type = 'invoice' AND l.linked_id = ? AND e.company_id = ?
        ");
        $stmt->execute([$inv['id'], $companyId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            // Create
            $stmt = $DB->prepare("
                INSERT INTO calendar_events
                (company_id, calendar_id, title, description, start_datetime, end_datetime, all_day, color, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, '#10b981', ?, NOW())
            ");
            $stmt->execute([
                $companyId,
                $calendarId,
                '💰 Invoice Due: ' . $inv['invoice_number'],
                'Customer: ' . $inv['customer_name'] . "\nAmount: R" . number_format($inv['total'], 2),
                $inv['due_date'] . ' 00:00:00',
                $inv['due_date'] . ' 23:59:59',
                $userId
            ]);

            $eventId = $DB->lastInsertId();

            // Link
            $stmt = $DB->prepare("
                INSERT INTO calendar_event_links (event_id, linked_type, linked_id)
                VALUES (?, 'invoice', ?)
            ");
            $stmt->execute([$eventId, $inv['id']]);

            $synced++;
        }
    }

    echo json_encode(['ok' => true, 'synced' => $synced]);

} catch (Exception $e) {
    error_log("Invoice sync error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Sync failed']);
}