<?php
// GET /qi/ajax/get_calendar_link.php?type=invoice|quote&id=12
// Where a quote's expiry or an invoice's due date sits on the calendar.
// → { ok: true, url: "/calendar/?view=month&date=…&focus=invoice:12", date: "2026-10-21" }
// (This endpoint used to run without a session or company check.)

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../calendar/lib/autoload.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$companyId = (int)$_SESSION['company_id'];
$type = strtolower(trim((string)($_GET['type'] ?? '')));
$id = (int)($_GET['id'] ?? 0);
if (!in_array($type, ['quote', 'invoice'], true) || $id < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid document']);
    exit;
}

try {
    $sql = $type === 'invoice'
        ? "SELECT due_date FROM invoices WHERE id = ? AND company_id = ?"
        : "SELECT expiry_date FROM quotes WHERE id = ? AND company_id = ?";
    $st = $DB->prepare($sql);
    $st->execute([$id, $companyId]);
    $date = $st->fetchColumn();
    if ($date === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    $date = $date ? substr((string)$date, 0, 10) : null;
    echo json_encode(['ok' => true, 'date' => $date,
                      'url' => $date ? CalendarLink::url($date, $type . ':' . $id) : null]);
} catch (Throwable $e) {
    error_log('[qi/get_calendar_link] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
