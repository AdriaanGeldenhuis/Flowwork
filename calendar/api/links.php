<?php
// GET /calendar/api/links.php?q=acme — records an event can be linked to
// (projects, boards, tasks, customers/suppliers, deals, invoices, quotes), filtered to
// what the user may open. GET ?type=crm_account&id=12 describes one record.
require_once __DIR__ . '/_bootstrap.php';

$links = new CalendarLinks($DB, $CAL);
if (isset($_GET['type'])) {
    $d = $links->describe((string)$_GET['type'], (int)($_GET['id'] ?? 0));
    if (!$d) cal_error('Record not found', 404);
    cal_json(['link' => $d]);
}
cal_json(['results' => $links->search((string)($_GET['q'] ?? ''))]);
