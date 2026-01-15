<?php
// CSV Project Profit & Loss Report
//
// Generates a CSV summarising income, expenses and net profit per project. Income
// is derived from invoice lines linked to the project's boards; expenses come
// from AP bill lines linked to the same boards. Only active projects are
// included. This report provides management with a quick view of project
// profitability.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="project_pl.csv"');

// Fetch active projects
$stmt = $DB->prepare("SELECT id, name FROM projects WHERE company_id = ? AND status NOT IN ('archived','cancelled')");
$stmt->execute([$companyId]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out = fopen('php://output', 'w');
fputcsv($out, ['Project', 'Income', 'Expenses', 'Net Profit']);

// Prepare statements outside loops for performance
$stmtBoards = $DB->prepare("SELECT board_id FROM project_boards WHERE project_id = ?");
$stmtIncome = $DB->prepare("SELECT COALESCE(SUM(il.line_total),0) FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id WHERE i.company_id = ? AND il.project_board_id = ?");
$stmtExpense = $DB->prepare("SELECT COALESCE(SUM(bl.line_total),0) FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.ap_bill_id WHERE b.company_id = ? AND bl.project_board_id = ?");

foreach ($projects as $proj) {
    $projId = (int)$proj['id'];
    $projName = $proj['name'];
    // Get board ids
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
    fputcsv($out, [
        $projName,
        number_format($income, 2, '.', ''),
        number_format($expense, 2, '.', ''),
        number_format($net, 2, '.', '')
    ]);
}
fclose($out);
exit;