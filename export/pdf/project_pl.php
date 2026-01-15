<?php
// PDF Project Profit & Loss Report
//
// Produces a simple PDF summarising income, expenses and net profit for each
// project. Income and expenses are derived from invoice and AP bill lines
// associated with the project's boards. Only active projects are listed.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

// Fetch projects
$stmt = $DB->prepare("SELECT id, name FROM projects WHERE company_id = ? AND status NOT IN ('archived','cancelled')");
$stmt->execute([$companyId]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare statements
$stmtBoards = $DB->prepare("SELECT board_id FROM project_boards WHERE project_id = ?");
$stmtIncome = $DB->prepare("SELECT COALESCE(SUM(il.line_total),0) FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id WHERE i.company_id = ? AND il.project_board_id = ?");
$stmtExpense = $DB->prepare("SELECT COALESCE(SUM(bl.line_total),0) FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.ap_bill_id WHERE b.company_id = ? AND bl.project_board_id = ?");

$lines = [];
$lines[] = 'Project Profit & Loss Summary';
$lines[] = 'As of ' . (new DateTimeImmutable('today'))->format('Y-m-d');
$lines[] = '';
$lines[] = sprintf("%-40s %15s %15s %15s", 'Project', 'Income', 'Expenses', 'Net Profit');

$grandIncome  = 0.0;
$grandExpense = 0.0;

foreach ($projects as $proj) {
    $projId   = (int)$proj['id'];
    $projName = $proj['name'];
    $stmtBoards->execute([$projId]);
    $boards = $stmtBoards->fetchAll(PDO::FETCH_COLUMN, 0);
    $income  = 0.0;
    $expense = 0.0;
    if ($boards) {
        foreach ($boards as $boardId) {
            $stmtIncome->execute([$companyId, $boardId]);
            $income += (float)$stmtIncome->fetchColumn();
            $stmtExpense->execute([$companyId, $boardId]);
            $expense += (float)$stmtExpense->fetchColumn();
        }
    }
    $net = $income - $expense;
    $grandIncome  += $income;
    $grandExpense += $expense;
    $lines[] = sprintf(
        "%-40s %15s %15s %15s",
        mb_strimwidth($projName, 0, 40, '…'),
        number_format($income, 2, '.', ''),
        number_format($expense, 2, '.', ''),
        number_format($net, 2, '.', '')
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

function generate_simple_pdf_content(array $lines): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    // Catalog
    $offsets[] = strlen($pdf);
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    // Pages
    $offsets[] = strlen($pdf);
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    // Page
    $offsets[] = strlen($pdf);
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>\nendobj\n";
    // Font
    $offsets[] = strlen($pdf);
    $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>\nendobj\n";
    // Content stream
    $yPos = 812;
    $content = "BT\n/F1 12 Tf\n";
    foreach ($lines as $line) {
        $content .= sprintf("36 %.2f Td (%s) Tj\n", $yPos, pdf_escape($line));
        $yPos -= 14;
    }
    $content .= "ET";
    $offsets[] = strlen($pdf);
    $pdf .= "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n";
    $pdf .= $content . "\nendstream\nendobj\n";
    // Xref
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}

$pdfContent = generate_simple_pdf_content($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="project_pl.pdf"');
echo $pdfContent;
exit;