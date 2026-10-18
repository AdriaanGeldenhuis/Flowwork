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

// PDF helpers
function pdf_escape($str)
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
}

// The font uses WinAnsiEncoding: convert before padding so columns line up.
function pdf_text(string $str): string
{
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $str);
    return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $str) : $out;
}

function generate_simple_pdf_content(array $lines): string
{
    // 10pt Courier keeps the 88-character rows inside an A4 page; 54 rows per page.
    $pages = array_chunk($lines, 54) ?: [[]];
    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $kids = [];
    foreach (array_keys($pages) as $i) {
        $kids[] = (4 + 2 * $i) . ' 0 R';
    }
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($pages) . " >>";
    $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>";
    foreach ($pages as $i => $pageLines) {
        $yPos = 806;
        $content = "BT\n/F1 10 Tf\n";
        foreach ($pageLines as $line) {
            // Tm places each row absolutely (Td is relative to the previous row).
            $content .= sprintf("1 0 0 1 36 %.2f Tm (%s) Tj\n", $yPos, pdf_escape($line));
            $yPos -= 14;
        }
        $content .= "ET";
        $objects[4 + 2 * $i] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents " . (5 + 2 * $i) . " 0 R /Resources << /Font << /F1 3 0 R >> >> >>";
        $objects[5 + 2 * $i] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    // Xref
    $xrefOffset = strlen($pdf);
    $size = count($objects) + 1;
    $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}

$pdfContent = generate_simple_pdf_content($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="project_pl.pdf"');
echo $pdfContent;
exit;