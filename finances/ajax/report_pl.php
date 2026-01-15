<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_pl.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$date = $_GET['date'] ?? date('Y-m-d');

try {
    // Get company name
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn();

    // Get revenue accounts (credit balance)
    $stmt = $DB->prepare("
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit - jl.debit ELSE 0 END), 0) AS balance
        FROM gl_accounts a
        LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
        LEFT JOIN journal_entries je ON jl.journal_id = je.id
        WHERE a.company_id = ? 
        AND a.account_type = 'revenue'
        AND a.is_active = 1
        GROUP BY a.account_id, a.account_code, a.account_name
        ORDER BY a.account_code ASC
    ");
    $stmt->execute([$date, $companyId]);
    $revenueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $revenue = [];
    $totalRevenue = 0;
    foreach ($revenueRows as $row) {
        $balanceCents = (int) round(floatval($row['balance']) * 100);
        if ($balanceCents != 0) {
            $revenue[] = [
                'account_id'   => $row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'balance_cents' => $balanceCents
            ];
            $totalRevenue += $balanceCents;
        }
    }

    // Get expense accounts (debit balance)
    $stmt = $DB->prepare("
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0) AS balance
        FROM gl_accounts a
        LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
        LEFT JOIN journal_entries je ON jl.journal_id = je.id
        WHERE a.company_id = ? 
        AND a.account_type = 'expense'
        AND a.is_active = 1
        GROUP BY a.account_id, a.account_code, a.account_name
        ORDER BY a.account_code ASC
    ");
    $stmt->execute([$date, $companyId]);
    $expenseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $expenses = [];
    $totalExpenses = 0;
    foreach ($expenseRows as $row) {
        $balanceCents = (int) round(floatval($row['balance']) * 100);
        if ($balanceCents != 0) {
            $expenses[] = [
                'account_id'   => $row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'balance_cents' => $balanceCents
            ];
            $totalExpenses += $balanceCents;
        }
    }

    $netIncome = $totalRevenue - $totalExpenses;

    echo json_encode([
        'ok' => true,
        'data' => [
            'company_name' => $companyName,
            'date' => $date,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue_cents' => (int)$totalRevenue,
            'total_expenses_cents' => (int)$totalExpenses,
            'net_income_cents' => (int)$netIncome
        ]
    ]);

} catch (Exception $e) {
    error_log("P&L error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate P&L'
    ]);
}