<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_balance_sheet.php
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

    // Helper to retrieve account balances by type
    $fetchBalances = function($type) use ($DB, $companyId, $date) {
        $stmt = $DB->prepare("SELECT a.account_id, a.account_code, a.account_name,
            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0) AS balance_debit,
            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit - jl.debit ELSE 0 END), 0) AS balance_credit
            FROM gl_accounts a
            LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
            LEFT JOIN journal_entries je ON jl.journal_id = je.id
            WHERE a.company_id = ?
            AND a.account_type = ?
            AND a.is_active = 1
            GROUP BY a.account_id, a.account_code, a.account_name
            ORDER BY a.account_code ASC");
        $stmt->execute([$date, $date, $companyId, $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    // Assets: normal debit balance
    $assetRows = $fetchBalances('asset');
    $assets = [];
    foreach ($assetRows as $row) {
        $balanceCents = (int) round(floatval($row['balance_debit']) * 100);
        if ($balanceCents != 0) {
            $assets[] = [
                'account_id'   => $row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'balance_cents' => $balanceCents
            ];
        }
    }

    // Liabilities: normal credit balance
    $liabilityRows = $fetchBalances('liability');
    $liabilities = [];
    foreach ($liabilityRows as $row) {
        $balanceCents = (int) round(floatval($row['balance_credit']) * 100);
        if ($balanceCents != 0) {
            $liabilities[] = [
                'account_id'   => $row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'balance_cents' => $balanceCents
            ];
        }
    }

    // Equity: normal credit balance
    $equityRows = $fetchBalances('equity');
    $equity = [];
    foreach ($equityRows as $row) {
        $balanceCents = (int) round(floatval($row['balance_credit']) * 100);
        if ($balanceCents != 0) {
            $equity[] = [
                'account_id'   => $row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'balance_cents' => $balanceCents
            ];
        }
    }

    // Calculate net income (revenue - expenses) using decimal fields
    $stmt = $DB->prepare("SELECT 
            COALESCE(SUM(CASE WHEN a.account_type = 'revenue' AND je.entry_date <= ? THEN jl.credit - jl.debit ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN a.account_type = 'expense' AND je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0) AS expenses
        FROM gl_accounts a
        LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
        LEFT JOIN journal_entries je ON jl.journal_id = je.id
        WHERE a.company_id = ? AND a.account_type IN ('revenue','expense')");
    $stmt->execute([$date, $date, $companyId]);
    $plData = $stmt->fetch(PDO::FETCH_ASSOC);
    $netIncomeCents = (int) round((floatval($plData['revenue']) - floatval($plData['expenses'])) * 100);

    if ($netIncomeCents != 0) {
        $equity[] = [
            'account_id'   => null,
            'account_code' => '3300',
            'account_name' => 'Current Year Earnings',
            'balance_cents' => $netIncomeCents
        ];
    }

    // Calculate totals
    $totalAssets      = array_sum(array_column($assets, 'balance_cents'));
    $totalLiabilities = array_sum(array_column($liabilities, 'balance_cents'));
    $totalEquity      = array_sum(array_column($equity, 'balance_cents'));

    echo json_encode([
        'ok' => true,
        'data' => [
            'company_name' => $companyName,
            'date' => $date,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets_cents' => (int)$totalAssets,
            'total_liabilities_cents' => (int)$totalLiabilities,
            'total_equity_cents' => (int)$totalEquity,
            'total_liabilities_equity_cents' => (int)($totalLiabilities + $totalEquity)
        ]
    ]);

} catch (Exception $e) {
    error_log("Balance sheet error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate balance sheet'
    ]);
}