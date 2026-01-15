<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_cashflow_indirect.php
// Generate a cash flow statement using the indirect method for a given date range.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/lib/AccountsMap.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

// Validate dates
try {
    $startDateObj = new DateTime($startDate);
    $endDateObj   = new DateTime($endDate);
    if ($endDateObj < $startDateObj) {
        throw new Exception('End date must be on or after start date');
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Invalid dates']);
    exit;
}

try {
    // Resolve important account codes/IDs
    $accountsMap = new \Finances\AccountsMap($DB, $companyId);
    $arId  = $accountsMap->getAccountId('finance_ar_account_id');
    $apId  = $accountsMap->getAccountId('finance_ap_account_id');
    $invId = $accountsMap->getAccountId('finance_inventory_account_id');
    $arCode  = $accountsMap->getAccountCodeById($arId);
    $apCode  = $accountsMap->getAccountCodeById($apId);
    $invCode = $accountsMap->getAccountCodeById($invId);

    // Resolve bank account codes
    $stmt = $DB->prepare("SELECT gl_account_id FROM gl_bank_accounts WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $bankAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $bankCodes = [];
    if ($bankAccountIds) {
        $placeholders = implode(',', array_fill(0, count($bankAccountIds), '?'));
        $stmt = $DB->prepare("SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_id IN ($placeholders)");
        $stmt->execute(array_merge([$companyId], $bankAccountIds));
        $bankCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Helper to calculate balances for an account (asset) as debit-credit and for liability/equity as credit-debit
    function calculateBalances(PDO $db, $companyId, $accountCodes, $accountIds, $startDate, $endDate, $isAsset) {
        if (!$accountCodes && !$accountIds) return [0.0, 0.0];
        $conditions = [];
        $params = [];
        if ($accountCodes) {
            $placeholders = implode(',', array_fill(0, count($accountCodes), '?'));
            $conditions[] = "jl.account_code IN ($placeholders)";
            $params = array_merge($params, $accountCodes);
        }
        if ($accountIds) {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $conditions[] = "jl.account_id IN ($placeholders)";
            $params = array_merge($params, $accountIds);
        }
        $condSql = implode(' OR ', $conditions);
        $sql = "SELECT\n            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN (" . ($isAsset ? 'jl.debit - jl.credit' : 'jl.credit - jl.debit') . ") ELSE 0 END), 0) AS end_bal,\n            COALESCE(SUM(CASE WHEN je.entry_date < ? THEN (" . ($isAsset ? 'jl.debit - jl.credit' : 'jl.credit - jl.debit') . ") ELSE 0 END), 0) AS start_bal\n        FROM journal_lines jl\n        JOIN journal_entries je ON jl.journal_id = je.id\n        WHERE ($condSql) AND je.company_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$endDate, $startDate], $params, [$companyId]));
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return [floatval($res['start_bal']), floatval($res['end_bal'])];
    }

    // Compute Net Income for period using P&L logic (revenues - expenses)
    $sqlNI = "SELECT\n            COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' THEN (jl.credit - jl.debit)\n                               WHEN ga.account_type = 'expense' THEN (jl.debit - jl.credit)\n                               ELSE 0 END), 0) AS net\n        FROM journal_lines jl\n        JOIN journal_entries je ON jl.journal_id = je.id\n        JOIN gl_accounts ga ON (ga.account_code = jl.account_code OR ga.account_id = jl.account_id) AND ga.company_id = je.company_id\n        WHERE je.company_id = ? AND je.entry_date BETWEEN ? AND ?";
    $stmt = $DB->prepare($sqlNI);
    $stmt->execute([$companyId, $startDate, $endDate]);
    $netIncome = floatval($stmt->fetchColumn());

    // Depreciation expense (non-cash) for period: sum of debit - credit for expense accounts with name like 'Depreciation'
    $stmt = $DB->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0)\n        FROM journal_lines jl\n        JOIN journal_entries je ON jl.journal_id = je.id\n        JOIN gl_accounts ga ON (ga.account_code = jl.account_code OR ga.account_id = jl.account_id)\n        WHERE je.company_id = ? AND je.entry_date BETWEEN ? AND ? AND ga.account_type = 'expense' AND ga.account_name LIKE '%Depreciation%'");
    $stmt->execute([$companyId, $startDate, $endDate]);
    $depreciation = floatval($stmt->fetchColumn());

    // Change in AR (asset account) -> increases reduce cash
    [$arStart, $arEnd] = calculateBalances($DB, $companyId, $arCode ? [$arCode] : [], $arId ? [$arId] : [], $startDate, $endDate, true);
    $changeAR = $arEnd - $arStart;

    // Change in Inventory
    [$invStart, $invEnd] = calculateBalances($DB, $companyId, $invCode ? [$invCode] : [], $invId ? [$invId] : [], $startDate, $endDate, true);
    $changeInv = $invEnd - $invStart;

    // Change in AP (liability) -> increases add cash
    [$apStart, $apEnd] = calculateBalances($DB, $companyId, $apCode ? [$apCode] : [], $apId ? [$apId] : [], $startDate, $endDate, false);
    $changeAP = $apEnd - $apStart;

    // Operating Cash Flow
    $operating = $netIncome + $depreciation - $changeAR - $changeInv + $changeAP;

    // Investing: changes in long-term asset accounts (excluding bank, AR and inventory)
    // Find asset accounts
    $sqlAssets = "SELECT account_id, account_code FROM gl_accounts WHERE company_id = ? AND account_type = 'asset'";
    $stmt = $DB->prepare($sqlAssets);
    $stmt->execute([$companyId]);
    $assetRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $invSet = $invId ? [$invId] : [];
    $arSet  = $arId ? [$arId] : [];
    $bankSet = [];
    // gather bank account ids
    foreach ($bankAccountIds as $bid) { $bankSet[] = $bid; }
    $investingChange = 0.0;
    foreach ($assetRows as $arow) {
        $aid = intval($arow['account_id']);
        $acode = $arow['account_code'];
        // skip AR, Inventory, bank accounts
        if (in_array($aid, $invSet) || in_array($aid, $arSet) || in_array($aid, $bankAccountIds)) continue;
        [$start, $end] = calculateBalances($DB, $companyId, [$acode], [$aid], $startDate, $endDate, true);
        $change = $end - $start;
        // Increase in asset reduces cash (outflow), decrease increases cash (inflow)
        $investingChange -= $change;
    }

    // Financing: changes in liabilities and equity (excluding AP)
    $sqlLE = "SELECT account_id, account_code, account_type FROM gl_accounts WHERE company_id = ? AND account_type IN ('liability','equity')";
    $stmt = $DB->prepare($sqlLE);
    $stmt->execute([$companyId]);
    $rowsLE = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $financingChange = 0.0;
    foreach ($rowsLE as $row) {
        $aid   = intval($row['account_id']);
        $acode = $row['account_code'];
        $atype = $row['account_type'];
        // Skip AP
        if ($aid == $apId) continue;
        [$start, $end] = calculateBalances($DB, $companyId, [$acode], [$aid], $startDate, $endDate, false);
        // For liability/equity, a positive change means inflow
        $change = $end - $start;
        $financingChange += $change;
    }

    // Net cash flow
    $netCash = $operating + $investingChange + $financingChange;

    // Convert to cents for output
    $result = [
        'start_date'        => $startDate,
        'end_date'          => $endDate,
        'net_income_cents'  => intval(round($netIncome * 100)),
        'depreciation_cents'=> intval(round($depreciation * 100)),
        'change_ar_cents'   => intval(round($changeAR * 100)),
        'change_inv_cents'  => intval(round($changeInv * 100)),
        'change_ap_cents'   => intval(round($changeAP * 100)),
        'operating_cents'   => intval(round($operating * 100)),
        'investing_cents'   => intval(round($investingChange * 100)),
        'financing_cents'   => intval(round($financingChange * 100)),
        'net_cash_cents'    => intval(round($netCash * 100)),
    ];
    echo json_encode(['ok' => true, 'data' => $result]);
} catch (Exception $e) {
    error_log('Cash flow report error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}