<?php
// CSV Project Profit & Loss Report
//
// Generates a CSV summarising income, expenses and net profit per project
// (ZAR, excl. VAT). Income comes from the project's invoices (less credit
// notes); expenses from posted expense lines tagged with the project or its
// boards (AP bills, receipts, journals) — see export/lib/project_pl.php. Only
// active projects are included; ?project_id=N limits it to one project. This
// report provides management with a quick view of project profitability.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']); // gate this finance export
require_once __DIR__ . '/../lib/project_pl.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

$rows = project_pl_rows($DB, (int)$companyId, (int)($_GET['project_id'] ?? 0));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="project_pl.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Project', 'Income', 'Expenses', 'Net Profit']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['name'],
        number_format($row['income'], 2, '.', ''),
        number_format($row['expenses'], 2, '.', ''),
        number_format($row['net'], 2, '.', '')
    ]);
}
fclose($out);
exit;