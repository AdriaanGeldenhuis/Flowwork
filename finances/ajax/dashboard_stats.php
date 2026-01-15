<?php
// /finances/ajax/dashboard_stats.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
// Include AccountsMap to resolve dynamic GL codes
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

// Ensure a company context exists
$companyId = $_SESSION['company_id'] ?? null;

if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Resolve account codes from company settings (with sensible defaults)
    $accountsMap = new AccountsMap($DB, (int)$companyId);
    $arCode       = $accountsMap->get('finance_ar_account_id', '1200');
    $apCode       = $accountsMap->get('finance_ap_account_id', '2110');
    $vatOutCode   = $accountsMap->get('finance_vat_output_account_id', '2120');
    $vatInCode    = $accountsMap->get('finance_vat_input_account_id', '2130');

    // 1. Cash & Bank: Sum current balances from active bank accounts
    $stmt = $DB->prepare("SELECT COALESCE(SUM(current_balance_cents),0) AS cash_cents FROM gl_bank_accounts WHERE company_id = ? AND is_active = 1");
    $stmt->execute([$companyId]);
    $cash_cents = (int)$stmt->fetchColumn();

    // 2. Accounts Receivable: Sum outstanding balances on customer invoices (status not paid/cancelled)
    $stmt = $DB->prepare("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE company_id = ? AND status NOT IN ('paid','cancelled')");
    $stmt->execute([$companyId]);
    $ar_open = (float)$stmt->fetchColumn();
    $ar_cents = (int)round($ar_open * 100);

    // 3. Accounts Payable: Sum outstanding balances on supplier bills
    $stmt = $DB->prepare("
        SELECT COALESCE(SUM(GREATEST(0, b.total - IFNULL(paid.total_paid,0) - IFNULL(vc.total_credit,0))), 0) AS ap_open
        FROM ap_bills b
        LEFT JOIN (
            SELECT pa.bill_id, SUM(pa.amount) AS total_paid
            FROM ap_payment_allocations pa
            JOIN ap_payments p ON p.id = pa.ap_payment_id AND p.company_id = ?
            GROUP BY pa.bill_id
        ) paid ON paid.bill_id = b.id
        LEFT JOIN (
            SELECT vca.bill_id, SUM(vca.amount) AS total_credit
            FROM vendor_credit_allocations vca
            JOIN vendor_credits vc ON vc.id = vca.credit_id AND vc.company_id = ?
            GROUP BY vca.bill_id
        ) vc ON vc.bill_id = b.id
        WHERE b.company_id = ? AND b.status NOT IN ('paid','cancelled')
    ");
    $stmt->execute([$companyId, $companyId, $companyId]);
    $ap_open = (float)$stmt->fetchColumn();
    $ap_cents = (int)round($ap_open * 100);

    // 4. Net Profit (This Month) – profit & loss for the current month (MTD)
    date_default_timezone_set('Africa/Johannesburg');
    $today = new DateTimeImmutable('today');
    $monthStart = $today->modify('first day of this month')->format('Y-m-d');
    $monthEnd   = $today->format('Y-m-d');
    // Sum revenue and expense accounts using journal_lines
    $stmt = $DB->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' THEN (COALESCE(jl.credit, jl.credit_cents/100) - COALESCE(jl.debit, jl.debit_cents/100)) ELSE 0 END),0)
            + COALESCE(SUM(CASE WHEN ga.account_type = 'expense' THEN (COALESCE(jl.debit, jl.debit_cents/100) - COALESCE(jl.credit, jl.credit_cents/100)) ELSE 0 END),0)
            AS net_income
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_id
        JOIN gl_accounts ga ON ga.company_id = je.company_id AND (ga.account_code = jl.account_code OR ga.account_id = jl.account_id)
        WHERE je.company_id = ? AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt->execute([$companyId, $monthStart, $monthEnd]);
    $net_income = (float)$stmt->fetchColumn();
    $pl_month_cents = (int)round($net_income * 100);

    // 5. VAT Due – Output VAT minus Input VAT across all transactions
    $stmt = $DB->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN ga.account_code = ? THEN (COALESCE(jl.credit, jl.credit_cents/100) - COALESCE(jl.debit, jl.debit_cents/100)) ELSE 0 END),0) AS output_vat,
            COALESCE(SUM(CASE WHEN ga.account_code = ? THEN (COALESCE(jl.debit, jl.debit_cents/100) - COALESCE(jl.credit, jl.credit_cents/100)) ELSE 0 END),0) AS input_vat
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_id
        JOIN gl_accounts ga ON ga.company_id = je.company_id AND (ga.account_code = jl.account_code OR ga.account_id = jl.account_id)
        WHERE je.company_id = ?
    ");
    $stmt->execute([$vatOutCode, $vatInCode, $companyId]);
    $vatRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $vatDue = (float)($vatRow['output_vat'] ?? 0) - (float)($vatRow['input_vat'] ?? 0);
    $vat_due_cents = (int)round($vatDue * 100);

    // 6. Bank transactions to reconcile – count unmatched bank transactions
    $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND matched = 0");
    $stmt->execute([$companyId]);
    $bank_unrec_count = (int)$stmt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'data' => [
            'cash_cents'        => $cash_cents,
            'ar_cents'          => $ar_cents,
            'ap_cents'          => $ap_cents,
            'pl_month_cents'    => $pl_month_cents,
            'vat_due_cents'     => $vat_due_cents,
            'bank_unrec_count'  => $bank_unrec_count
        ]
    ]);

} catch (Exception $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load dashboard statistics'
    ]);
}