<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_cashflow_indirect.php
// Generate a cash flow statement using the indirect method for a given date range.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/../../finances/lib/AccountsMap.php';
require_once __DIR__ . '/report_helpers.php';

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

// Validate dates strictly
$startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
$endDateObj   = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDate) {
    echo json_encode(['ok' => false, 'error' => 'Invalid start_date']);
    exit;
}
if (!$endDateObj || $endDateObj->format('Y-m-d') !== $endDate) {
    echo json_encode(['ok' => false, 'error' => 'Invalid end_date']);
    exit;
}
if ($endDateObj < $startDateObj) {
    echo json_encode(['ok' => false, 'error' => 'End date must be on or after start date']);
    exit;
}

try {
    // Resolve important account codes/IDs
    $accountsMap = new AccountsMap($DB, $companyId);
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

    // Helper to calculate balances for an account (asset) as debit-credit and for liability/equity as credit-debit.
    // Year-end closing journals (module = 'year_end') are excluded: they have no cash
    // effect and only shuffle P&L balances into retained earnings. Since net income is
    // computed excluding them, including their retained-earnings movement in the
    // financing section would double-count the closed year's profit. The exclusion is a
    // no-op for AR/AP/inventory/bank/asset accounts, which closing journals never touch.
    function calculateBalances(PDO $db, $companyId, $accountCodes, $accountIds, $startDate, $endDate, $isAsset) {
        if (!$accountCodes) return [0.0, 0.0];
        $placeholders = implode(',', array_fill(0, count($accountCodes), '?'));
        $expr = $isAsset ? 'jl.debit - jl.credit' : 'jl.credit - jl.debit';
        $sql = "SELECT
            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN ($expr) ELSE 0 END), 0) AS end_bal,
            COALESCE(SUM(CASE WHEN je.entry_date < ? THEN ($expr) ELSE 0 END), 0) AS start_bal
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_id = je.id
        WHERE jl.account_code IN ($placeholders) AND je.company_id = ? AND je.status = 'posted'
          AND (je.module IS NULL OR je.module <> 'year_end')";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$endDate, $startDate], $accountCodes, [$companyId]));
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return [floatval($res['start_bal']), floatval($res['end_bal'])];
    }

    // Compute Net Income for period using P&L logic (revenues - expenses)
    $sqlNI = "SELECT
            COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' THEN (jl.credit - jl.debit)
                               WHEN ga.account_type = 'expense' THEN -(jl.debit - jl.credit)
                               ELSE 0 END), 0) AS net
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_id = je.id
        JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
        WHERE je.company_id = ? AND je.status = 'posted'
          AND (je.module IS NULL OR je.module <> 'year_end')
          AND je.entry_date BETWEEN ? AND ?";
    $stmt = $DB->prepare($sqlNI);
    $stmt->execute([$companyId, $startDate, $endDate]);
    $netIncome = floatval($stmt->fetchColumn());

    // Depreciation expense (non-cash) for period:
    // Prefer accounts tagged with account_subtype='depreciation'; fall back to
    // name-based detection for legacy chart-of-accounts without subtypes.
    $hasSubtype = columnExists($DB, 'gl_accounts', 'account_subtype');
    if ($hasSubtype) {
        $stmt = $DB->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
            FROM journal_lines jl
            JOIN journal_entries je ON jl.journal_id = je.id
            JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
            WHERE je.company_id = ? AND je.status = 'posted'
              AND (je.module IS NULL OR je.module <> 'year_end')
              AND je.entry_date BETWEEN ? AND ?
              AND ga.account_type = 'expense'
              AND (ga.account_subtype = 'depreciation' OR ga.account_name LIKE '%Depreciation%')");
    } else {
        $stmt = $DB->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
            FROM journal_lines jl
            JOIN journal_entries je ON jl.journal_id = je.id
            JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
            WHERE je.company_id = ? AND je.status = 'posted'
              AND (je.module IS NULL OR je.module <> 'year_end')
              AND je.entry_date BETWEEN ? AND ?
              AND ga.account_type = 'expense'
              AND ga.account_name LIKE '%Depreciation%'");
    }
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

    // Investing: changes in long-term asset accounts (excluding bank, AR and inventory).
    // Also exclude accumulated-depreciation contra-asset accounts: the depreciation
    // expense is already added back in operating activities, so including the
    // accumulated-depreciation movement here would double-count it.
    if ($hasSubtype) {
        $sqlAssets = "SELECT account_id, account_code FROM gl_accounts
            WHERE company_id = ? AND account_type = 'asset'
            AND COALESCE(account_subtype, '') <> 'accumulated_depreciation'
            AND account_name NOT LIKE '%Accum%Dep%'";
    } else {
        $sqlAssets = "SELECT account_id, account_code FROM gl_accounts
            WHERE company_id = ? AND account_type = 'asset'
            AND account_name NOT LIKE '%Accum%Dep%'";
    }
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

    // Financing = LONG-TERM liabilities (loans, non-current) and equity only.
    // Current-liability movements — VAT control, PAYE/UIF/SDL, provisional /
    // dividends tax, accruals and other current liabilities — are OPERATING
    // working-capital changes, not financing. Lumping every non-AP liability into
    // financing systematically mis-stated operating cash flow every period.
    $OPERATING_LIAB_SUBTYPES = ['current_liability', 'vat_control', 'paye_liability',
        'uif_liability', 'sdl_liability', 'dividends_tax_payable', 'provisional_tax',
        'accruals', 'accounts_payable'];
    $sqlLE = "SELECT account_id, account_code, account_type, account_subtype FROM gl_accounts WHERE company_id = ? AND account_type IN ('liability','equity')";
    $stmt = $DB->prepare($sqlLE);
    $stmt->execute([$companyId]);
    $rowsLE = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $financingChange = 0.0;
    $operatingLiabChange = 0.0;
    foreach ($rowsLE as $row) {
        $aid   = intval($row['account_id']);
        $acode = $row['account_code'];
        $atype = $row['account_type'];
        $asub  = (string)($row['account_subtype'] ?? '');
        // Skip AP — already counted in changeAP (operating) above.
        if ($aid == $apId) continue;
        [$start, $end] = calculateBalances($DB, $companyId, [$acode], [$aid], $startDate, $endDate, false);
        // A positive change (liability/equity increase) is a cash inflow.
        $change = $end - $start;
        if ($atype === 'liability' && in_array($asub, $OPERATING_LIAB_SUBTYPES, true)) {
            $operatingLiabChange += $change; // operating working capital
        } else {
            $financingChange += $change;     // loans / non-current / equity
        }
    }
    // Fold current-liability movements into operating activities.
    $operating += $operatingLiabChange;

    // Net cash flow
    $netCash = $operating + $investingChange + $financingChange;

    $meta = getReportMeta($DB, $companyId, $userId);

    // Convert to cents for output
    $result = [
        'report_meta'       => $meta,
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
    echo json_encode(['ok' => false, 'error' => 'Failed to generate cash flow report']);
}