<?php
// /finances/index.php — Finances command deck (server-rendered overview)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Include permissions helper and restrict access
require_once __DIR__ . '/permissions.php';
// Only admin, bookkeeper or viewer roles may access the finance dashboard.
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Check if default accounts exist, if not seed them
$stmt = $DB->prepare("SELECT COUNT(*) as cnt FROM gl_accounts WHERE company_id = ?");
$stmt->execute([$companyId]);
$accountCount = $stmt->fetch()['cnt'];

if ($accountCount == 0 && in_array(fw_get_user_role($DB), ['admin', 'bookkeeper'], true)) {
    // Seed SARS-aligned chart of accounts for new companies. Write-role only
    // (viewers must not mutate the books), and tolerant of the concurrent-GET
    // race: the procedure re-checks, and a duplicate-seed error must not take
    // the dashboard down.
    try {
        $stmtSeed = $DB->prepare("CALL seed_sars_chart_of_accounts(?)");
        $stmtSeed->execute([$companyId]);
    } catch (Throwable $e) {
        error_log('CoA seed skipped: ' . $e->getMessage());
    }
}

// Statistics for the overview command deck.
// Data caveats respected throughout: invoices.status='overdue' is never written
// anywhere, so overdue is DERIVED from due_date; ap_bills carry no stored
// balance, so outstanding is derived from payment + vendor-credit allocations;
// journal_lines.debit/credit and invoice/bill amounts are RANDS while
// gl_bank_accounts / gl_bank_transactions / gl_vat_periods are CENTS.

// --- Cash on hand (per bank feed; cents) ---
$cashStmt = $DB->prepare("
    SELECT COALESCE(SUM(current_balance_cents), 0) AS cash_cents,
           COUNT(*) AS account_count
    FROM gl_bank_accounts
    WHERE company_id = ? AND is_active = 1
");
$cashStmt->execute([$companyId]);
$cashRow = $cashStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$cashOnHand = ((int)($cashRow['cash_cents'] ?? 0)) / 100;
$bankAccountCount = (int)($cashRow['account_count'] ?? 0);

// --- Receivables: outstanding + derived overdue + aging (one pass; ZAR) ---
$recvStmt = $DB->prepare("
    SELECT
        COUNT(*) AS open_count,
        COALESCE(SUM(i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1)), 0) AS outstanding_zar,
        SUM(i.due_date < CURDATE()) AS overdue_count,
        COALESCE(SUM(CASE WHEN i.due_date < CURDATE()
                          THEN i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS overdue_zar,
        SUM(i.due_date >= CURDATE()) AS cnt_current,
        COALESCE(SUM(CASE WHEN i.due_date >= CURDATE()
                          THEN i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS aging_current,
        SUM(DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30) AS cnt_b30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30
                          THEN i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS aging_b30,
        SUM(DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60) AS cnt_b60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60
                          THEN i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS aging_b60,
        SUM(DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90) AS cnt_b90,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90
                          THEN i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS aging_b90,
        SUM(DATEDIFF(CURDATE(), i.due_date) > 90) AS cnt_b90plus,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90
                          THEN i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS aging_b90plus,
        SUM(i.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS due_week_count
    FROM invoices i
    WHERE i.company_id = ? AND i.deleted_at IS NULL
      AND i.status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded')
      AND i.balance_due > 0
");
$recvStmt->execute([$companyId]);
$recv = $recvStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$arOutstanding = (float)($recv['outstanding_zar'] ?? 0);
$arOpenCount = (int)($recv['open_count'] ?? 0);
$arOverdue = (float)($recv['overdue_zar'] ?? 0);
$arOverdueCount = (int)($recv['overdue_count'] ?? 0);
$dueWeekCount = (int)($recv['due_week_count'] ?? 0);

// --- Payables: derived balances (total − payments − vendor credits; rands) ---
$apStmt = $DB->prepare("
    SELECT
        COUNT(*) AS open_bills,
        COALESCE(SUM(balance), 0) AS ap_outstanding,
        SUM(due_date IS NOT NULL AND due_date < CURDATE()) AS overdue_count,
        COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN balance END), 0) AS overdue_val,
        SUM(due_date IS NULL OR due_date >= CURDATE()) AS cnt_current,
        COALESCE(SUM(CASE WHEN due_date IS NULL OR due_date >= CURDATE() THEN balance END), 0) AS aging_current,
        SUM(DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30) AS cnt_b30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN balance END), 0) AS aging_b30,
        SUM(DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60) AS cnt_b60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN balance END), 0) AS aging_b60,
        SUM(DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90) AS cnt_b90,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN balance END), 0) AS aging_b90,
        SUM(DATEDIFF(CURDATE(), due_date) > 90) AS cnt_b90plus,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN balance END), 0) AS aging_b90plus
    FROM (
        SELECT b.id, b.due_date,
               (b.total - IFNULL(p.paid_tot, 0) - IFNULL(c.cred_tot, 0)) AS balance
        FROM ap_bills b
        LEFT JOIN (SELECT bill_id, SUM(amount) AS paid_tot FROM ap_payment_allocations GROUP BY bill_id) p
               ON p.bill_id = b.id
        LEFT JOIN (SELECT bill_id, SUM(amount) AS cred_tot FROM vendor_credit_allocations GROUP BY bill_id) c
               ON c.bill_id = b.id
        WHERE b.company_id = ? AND b.status IN ('posted','paid')
    ) t
    WHERE balance > 0.005
");
$apStmt->execute([$companyId]);
$ap = $apStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$apOutstanding = (float)($ap['ap_outstanding'] ?? 0);
$apOpenCount = (int)($ap['open_bills'] ?? 0);
$apOverdue = (float)($ap['overdue_val'] ?? 0);
$apOverdueCount = (int)($ap['overdue_count'] ?? 0);

// --- 12-month scaffold (zero-filled; months with no journals return no rows) ---
$monthKeys12 = [];
$monthLabels12 = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("first day of -{$i} months");
    $monthKeys12[date('Y-m', $ts)] = count($monthKeys12);
    $monthLabels12[] = date('M', $ts);
}
$rangeStart12 = array_key_first($monthKeys12) . '-01';
$zero12 = array_fill(0, count($monthKeys12), 0);
$monthStart = date('Y-m-01');
$prevMonthStart = date('Y-m-01', strtotime('first day of -1 month'));

// --- Net cash flow per month (posted GL, bank/cash subtypes; rands) ---
$cashflowSeries = $zero12;
$cashflowStmt = $DB->prepare("
    SELECT DATE_FORMAT(je.entry_date, '%Y-%m') AS ym,
           COALESCE(SUM(jl.debit - jl.credit), 0) AS net_cash
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_id
    JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
    WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date >= ?
      AND ga.account_type = 'asset' AND ga.account_subtype IN ('bank','cash')
    GROUP BY ym
");
$cashflowStmt->execute([$companyId, $rangeStart12]);
foreach ($cashflowStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($monthKeys12[$row['ym']])) {
        $cashflowSeries[$monthKeys12[$row['ym']]] = (int)round((float)$row['net_cash']);
    }
}

// --- P&L: MTD net profit + previous month for the delta (one pass; rands) ---
// Sign conventions per report_pl: revenue = credit − debit, expense = debit − credit
// (type-level sums keep contra accounts like 4200 Sales Returns correct).
$plStmt = $DB->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' AND je.entry_date >= ? THEN jl.credit - jl.debit END), 0) AS revenue_mtd,
        COALESCE(SUM(CASE WHEN ga.account_type = 'expense' AND je.entry_date >= ? THEN jl.debit - jl.credit END), 0) AS expenses_mtd,
        COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' AND je.entry_date <  ? THEN jl.credit - jl.debit END), 0) AS revenue_prev,
        COALESCE(SUM(CASE WHEN ga.account_type = 'expense' AND je.entry_date <  ? THEN jl.debit - jl.credit END), 0) AS expenses_prev
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_id
    JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
    WHERE je.company_id = ? AND je.status = 'posted'
      AND je.entry_date >= ? AND je.entry_date <= CURDATE()
      AND ga.account_type IN ('revenue','expense')
      -- Exclude year-end close journals: they roll P&L to retained earnings and
      -- would otherwise distort the period's revenue/expense widgets.
      AND (je.module IS NULL OR je.module <> 'year_end')
");
$plStmt->execute([$monthStart, $monthStart, $monthStart, $monthStart, $companyId, $prevMonthStart]);
$pl = $plStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$netMtd = (float)($pl['revenue_mtd'] ?? 0) - (float)($pl['expenses_mtd'] ?? 0);
$netPrev = (float)($pl['revenue_prev'] ?? 0) - (float)($pl['expenses_prev'] ?? 0);
$netDelta = $netMtd - $netPrev;

// --- Revenue vs expenses per month, 12 months (rands) ---
$revenueSeries12 = $zero12;
$expenseSeries12 = $zero12;
$netSeries12 = $zero12;
$revExpStmt = $DB->prepare("
    SELECT DATE_FORMAT(je.entry_date, '%Y-%m') AS ym,
           COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' THEN jl.credit - jl.debit END), 0) AS rev_val,
           COALESCE(SUM(CASE WHEN ga.account_type = 'expense' THEN jl.debit - jl.credit END), 0) AS exp_val
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_id
    JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
    WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date >= ?
      AND ga.account_type IN ('revenue','expense')
      AND (je.module IS NULL OR je.module <> 'year_end')
    GROUP BY ym
");
$revExpStmt->execute([$companyId, $rangeStart12]);
foreach ($revExpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($monthKeys12[$row['ym']])) {
        $idx = $monthKeys12[$row['ym']];
        $revenueSeries12[$idx] = (int)round((float)$row['rev_val']);
        $expenseSeries12[$idx] = (int)round((float)$row['exp_val']);
        $netSeries12[$idx] = (int)round((float)$row['rev_val'] - (float)$row['exp_val']);
    }
}

// --- Invoiced value per month (AR slab sparkline; ZAR) ---
$invoicedSeries12 = $zero12;
$invoicedStmt = $DB->prepare("
    SELECT DATE_FORMAT(i.issue_date, '%Y-%m') AS ym,
           COALESCE(SUM(i.total * COALESCE(NULLIF(i.exchange_rate,0),1)), 0) AS invoiced
    FROM invoices i
    WHERE i.company_id = ? AND i.deleted_at IS NULL
      AND i.status NOT IN ('draft','cancelled') AND i.issue_date >= ?
    GROUP BY ym
");
$invoicedStmt->execute([$companyId, $rangeStart12]);
foreach ($invoicedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($monthKeys12[$row['ym']])) {
        $invoicedSeries12[$monthKeys12[$row['ym']]] = (int)round((float)$row['invoiced']);
    }
}

// --- Mini KPIs ---
$draftJournalStmt = $DB->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND status = 'draft'");
$draftJournalStmt->execute([$companyId]);
$draftJournalCount = (int)$draftJournalStmt->fetchColumn();

$unrecStmt = $DB->prepare("
    SELECT COUNT(*) AS unrec_count, COALESCE(SUM(amount_cents), 0) AS unrec_cents
    FROM gl_bank_transactions
    WHERE company_id = ? AND matched = 0
");
$unrecStmt->execute([$companyId]);
$unrec = $unrecStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$unrecCount = (int)($unrec['unrec_count'] ?? 0);

// Live net VAT position from the books (chart-agnostic subtype filter; rands).
// Positive = owed to SARS (output VAT is credit-normal).
$vatStmt = $DB->prepare("
    SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS net_vat
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_id
    JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
    WHERE je.company_id = ? AND je.status = 'posted'
      AND ga.account_subtype = 'vat_control'
");
$vatStmt->execute([$companyId]);
$netVat = (float)$vatStmt->fetchColumn();

// Next VAT filing: earliest period still open or prepared
$vatPeriodStmt = $DB->prepare("
    SELECT period_end, status
    FROM gl_vat_periods
    WHERE company_id = ? AND status IN ('open','prepared')
    ORDER BY period_end ASC
    LIMIT 1
");
$vatPeriodStmt->execute([$companyId]);
$nextVatPeriod = $vatPeriodStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// Locked through (mirrors PeriodService enforcement, which honours soft deletes)
$lockStmt = $DB->prepare("SELECT MAX(lock_date) FROM gl_period_locks WHERE company_id = ? AND is_active = 1");
$lockStmt->execute([$companyId]);
$lockedThrough = $lockStmt->fetchColumn() ?: null;

// --- Expense breakdown MTD (top 6 accounts + Other; rands) ---
$expBreakStmt = $DB->prepare("
    SELECT ga.account_name, COALESCE(SUM(jl.debit - jl.credit), 0) AS spend
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_id
    JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code
    WHERE je.company_id = ? AND je.status = 'posted'
      AND je.entry_date >= ? AND je.entry_date <= CURDATE()
      AND ga.account_type = 'expense'
    GROUP BY ga.account_code, ga.account_name
    HAVING spend > 0
    ORDER BY spend DESC
");
$expBreakStmt->execute([$companyId, $monthStart]);
$expenseLabels = [];
$expenseValues = [];
$otherSpend = 0.0;
foreach ($expBreakStmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
    if ($i < 6) {
        $expenseLabels[] = (string)$row['account_name'];
        $expenseValues[] = (int)round((float)$row['spend']);
    } else {
        $otherSpend += (float)$row['spend'];
    }
}
if ($otherSpend > 0) {
    $expenseLabels[] = 'Other';
    $expenseValues[] = (int)round($otherSpend);
}

// --- Top overdue receivables (derived overdue) ---
$overdueArStmt = $DB->prepare("
    SELECT i.id, i.invoice_number, COALESCE(ca.name, '(deleted customer)') AS customer_name,
           DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
           i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) AS balance_zar
    FROM invoices i
    LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
    WHERE i.company_id = ? AND i.deleted_at IS NULL
      AND i.status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded')
      AND i.balance_due > 0 AND i.due_date < CURDATE()
    ORDER BY days_overdue DESC
    LIMIT 5
");
$overdueArStmt->execute([$companyId]);
$topOverdueAr = $overdueArStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Top overdue payables (derived balances) ---
$overdueApStmt = $DB->prepare("
    SELECT b.id, b.vendor_invoice_number, COALESCE(ca.name, '(deleted supplier)') AS supplier_name,
           DATEDIFF(CURDATE(), b.due_date) AS days_overdue,
           (b.total - IFNULL(p.paid_tot, 0) - IFNULL(c.cred_tot, 0)) AS balance
    FROM ap_bills b
    LEFT JOIN crm_accounts ca ON ca.id = b.supplier_id
    LEFT JOIN (SELECT bill_id, SUM(amount) AS paid_tot FROM ap_payment_allocations GROUP BY bill_id) p
           ON p.bill_id = b.id
    LEFT JOIN (SELECT bill_id, SUM(amount) AS cred_tot FROM vendor_credit_allocations GROUP BY bill_id) c
           ON c.bill_id = b.id
    WHERE b.company_id = ? AND b.status IN ('posted','paid')
      AND b.due_date IS NOT NULL AND b.due_date < CURDATE()
    HAVING balance > 0.005
    ORDER BY days_overdue DESC
    LIMIT 5
");
$overdueApStmt->execute([$companyId]);
$topOverdueAp = $overdueApStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Recent journal entries (totals are RANDS — no /100) ---
$recentJournalStmt = $DB->prepare("
    SELECT je.id, je.entry_date, je.reference, je.description, je.module, je.status,
           (SELECT COALESCE(SUM(jl.debit), 0) FROM journal_lines jl WHERE jl.journal_id = je.id) AS total_debits
    FROM journal_entries je
    WHERE je.company_id = ?
    ORDER BY je.created_at DESC
    LIMIT 8
");
$recentJournalStmt->execute([$companyId]);
$recentJournals = $recentJournalStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Recent bank activity (cents) ---
$recentBankStmt = $DB->prepare("
    SELECT bt.tx_date, bt.description, bt.amount_cents, bt.matched, ba.name AS account_name
    FROM gl_bank_transactions bt
    LEFT JOIN gl_bank_accounts ba ON ba.id = bt.bank_account_id AND ba.company_id = bt.company_id
    WHERE bt.company_id = ?
    ORDER BY bt.tx_date DESC, bt.bank_tx_id DESC
    LIMIT 8
");
$recentBankStmt->execute([$companyId]);
$recentBankTx = $recentBankStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Presentation helpers ---
if (!function_exists('fmtRand')) {
    function fmtRand($val) {
        $val = (float)$val;
        $sign = $val < 0 ? '-' : '';
        $abs = abs($val);
        if ($abs >= 1000000) return $sign . 'R' . number_format($abs / 1000000, 1) . 'M';
        if ($abs >= 1000) return $sign . 'R' . number_format($abs / 1000, 1) . 'k';
        return $sign . 'R' . number_format($abs, 0);
    }
}
if (!function_exists('fwInitials')) {
    // Multibyte-safe: byte-level substr() can split a UTF-8
    // sequence, and htmlspecialchars() then returns '' for it
    function fwInitials($name) {
        if (preg_match('/^.{1,2}/us', (string)$name, $m)) {
            return strtoupper($m[0]);
        }
        return strtoupper(substr((string)$name, 0, 1)) ?: '?';
    }
}
if (!function_exists('fwTrim')) {
    // Multibyte-safe truncation for table descriptions
    function fwTrim($str, $len = 48) {
        $str = (string)$str;
        if (preg_match('/^(.{' . (int)$len . '}).+/us', $str, $m)) {
            return $m[1] . '…';
        }
        return $str;
    }
}

$hour = (int)date('G');
$dayGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$arAgingBuckets = [
    'current' => ['label' => 'Current',    'val' => (float)($recv['aging_current'] ?? 0), 'cnt' => (int)($recv['cnt_current'] ?? 0)],
    'b30'     => ['label' => '1–30 days',  'val' => (float)($recv['aging_b30'] ?? 0),     'cnt' => (int)($recv['cnt_b30'] ?? 0)],
    'b60'     => ['label' => '31–60 days', 'val' => (float)($recv['aging_b60'] ?? 0),     'cnt' => (int)($recv['cnt_b60'] ?? 0)],
    'b90'     => ['label' => '61–90 days', 'val' => (float)($recv['aging_b90'] ?? 0),     'cnt' => (int)($recv['cnt_b90'] ?? 0)],
    'b90plus' => ['label' => '90+ days',   'val' => (float)($recv['aging_b90plus'] ?? 0), 'cnt' => (int)($recv['cnt_b90plus'] ?? 0)],
];
$maxArAging = max(1, max(array_column($arAgingBuckets, 'val')));

$apAgingBuckets = [
    'current' => ['label' => 'Current',    'val' => (float)($ap['aging_current'] ?? 0), 'cnt' => (int)($ap['cnt_current'] ?? 0)],
    'b30'     => ['label' => '1–30 days',  'val' => (float)($ap['aging_b30'] ?? 0),     'cnt' => (int)($ap['cnt_b30'] ?? 0)],
    'b60'     => ['label' => '31–60 days', 'val' => (float)($ap['aging_b60'] ?? 0),     'cnt' => (int)($ap['cnt_b60'] ?? 0)],
    'b90'     => ['label' => '61–90 days', 'val' => (float)($ap['aging_b90'] ?? 0),     'cnt' => (int)($ap['cnt_b90'] ?? 0)],
    'b90plus' => ['label' => '90+ days',   'val' => (float)($ap['aging_b90plus'] ?? 0), 'cnt' => (int)($ap['cnt_b90plus'] ?? 0)],
];
$maxApAging = max(1, max(array_column($apAgingBuckets, 'val')));

$moduleLabels = ['qi' => 'QI', 'fin' => 'Finance', 'payroll' => 'Payroll', 'manual' => 'Manual'];

// Quick actions + kebab menu come from the shared role-aware nav
// (single source of truth in partials/nav.php).
require_once __DIR__ . '/partials/nav.php';
$quickActions = fw_finance_quick_actions($DB);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Finances – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

        <?php
        $finTitle = 'Finances';
        $finBack = null;
        // $finKebab intentionally not set — header.php defaults to the full
        // role-aware menu from partials/nav.php
        $finCompanyName = $companyName;
        $finFirstName = $firstName;
        include __DIR__ . '/partials/header.php';
        ?>

        <main class="fw-finance__main">

            <!-- Hero: greeting + alert chips -->
            <div class="fw-finance__dash-hero">
                <div>
                    <div class="fw-finance__dash-hello"><?= $dayGreeting ?>, <?= htmlspecialchars($firstName) ?> 👋</div>
                    <div class="fw-finance__dash-date"><?= date('l, j F Y') ?> · <?= $arOpenCount ?> open invoice<?= $arOpenCount === 1 ? '' : 's' ?> · <?= $apOpenCount ?> open bill<?= $apOpenCount === 1 ? '' : 's' ?></div>
                </div>
                <div class="fw-finance__dash-chips">
                    <?php if ($arOverdueCount > 0): ?>
                    <a href="/finances/ar/" class="fw-finance__dash-chip fw-finance__dash-chip--danger">⏰ <?= $arOverdueCount ?> overdue receivable<?= $arOverdueCount === 1 ? '' : 's' ?> · <?= fmtRand($arOverdue) ?></a>
                    <?php endif; ?>
                    <?php if ($apOverdueCount > 0): ?>
                    <a href="/finances/ap/" class="fw-finance__dash-chip fw-finance__dash-chip--warn">📑 <?= $apOverdueCount ?> overdue payable<?= $apOverdueCount === 1 ? '' : 's' ?> · <?= fmtRand($apOverdue) ?></a>
                    <?php endif; ?>
                    <?php if ($unrecCount > 0): ?>
                    <a href="/finances/bank/" class="fw-finance__dash-chip fw-finance__dash-chip--info">🏦 <?= $unrecCount ?> unreconciled transaction<?= $unrecCount === 1 ? '' : 's' ?></a>
                    <?php endif; ?>
                    <a href="/finances/journals.php" class="fw-finance__dash-chip">➕ New journal</a>
                </div>
            </div>

            <!-- Quick actions command deck — first thing in view, full SARS bookkeeping cycle -->
            <section class="fw-finance__table-card fw-finance__qa-deck" aria-label="Quick actions">
                <h3 class="fw-finance__chart-title">Quick Actions <small>SARS bookkeeping · one tap away</small></h3>
                <div class="fw-finance__quick-actions fw-finance__quick-actions--deck">
                    <?php foreach ($quickActions as $qa): ?>
                    <a href="<?= htmlspecialchars($qa['href']) ?>" class="fw-finance__qa-tile"><span class="fw-finance__qa-icon"><?= $qa['icon'] ?></span><span class="fw-finance__qa-label"><?= htmlspecialchars($qa['label']) ?></span></a>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Hero KPI slabs -->
            <div class="fw-finance__kpi-grid">
                <div class="fw-finance__kpi-card">
                    <div class="fw-finance__kpi-top">
                        <div class="fw-finance__kpi-icon">💰</div>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--flat"><?= $bankAccountCount ?> active account<?= $bankAccountCount === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="fw-finance__kpi-value" data-countup="<?= (int)round($cashOnHand) ?>" data-prefix="R">R<?= number_format(round($cashOnHand)) ?></div>
                    <div class="fw-finance__kpi-label">Cash on Hand</div>
                    <div class="fw-finance__kpi-foot">🏦 per bank feed balances</div>
                    <canvas class="fw-finance__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($cashflowSeries)) ?>" data-spark-color="#4ade80"></canvas>
                </div>

                <div class="fw-finance__kpi-card">
                    <div class="fw-finance__kpi-top">
                        <div class="fw-finance__kpi-icon">🧾</div>
                        <?php if ($arOverdueCount > 0): ?>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--down">▼ <?= $arOverdueCount ?> overdue · <?= fmtRand($arOverdue) ?></span>
                        <?php else: ?>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--flat">— all current</span>
                        <?php endif; ?>
                    </div>
                    <div class="fw-finance__kpi-value" data-countup="<?= (int)round($arOutstanding) ?>" data-prefix="R">R<?= number_format(round($arOutstanding)) ?></div>
                    <div class="fw-finance__kpi-label">Receivables Outstanding</div>
                    <div class="fw-finance__kpi-foot">📄 <?= $arOpenCount ?> open invoice<?= $arOpenCount === 1 ? '' : 's' ?></div>
                    <canvas class="fw-finance__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($invoicedSeries12)) ?>" data-spark-color="#3b82f6"></canvas>
                </div>

                <div class="fw-finance__kpi-card">
                    <div class="fw-finance__kpi-top">
                        <div class="fw-finance__kpi-icon fw-finance__kpi-icon--red">📑</div>
                        <?php if ($apOverdueCount > 0): ?>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--down">▼ <?= $apOverdueCount ?> past due · <?= fmtRand($apOverdue) ?></span>
                        <?php else: ?>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--flat">— all current</span>
                        <?php endif; ?>
                    </div>
                    <div class="fw-finance__kpi-value fw-finance__kpi-value--orange" data-countup="<?= (int)round($apOutstanding) ?>" data-prefix="R">R<?= number_format(round($apOutstanding)) ?></div>
                    <div class="fw-finance__kpi-label">Payables Outstanding</div>
                    <div class="fw-finance__kpi-foot">💳 <?= $apOpenCount ?> open bill<?= $apOpenCount === 1 ? '' : 's' ?></div>
                    <canvas class="fw-finance__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($expenseSeries12)) ?>" data-spark-color="#ef4444"></canvas>
                </div>

                <div class="fw-finance__kpi-card">
                    <div class="fw-finance__kpi-top">
                        <div class="fw-finance__kpi-icon fw-finance__kpi-icon--green">📈</div>
                        <?php if ($netDelta > 0): ?>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--up">▲ <?= fmtRand($netDelta) ?> vs last mo</span>
                        <?php elseif ($netDelta < 0): ?>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--down">▼ <?= fmtRand(abs($netDelta)) ?> vs last mo</span>
                        <?php else: ?>
                        <span class="fw-finance__kpi-delta fw-finance__kpi-delta--flat">— level with last mo</span>
                        <?php endif; ?>
                    </div>
                    <div class="fw-finance__kpi-value fw-finance__kpi-value--green" data-countup="<?= (int)round($netMtd) ?>" data-prefix="R">R<?= number_format(round($netMtd)) ?></div>
                    <div class="fw-finance__kpi-label">Net Profit This Month</div>
                    <div class="fw-finance__kpi-foot">💵 <?= fmtRand($netPrev) ?> last month</div>
                    <canvas class="fw-finance__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($netSeries12)) ?>" data-spark-color="#8b5cf6"></canvas>
                </div>
            </div>

            <!-- Secondary KPI strip -->
            <div class="fw-finance__kpi-strip">
                <div class="fw-finance__kpi">
                    <div class="fw-finance__kpi-num" data-countup="<?= $draftJournalCount ?>"><?= $draftJournalCount ?></div>
                    <div class="fw-finance__kpi-sub">Draft journals</div>
                </div>
                <div class="fw-finance__kpi">
                    <div class="fw-finance__kpi-num" data-countup="<?= $unrecCount ?>"><?= $unrecCount ?></div>
                    <div class="fw-finance__kpi-sub">Unreconciled transactions</div>
                </div>
                <div class="fw-finance__kpi">
                    <div class="fw-finance__kpi-num"><?= fmtRand(abs($netVat)) ?></div>
                    <div class="fw-finance__kpi-sub"><?= $netVat >= 0 ? 'VAT owed to SARS' : 'VAT refund due' ?></div>
                </div>
                <div class="fw-finance__kpi">
                    <div class="fw-finance__kpi-num"><?= $nextVatPeriod ? date('j M', strtotime($nextVatPeriod['period_end'])) : '—' ?></div>
                    <div class="fw-finance__kpi-sub">Next VAT filing</div>
                </div>
                <div class="fw-finance__kpi">
                    <div class="fw-finance__kpi-num"><?= $lockedThrough ? date('j M y', strtotime($lockedThrough)) : '—' ?></div>
                    <div class="fw-finance__kpi-sub"><?= $lockedThrough ? 'Locked through' : 'Not locked' ?></div>
                </div>
                <div class="fw-finance__kpi">
                    <div class="fw-finance__kpi-num" data-countup="<?= $dueWeekCount ?>"><?= $dueWeekCount ?></div>
                    <div class="fw-finance__kpi-sub">Invoices due this week</div>
                </div>
            </div>

            <!-- Aging funnels: receivables + payables -->
            <div class="fw-finance__charts-grid">
                <div class="fw-finance__chart-card">
                    <h3 class="fw-finance__chart-title">Receivables Aging <small><?= fmtRand($arOutstanding) ?> outstanding</small></h3>
                    <div class="fw-finance__funnel">
                        <?php foreach ($arAgingBuckets as $bucketKey => $bucket):
                            $val = $bucket['val'];
                            $cnt = $bucket['cnt'];
                            $pct = max(4, (int)round(100 * $val / $maxArAging));
                        ?>
                        <div class="fw-finance__funnel-row">
                            <div class="fw-finance__funnel-label"><?= $bucket['label'] ?></div>
                            <div class="fw-finance__funnel-track">
                                <div class="fw-finance__funnel-fill fw-finance__funnel-fill--<?= $bucketKey ?>" style="width:<?= $val > 0 ? $pct : 0 ?>%"><?= $cnt > 0 ? $cnt : '' ?></div>
                            </div>
                            <div class="fw-finance__funnel-val"><?= fmtRand($val) ?><small><?= $cnt ?> invoice<?= $cnt === 1 ? '' : 's' ?></small></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="fw-finance__chart-card">
                    <h3 class="fw-finance__chart-title">Payables Aging <small><?= fmtRand($apOutstanding) ?> outstanding</small></h3>
                    <div class="fw-finance__funnel">
                        <?php foreach ($apAgingBuckets as $bucketKey => $bucket):
                            $val = $bucket['val'];
                            $cnt = $bucket['cnt'];
                            $pct = max(4, (int)round(100 * $val / $maxApAging));
                        ?>
                        <div class="fw-finance__funnel-row">
                            <div class="fw-finance__funnel-label"><?= $bucket['label'] ?></div>
                            <div class="fw-finance__funnel-track">
                                <div class="fw-finance__funnel-fill fw-finance__funnel-fill--<?= $bucketKey ?>" style="width:<?= $val > 0 ? $pct : 0 ?>%"><?= $cnt > 0 ? $cnt : '' ?></div>
                            </div>
                            <div class="fw-finance__funnel-val"><?= fmtRand($val) ?><small><?= $cnt ?> bill<?= $cnt === 1 ? '' : 's' ?></small></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Charts: cash flow + revenue vs expenses -->
            <div class="fw-finance__charts-grid">
                <div class="fw-finance__chart-card">
                    <h3 class="fw-finance__chart-title">Net Cash Flow <small>posted GL · last 12 months</small></h3>
                    <div class="fw-finance__chart-body">
                        <canvas id="chartCashflow" height="220"></canvas>
                    </div>
                </div>
                <div class="fw-finance__chart-card">
                    <h3 class="fw-finance__chart-title">Revenue vs Expenses <small>last 12 months</small></h3>
                    <div class="fw-finance__chart-body">
                        <canvas id="chartRevExp" height="220"></canvas>
                    </div>
                </div>
            </div>

            <!-- Charts: expense breakdown + AR vs AP -->
            <div class="fw-finance__charts-grid">
                <div class="fw-finance__chart-card">
                    <h3 class="fw-finance__chart-title">Expense Breakdown <small>this month · top accounts</small></h3>
                    <div class="fw-finance__chart-body">
                        <canvas id="chartExpense" height="200"></canvas>
                    </div>
                </div>
                <div class="fw-finance__chart-card">
                    <h3 class="fw-finance__chart-title">AR vs AP <small>outstanding balances</small></h3>
                    <div class="fw-finance__chart-body">
                        <canvas id="chartArAp" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top overdue receivables + top overdue payables -->
            <div class="fw-finance__tables-grid">
                <div class="fw-finance__table-card">
                    <h3 class="fw-finance__chart-title">Top Overdue Receivables <small>most overdue first</small></h3>
                    <div class="fw-finance__mini-list">
                        <?php if (empty($topOverdueAr)): ?>
                            <div class="fw-finance__empty-note">No overdue receivables 🎉<small>Invoices past their due date will rank here</small></div>
                        <?php else: foreach ($topOverdueAr as $inv): ?>
                        <a class="fw-finance__mini-row" href="/finances/ar/invoice_view.php?id=<?= (int)$inv['id'] ?>">
                            <div class="fw-finance__mini-avatar fw-finance__mini-avatar--customer"><?= htmlspecialchars(fwInitials($inv['customer_name'])) ?></div>
                            <div class="fw-finance__mini-info">
                                <div class="fw-finance__mini-name"><?= htmlspecialchars($inv['customer_name']) ?></div>
                                <div class="fw-finance__mini-sub"><?= htmlspecialchars($inv['invoice_number']) ?> · <?= (int)$inv['days_overdue'] ?>d overdue</div>
                            </div>
                            <div class="fw-finance__mini-val"><?= fmtRand((float)$inv['balance_zar']) ?></div>
                        </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <div class="fw-finance__table-card">
                    <h3 class="fw-finance__chart-title">Top Overdue Payables <small>most overdue first</small></h3>
                    <div class="fw-finance__mini-list">
                        <?php if (empty($topOverdueAp)): ?>
                            <div class="fw-finance__empty-note">No overdue payables 🎉<small>Bills past their due date will rank here</small></div>
                        <?php else: foreach ($topOverdueAp as $bill): ?>
                        <a class="fw-finance__mini-row" href="/finances/ap/bill_view.php?id=<?= (int)$bill['id'] ?>">
                            <div class="fw-finance__mini-avatar"><?= htmlspecialchars(fwInitials($bill['supplier_name'])) ?></div>
                            <div class="fw-finance__mini-info">
                                <div class="fw-finance__mini-name"><?= htmlspecialchars($bill['supplier_name']) ?></div>
                                <div class="fw-finance__mini-sub"><?= htmlspecialchars($bill['vendor_invoice_number'] ?: 'Bill #' . (int)$bill['id']) ?> · <?= (int)$bill['days_overdue'] ?>d overdue</div>
                            </div>
                            <div class="fw-finance__mini-val"><?= fmtRand((float)$bill['balance']) ?></div>
                        </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent journals + recent bank activity -->
            <div class="fw-finance__tables-grid">
                <div class="fw-finance__table-card">
                    <h3 class="fw-finance__chart-title">Recent Journal Entries <small>latest first</small></h3>
                    <div class="fw-finance__table-scroll">
                        <table class="fw-finance__dash-table">
                            <thead>
                                <tr><th>Entry</th><th>Date</th><th>Description</th><th>Module</th><th>Status</th><th>Total</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentJournals)): ?>
                                    <tr><td colspan="6" class="fw-finance__empty-note">No journal entries yet — post your first journal 📝</td></tr>
                                <?php else: foreach ($recentJournals as $je): ?>
                                    <tr>
                                        <td><a href="/finances/gl/journal_view.php?id=<?= (int)$je['id'] ?>"><?= htmlspecialchars($je['reference'] ?: 'JE-' . (int)$je['id']) ?></a></td>
                                        <td><?= $je['entry_date'] ? date('d M', strtotime($je['entry_date'])) : '—' ?></td>
                                        <td><?= htmlspecialchars(fwTrim($je['description'] ?? '', 42)) ?: '—' ?></td>
                                        <td><span class="fw-finance__badge fw-finance__badge--module"><?= htmlspecialchars($moduleLabels[$je['module']] ?? ucfirst((string)$je['module'])) ?></span></td>
                                        <td><span class="fw-finance__badge fw-finance__badge--<?= htmlspecialchars($je['status']) ?>"><?= htmlspecialchars(ucfirst($je['status'])) ?></span></td>
                                        <td><?= fmtRand((float)$je['total_debits']) /* journal_lines are rands — never /100 */ ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="fw-finance__table-card">
                    <h3 class="fw-finance__chart-title">Recent Bank Activity <small>latest imported</small></h3>
                    <div class="fw-finance__table-scroll">
                        <table class="fw-finance__dash-table">
                            <thead>
                                <tr><th>Date</th><th>Description</th><th>Amount</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentBankTx)): ?>
                                    <tr><td colspan="4" class="fw-finance__empty-note">No bank transactions yet — import a statement 🏦</td></tr>
                                <?php else: foreach ($recentBankTx as $tx):
                                    $amt = ((int)$tx['amount_cents']) / 100;
                                ?>
                                    <tr>
                                        <td><?= $tx['tx_date'] ? date('d M', strtotime($tx['tx_date'])) : '—' ?></td>
                                        <td><?= htmlspecialchars(fwTrim($tx['description'] ?? '', 42)) ?: '—' ?><?php if (!empty($tx['account_name'])): ?> <small><?= htmlspecialchars($tx['account_name']) ?></small><?php endif; ?></td>
                                        <td class="fw-finance__amount <?= $amt >= 0 ? 'fw-finance__amount--credit' : 'fw-finance__amount--debit' ?>"><?= $amt >= 0 ? '+' : '−' ?>R<?= number_format(abs($amt), 2) ?></td>
                                        <td><span class="fw-finance__badge fw-finance__badge--<?= ((int)$tx['matched']) === 1 ? 'matched' : 'unmatched' ?>"><?= ((int)$tx['matched']) === 1 ? 'Matched' : 'Unmatched' ?></span></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-finance__footer">
            <span>Finances v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (function() {
      'use strict';

      const MONTH_LABELS_12 = <?= json_encode($monthLabels12, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const CASHFLOW = <?= json_encode($cashflowSeries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const REVENUE_12 = <?= json_encode($revenueSeries12, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const EXPENSES_12 = <?= json_encode($expenseSeries12, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      // Account names are free text — HEX-escaped json_encode keeps them inert in inline JS
      const EXPENSE_LABELS = <?= json_encode($expenseLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const EXPENSE_VALUES = <?= json_encode($expenseValues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const AR_AP = <?= json_encode([(int)round($arOutstanding), (int)round($apOutstanding)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      window.chartInstances = window.chartInstances || {};

      function themePalette() {
        const isDark = document.querySelector('.fw-finance').getAttribute('data-theme') === 'dark';
        return {
          isDark: isDark,
          green: isDark ? '#4ade80' : '#16a34a',
          red: '#ef4444',
          orange: '#f59e0b',
          purple: '#8b5cf6',
          blue: '#3b82f6',
          aqua: '#00ffb5',
          slate: '#64748b',
          grid: isDark ? 'rgba(255,255,255,.06)' : 'rgba(21,39,50,.06)',
          tick: isDark ? '#9cb4c0' : '#64737d',
          tooltipBg: isDark ? 'rgba(10,20,28,.95)' : 'rgba(255,255,255,.96)',
          tooltipText: isDark ? '#e7f0f4' : '#16212b',
          border: isDark ? 'rgba(255,255,255,.1)' : 'rgba(21,39,50,.1)',
          emptySeg: isDark ? '#1e293b' : '#e2e8f0',
          donutBorder: isDark ? 'rgba(11,19,30,.9)' : '#ffffff'
        };
      }

      function glossTooltip(p) {
        return {
          backgroundColor: p.tooltipBg,
          titleColor: p.tooltipText,
          bodyColor: p.tooltipText,
          borderColor: p.border,
          borderWidth: 1,
          padding: 12,
          cornerRadius: 10,
          boxPadding: 4,
          titleFont: { family: 'Inter', weight: '700' },
          bodyFont: { family: 'Inter' }
        };
      }

      function vGradient(ctx, area, hex) {
        const g = ctx.createLinearGradient(0, area.bottom, 0, area.top);
        g.addColorStop(0, hex + '33');
        g.addColorStop(1, hex);
        return g;
      }

      function randTick(v) {
        const s = v < 0 ? '-' : '';
        const a = Math.abs(v);
        if (a >= 1000000) return s + 'R' + (a / 1000000).toFixed(1) + 'M';
        if (a >= 1000) return s + 'R' + (a / 1000).toFixed(0) + 'k';
        return s + 'R' + a;
      }

      function randLabel(v) {
        const s = v < 0 ? '-' : '';
        return ' ' + s + 'R' + Math.abs(v).toLocaleString();
      }

      function destroyCharts() {
        ['cashflow', 'revexp', 'expense', 'arap'].forEach(function(k) {
          if (window.chartInstances[k]) {
            window.chartInstances[k].destroy();
            delete window.chartInstances[k];
          }
        });
      }

      function buildCharts() {
        if (typeof Chart === 'undefined') return;
        destroyCharts();
        const p = themePalette();
        Chart.defaults.font.family = 'Inter';

        // Net cash flow — green above the line, red below
        const cashflowCanvas = document.getElementById('chartCashflow');
        if (cashflowCanvas) {
          window.chartInstances.cashflow = new Chart(cashflowCanvas, {
            type: 'bar',
            data: {
              labels: MONTH_LABELS_12,
              datasets: [{
                label: 'Net cash flow',
                data: CASHFLOW,
                borderRadius: 8,
                borderSkipped: false,
                maxBarThickness: 34,
                backgroundColor: function(c) {
                  const area = c.chart.chartArea;
                  const v = typeof c.raw === 'number' ? c.raw : 0;
                  const hex = v >= 0 ? p.green : p.red;
                  return area ? vGradient(c.chart.ctx, area, hex) : hex;
                }
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              plugins: {
                legend: { display: false },
                tooltip: Object.assign(glossTooltip(p), {
                  callbacks: { label: ctx => randLabel(ctx.parsed.y) }
                })
              },
              scales: {
                x: { grid: { display: false }, ticks: { color: p.tick } },
                y: { grid: { color: p.grid }, ticks: { color: p.tick, callback: randTick }, border: { display: false } }
              }
            }
          });
        }

        // Revenue vs expenses — paired glossy bars
        const revExpCanvas = document.getElementById('chartRevExp');
        if (revExpCanvas) {
          window.chartInstances.revexp = new Chart(revExpCanvas, {
            type: 'bar',
            data: {
              labels: MONTH_LABELS_12,
              datasets: [
                {
                  label: 'Revenue',
                  data: REVENUE_12,
                  borderRadius: 6,
                  borderSkipped: false,
                  maxBarThickness: 20,
                  backgroundColor: function(c) {
                    const area = c.chart.chartArea;
                    return area ? vGradient(c.chart.ctx, area, p.green) : p.green;
                  }
                },
                {
                  label: 'Expenses',
                  data: EXPENSES_12,
                  borderRadius: 6,
                  borderSkipped: false,
                  maxBarThickness: 20,
                  backgroundColor: function(c) {
                    const area = c.chart.chartArea;
                    return area ? vGradient(c.chart.ctx, area, p.red) : p.red;
                  }
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              plugins: {
                legend: { position: 'bottom', labels: { color: p.tick, usePointStyle: true, pointStyle: 'circle', padding: 16 } },
                tooltip: Object.assign(glossTooltip(p), {
                  callbacks: { label: ctx => ' ' + ctx.dataset.label + ':' + randLabel(ctx.parsed.y) }
                })
              },
              scales: {
                x: { grid: { display: false }, ticks: { color: p.tick } },
                y: { grid: { color: p.grid }, ticks: { color: p.tick, callback: randTick }, border: { display: false } }
              }
            }
          });
        }

        // Expense breakdown MTD — doughnut, top accounts + Other
        const expenseCanvas = document.getElementById('chartExpense');
        if (expenseCanvas) {
          const hasExpense = EXPENSE_VALUES.length > 0;
          const expenseColors = [p.green, p.blue, p.purple, p.orange, p.aqua, p.red, p.slate];
          window.chartInstances.expense = new Chart(expenseCanvas, {
            type: 'doughnut',
            data: {
              labels: hasExpense ? EXPENSE_LABELS : ['No expenses yet'],
              datasets: [{
                data: hasExpense ? EXPENSE_VALUES : [1],
                backgroundColor: hasExpense
                  ? EXPENSE_LABELS.map((_, i) => expenseColors[i % expenseColors.length])
                  : [p.emptySeg],
                borderWidth: 2,
                borderColor: p.donutBorder,
                hoverOffset: 8
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              cutout: '68%',
              plugins: {
                legend: { position: 'right', labels: { color: p.tick, usePointStyle: true, pointStyle: 'circle', padding: 12, boxWidth: 8 } },
                tooltip: Object.assign(glossTooltip(p), {
                  callbacks: { label: ctx => ' ' + ctx.label + ':' + (hasExpense ? randLabel(ctx.parsed) : ' —') }
                })
              }
            }
          });
        }

        // AR vs AP outstanding — two-segment doughnut
        const arApCanvas = document.getElementById('chartArAp');
        if (arApCanvas) {
          const hasBalances = (AR_AP[0] + AR_AP[1]) > 0;
          window.chartInstances.arap = new Chart(arApCanvas, {
            type: 'doughnut',
            data: {
              labels: hasBalances ? ['Receivables (AR)', 'Payables (AP)'] : ['Nothing outstanding'],
              datasets: [{
                data: hasBalances ? AR_AP : [1],
                backgroundColor: hasBalances ? [p.green, p.orange] : [p.emptySeg],
                borderWidth: 2,
                borderColor: p.donutBorder,
                hoverOffset: 8
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              cutout: '68%',
              plugins: {
                legend: { position: 'right', labels: { color: p.tick, usePointStyle: true, pointStyle: 'circle', padding: 12, boxWidth: 8 } },
                tooltip: Object.assign(glossTooltip(p), {
                  callbacks: { label: ctx => ' ' + ctx.label + ':' + (hasBalances ? randLabel(ctx.parsed) : ' —') }
                })
              }
            }
          });
        }
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildCharts);
      } else {
        buildCharts();
      }

      // finance.js destroys chart instances before dispatching fin:theme;
      // this listener only rebuilds with the new palette.
      document.addEventListener('fin:theme', function() { buildCharts(); });
    })();
    </script>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
