<?php
// PDF Project Profit & Loss Report
//
// Produces a simple PDF summarising income, expenses and net profit for each
// project. Income and expenses are derived from invoice and AP bill lines
// associated with the project's boards. Only active projects are listed.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']); // gate this finance export
require_once __DIR__ . '/../../includes/pdf/text_report_pdf.php';

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

$pdfContent = fw_text_report_pdf($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="project_pl.pdf"');
echo $pdfContent;
exit;