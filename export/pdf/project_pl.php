<?php
// PDF Project Profit & Loss Report
//
// Produces a simple PDF summarising income, expenses and net profit for each
// project (ZAR, excl. VAT). Income comes from the project's invoices (less
// credit notes); expenses from posted expense lines tagged with the project or
// its boards — see export/lib/project_pl.php. Only active projects are listed;
// ?project_id=N limits it to one project.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']); // gate this finance export
require_once __DIR__ . '/../lib/project_pl.php';
require_once __DIR__ . '/../../includes/pdf/text_report_pdf.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

$rows = project_pl_rows($DB, (int)$companyId, (int)($_GET['project_id'] ?? 0));

$lines = [];
$lines[] = 'Project Profit & Loss Summary';
$lines[] = 'As of ' . (new DateTimeImmutable('today'))->format('Y-m-d') . ' (ZAR, excl. VAT)';
$lines[] = '';
$lines[] = sprintf("%-40s %15s %15s %15s", 'Project', 'Income', 'Expenses', 'Net Profit');

$grandIncome  = 0.0;
$grandExpense = 0.0;

foreach ($rows as $row) {
    $grandIncome  += $row['income'];
    $grandExpense += $row['expenses'];
    $lines[] = sprintf(
        "%-40s %15s %15s %15s",
        pdf_text(mb_strimwidth($row['name'], 0, 40, '…')),
        number_format($row['income'], 2, '.', ''),
        number_format($row['expenses'], 2, '.', ''),
        number_format($row['net'], 2, '.', '')
    );
}

$lines[] = str_repeat('-', 90);
$lines[] = sprintf(
    "%-40s %15s %15s %15s",
    'TOTAL',
    number_format($grandIncome, 2, '.', ''),
    number_format($grandExpense, 2, '.', ''),
    number_format($grandIncome - $grandExpense, 2, '.', '')
);

// The shared PDF font uses WinAnsiEncoding: convert before padding so columns line up.
function pdf_text(string $str): string
{
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $str);
    return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $str) : $out;
}

$pdfContent = fw_text_report_pdf($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="project_pl.pdf"');
echo $pdfContent;
exit;