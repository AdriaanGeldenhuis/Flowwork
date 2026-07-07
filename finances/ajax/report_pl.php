<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_pl.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/report_helpers.php';

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$endDate   = $_GET['date'] ?? date('Y-m-d');
$startDate = $_GET['start_date'] ?? null;

// Validate dates
$dtEnd = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$dtEnd || $dtEnd->format('Y-m-d') !== $endDate) {
    $endDate = date('Y-m-d');
}
if ($startDate) {
    $dtStart = DateTime::createFromFormat('Y-m-d', $startDate);
    if (!$dtStart || $dtStart->format('Y-m-d') !== $startDate) {
        $startDate = null;
    }
}

// Default start_date to fiscal year start if not provided
if (!$startDate) {
    $startDate = getFiscalYearStart($DB, $companyId, $endDate);
}

try {
    $meta = getReportMeta($DB, $companyId, $userId);
    $hasSubtype = columnExists($DB, 'gl_accounts', 'account_subtype');

    // Build query — with or without account_subtype
    if ($hasSubtype) {
        $sql = "
            SELECT
                a.account_id, a.account_code, a.account_name, a.account_type,
                COALESCE(a.account_subtype, '') AS account_subtype,
                COALESCE(SUM(CASE
                    WHEN a.account_type = 'revenue' AND je.entry_date BETWEEN ? AND ? THEN jl.credit - jl.debit
                    WHEN a.account_type = 'expense' AND je.entry_date BETWEEN ? AND ? THEN jl.debit - jl.credit
                    ELSE 0
                END), 0) AS balance
            FROM gl_accounts a
            LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
            LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
                AND (je.module IS NULL OR je.module <> 'year_end')
            WHERE a.company_id = ?
            AND a.account_type IN ('revenue', 'expense')
            AND a.is_active = 1
            GROUP BY a.account_id, a.account_code, a.account_name, a.account_type, a.account_subtype
            ORDER BY a.account_code ASC
        ";
        $params = [$startDate, $endDate, $startDate, $endDate, $companyId, $companyId];
    } else {
        $sql = "
            SELECT
                a.account_id, a.account_code, a.account_name, a.account_type,
                '' AS account_subtype,
                COALESCE(SUM(CASE
                    WHEN a.account_type = 'revenue' AND je.entry_date BETWEEN ? AND ? THEN jl.credit - jl.debit
                    WHEN a.account_type = 'expense' AND je.entry_date BETWEEN ? AND ? THEN jl.debit - jl.credit
                    ELSE 0
                END), 0) AS balance
            FROM gl_accounts a
            LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
            LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
                AND (je.module IS NULL OR je.module <> 'year_end')
            WHERE a.company_id = ?
            AND a.account_type IN ('revenue', 'expense')
            AND a.is_active = 1
            GROUP BY a.account_id, a.account_code, a.account_name, a.account_type
            ORDER BY a.account_code ASC
        ";
        $params = [$startDate, $endDate, $startDate, $endDate, $companyId, $companyId];
    }

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Classify accounts into SARS-compliant P&L sections
    $revenue = [];
    $costOfSales = [];
    $otherIncome = [];
    $operatingExpenses = [];
    $financeCosts = [];
    $taxExpense = [];

    $totalRevenue = 0;
    $totalCostOfSales = 0;
    $totalOtherIncome = 0;
    $totalOperatingExpenses = 0;
    $totalFinanceCosts = 0;
    $totalTaxExpense = 0;

    foreach ($rows as $row) {
        $balanceCents = (int) round(floatval($row['balance']) * 100);
        if ($balanceCents == 0) continue;

        $item = [
            'account_id'    => $row['account_id'],
            'account_code'  => $row['account_code'],
            'account_name'  => $row['account_name'],
            'balance_cents' => $balanceCents,
        ];

        $subtype = $row['account_subtype'] ?? '';
        $type    = $row['account_type'];

        if ($type === 'revenue') {
            if (in_array($subtype, ['other_income', 'interest_income', 'dividend_income', 'forex_gain'])) {
                $otherIncome[] = $item;
                $totalOtherIncome += $balanceCents;
            } else {
                $revenue[] = $item;
                $totalRevenue += $balanceCents;
            }
        } elseif ($type === 'expense') {
            if ($subtype === 'cost_of_sales') {
                $costOfSales[] = $item;
                $totalCostOfSales += $balanceCents;
            } elseif ($subtype === 'finance_cost') {
                $financeCosts[] = $item;
                $totalFinanceCosts += $balanceCents;
            } elseif ($subtype === 'tax_expense') {
                $taxExpense[] = $item;
                $totalTaxExpense += $balanceCents;
            } else {
                $operatingExpenses[] = $item;
                $totalOperatingExpenses += $balanceCents;
            }
        }
    }

    $grossProfit       = $totalRevenue - $totalCostOfSales;
    $operatingProfit   = $grossProfit - $totalOperatingExpenses;
    $profitBeforeTax   = $operatingProfit + $totalOtherIncome - $totalFinanceCosts;
    $netProfitAfterTax = $profitBeforeTax - $totalTaxExpense;

    echo json_encode([
        'ok' => true,
        'data' => [
            'report_meta'   => $meta,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            // SARS-compliant P&L sections
            'revenue'                           => $revenue,
            'total_revenue_cents'               => (int)$totalRevenue,
            'cost_of_sales'                     => $costOfSales,
            'total_cost_of_sales_cents'         => (int)$totalCostOfSales,
            'gross_profit_cents'                => (int)$grossProfit,
            'operating_expenses'                => $operatingExpenses,
            'total_operating_expenses_cents'     => (int)$totalOperatingExpenses,
            'operating_profit_cents'            => (int)$operatingProfit,
            'other_income'                      => $otherIncome,
            'total_other_income_cents'          => (int)$totalOtherIncome,
            'finance_costs'                     => $financeCosts,
            'total_finance_costs_cents'         => (int)$totalFinanceCosts,
            'profit_before_tax_cents'           => (int)$profitBeforeTax,
            'tax_expense'                       => $taxExpense,
            'total_tax_expense_cents'           => (int)$totalTaxExpense,
            'net_profit_after_tax_cents'        => (int)$netProfitAfterTax,
            // Legacy fields for backwards compatibility
            'company_name'         => $meta['company_name'],
            'date'                 => $endDate,
            'expenses'             => array_merge($costOfSales, $operatingExpenses, $financeCosts, $taxExpense),
            'total_expenses_cents' => (int)($totalCostOfSales + $totalOperatingExpenses + $totalFinanceCosts + $totalTaxExpense),
            'net_income_cents'     => (int)$netProfitAfterTax,
        ]
    ]);

} catch (Exception $e) {
    error_log("P&L error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate P&L'
    ]);
}
