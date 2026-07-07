<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_balance_sheet.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/report_helpers.php';

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$date = $_GET['date'] ?? date('Y-m-d');
// Validate date format
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}

// Current/Non-Current classification using CoaSchema subtypes
$currentAssetSubtypes = ['bank', 'cash', 'accounts_receivable', 'inventory', 'prepayment', 'current_asset'];
$nonCurrentAssetSubtypes = ['fixed_asset', 'accumulated_depreciation', 'intangible_asset', 'investment', 'non_current_asset'];
$currentLiabilitySubtypes = ['accounts_payable', 'vat_control', 'paye_liability', 'uif_liability', 'sdl_liability', 'income_tax_payable', 'provisional_tax', 'dividends_tax_payable', 'current_liability'];
$nonCurrentLiabilitySubtypes = ['loan', 'non_current_liability'];

try {
    $meta = getReportMeta($DB, $companyId, $userId);
    $hasSubtype = columnExists($DB, 'gl_accounts', 'account_subtype');

    // Build query — with or without account_subtype
    if ($hasSubtype) {
        $sql = "
            SELECT a.account_id, a.account_code, a.account_name, a.account_type,
                COALESCE(a.account_subtype, '') AS account_subtype,
                COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0) AS balance_debit,
                COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit - jl.debit ELSE 0 END), 0) AS balance_credit
            FROM gl_accounts a
            LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
            LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
            WHERE a.company_id = ?
            AND a.account_type IN ('asset', 'liability', 'equity')
            AND a.is_active = 1
            GROUP BY a.account_id, a.account_code, a.account_name, a.account_type, a.account_subtype
            ORDER BY a.account_code ASC
        ";
    } else {
        $sql = "
            SELECT a.account_id, a.account_code, a.account_name, a.account_type,
                '' AS account_subtype,
                COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0) AS balance_debit,
                COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit - jl.debit ELSE 0 END), 0) AS balance_credit
            FROM gl_accounts a
            LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
            LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
            WHERE a.company_id = ?
            AND a.account_type IN ('asset', 'liability', 'equity')
            AND a.is_active = 1
            GROUP BY a.account_id, a.account_code, a.account_name, a.account_type
            ORDER BY a.account_code ASC
        ";
    }

    $stmt = $DB->prepare($sql);
    $stmt->execute([$date, $date, $companyId, $companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Classify accounts
    $currentAssets = [];
    $nonCurrentAssets = [];
    $currentLiabilities = [];
    $nonCurrentLiabilities = [];
    $equity = [];

    foreach ($rows as $row) {
        $type    = $row['account_type'];
        $subtype = $row['account_subtype'] ?? '';

        if ($type === 'asset') {
            $balanceCents = (int) round(floatval($row['balance_debit']) * 100);
        } else {
            $balanceCents = (int) round(floatval($row['balance_credit']) * 100);
        }

        if ($balanceCents == 0) continue;

        $item = [
            'account_id'    => $row['account_id'],
            'account_code'  => $row['account_code'],
            'account_name'  => $row['account_name'],
            'balance_cents' => $balanceCents,
        ];

        if ($type === 'asset') {
            if (in_array($subtype, $nonCurrentAssetSubtypes)) {
                $nonCurrentAssets[] = $item;
            } else {
                $currentAssets[] = $item;
            }
        } elseif ($type === 'liability') {
            if (in_array($subtype, $nonCurrentLiabilitySubtypes)) {
                $nonCurrentLiabilities[] = $item;
            } else {
                $currentLiabilities[] = $item;
            }
        } elseif ($type === 'equity') {
            $equity[] = $item;
        }
    }

    // Net income that has NOT been closed to an equity account, from inception.
    // The balance sheet only balances if this whole amount appears in equity:
    // the double-entry identity gives Assets = Liabilities + Equity +
    // (Revenue − Expense) over all time. Years already closed via a year-end
    // journal net to zero here (that journal reversed their P&L into the
    // retained-earnings account, which is already in Equity above), so this is
    // exactly the still-open P&L. We only SPLIT it for presentation below — the
    // total is unchanged, so the sheet balances identically.
    $stmt = $DB->prepare("SELECT
            COALESCE(SUM(CASE WHEN a.account_type = 'revenue' AND je.entry_date <= ? THEN jl.credit - jl.debit ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN a.account_type = 'expense' AND je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END), 0) AS expenses
        FROM gl_accounts a
        LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
        LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
        WHERE a.company_id = ? AND a.account_type IN ('revenue','expense')");
    $stmt->execute([$date, $date, $companyId, $companyId]);
    $plData = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalPlCents = (int) round((floatval($plData['revenue']) - floatval($plData['expenses'])) * 100);

    // Current-year portion: same window (fiscal-year-to-date) and year_end
    // exclusion as report_pl.php, so the "Current Year Earnings" line equals the
    // Income Statement's net profit for the period. Whatever remains is P&L
    // brought forward from prior periods that were never closed to retained
    // earnings — surfaced as its own line so CYE ties to the Income Statement.
    $fiscalStart = getFiscalYearStart($DB, $companyId, $date);
    $stmt = $DB->prepare("SELECT
            COALESCE(SUM(CASE WHEN a.account_type = 'revenue' AND je.entry_date BETWEEN ? AND ? AND (je.module IS NULL OR je.module <> 'year_end') THEN jl.credit - jl.debit ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN a.account_type = 'expense' AND je.entry_date BETWEEN ? AND ? AND (je.module IS NULL OR je.module <> 'year_end') THEN jl.debit - jl.credit ELSE 0 END), 0) AS expenses
        FROM gl_accounts a
        LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
        LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
        WHERE a.company_id = ? AND a.account_type IN ('revenue','expense')");
    $stmt->execute([$fiscalStart, $date, $fiscalStart, $date, $companyId, $companyId]);
    $cyData = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentYearCents   = (int) round((floatval($cyData['revenue']) - floatval($cyData['expenses'])) * 100);
    $priorRetainedCents = $totalPlCents - $currentYearCents;

    $accountsMap = new AccountsMap($DB, $companyId);
    if ($priorRetainedCents != 0) {
        $reCode = $accountsMap->get('finance_retained_earnings_account_id', '3200');
        $equity[] = [
            'account_id'    => null,
            'account_code'  => $reCode,
            'account_name'  => 'Retained Earnings (prior periods, unclosed)',
            'balance_cents' => $priorRetainedCents
        ];
    }
    if ($currentYearCents != 0) {
        $cyeCode = $accountsMap->get('finance_current_year_earnings_account_id', '3300');
        $equity[] = [
            'account_id'    => null,
            'account_code'  => $cyeCode,
            'account_name'  => 'Current Year Earnings',
            'balance_cents' => $currentYearCents
        ];
    }

    // Calculate totals
    $totalCurrentAssets         = array_sum(array_column($currentAssets, 'balance_cents'));
    $totalNonCurrentAssets      = array_sum(array_column($nonCurrentAssets, 'balance_cents'));
    $totalAssets                = $totalCurrentAssets + $totalNonCurrentAssets;
    $totalCurrentLiabilities    = array_sum(array_column($currentLiabilities, 'balance_cents'));
    $totalNonCurrentLiabilities = array_sum(array_column($nonCurrentLiabilities, 'balance_cents'));
    $totalLiabilities           = $totalCurrentLiabilities + $totalNonCurrentLiabilities;
    $totalEquity                = array_sum(array_column($equity, 'balance_cents'));

    echo json_encode([
        'ok' => true,
        'data' => [
            'report_meta'  => $meta,
            'date'         => $date,
            // IFRS/SARS classified structure
            'current_assets'                      => $currentAssets,
            'total_current_assets_cents'           => (int)$totalCurrentAssets,
            'non_current_assets'                  => $nonCurrentAssets,
            'total_non_current_assets_cents'       => (int)$totalNonCurrentAssets,
            'current_liabilities'                 => $currentLiabilities,
            'total_current_liabilities_cents'      => (int)$totalCurrentLiabilities,
            'non_current_liabilities'             => $nonCurrentLiabilities,
            'total_non_current_liabilities_cents'  => (int)$totalNonCurrentLiabilities,
            'equity'                              => $equity,
            'total_equity_cents'                  => (int)$totalEquity,
            // Summary totals
            'total_assets_cents'                  => (int)$totalAssets,
            'total_liabilities_cents'             => (int)$totalLiabilities,
            'total_liabilities_equity_cents'      => (int)($totalLiabilities + $totalEquity),
            // Legacy fields
            'company_name' => $meta['company_name'],
            'assets'       => array_merge($currentAssets, $nonCurrentAssets),
            'liabilities'  => array_merge($currentLiabilities, $nonCurrentLiabilities),
        ]
    ]);

} catch (Exception $e) {
    error_log("Balance sheet error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate balance sheet'
    ]);
}
