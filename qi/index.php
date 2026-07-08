<?php
// /qi/index.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/Currencies.php';

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

// Default tab
$activeTab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'quotes', 'invoices', 'recurring', 'credit_notes'];
if (!in_array($activeTab, $allowedTabs)) {
    $activeTab = 'overview';
}

// Fetch counts
$stmt = $DB->prepare("
    SELECT
        (SELECT COUNT(*) FROM quotes WHERE company_id = ? AND status NOT IN ('expired','declined')) as quote_count,
        (SELECT COUNT(*) FROM invoices WHERE company_id = ? AND status NOT IN ('cancelled') AND deleted_at IS NULL) as invoice_count,
        (SELECT COUNT(*) FROM recurring_invoices WHERE company_id = ? AND active = 1) as recurring_count,
        (SELECT COUNT(*) FROM credit_notes WHERE company_id = ?) as credit_count
");
$stmt->execute([$companyId, $companyId, $companyId, $companyId]);
$counts = $stmt->fetch();

// Statistics for the overview command deck.
// Data caveats respected throughout: nothing in the codebase ever sets
// invoices.status='overdue' or quotes.status='expired', so both are derived
// from due_date/expiry_date; money is normalised to ZAR via each document's
// exchange_rate; payments carry no currency of their own, so revenue converts
// through the allocated invoice; every invoices query filters deleted_at.
if ($activeTab === 'overview') {
    // --- Receivables + aging (one pass over open balances) ---
    $recvKpi = $DB->prepare("
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
    $recvKpi->execute([$companyId]);
    $recv = $recvKpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $outstandingZar = (float)($recv['outstanding_zar'] ?? 0);
    $openInvCount = (int)($recv['open_count'] ?? 0);
    $overdueZar = (float)($recv['overdue_zar'] ?? 0);
    $overdueCount = (int)($recv['overdue_count'] ?? 0);
    $dueWeekCount = (int)($recv['due_week_count'] ?? 0);

    $draftInvStmt = $DB->prepare("
        SELECT COUNT(*) FROM invoices
        WHERE company_id = ? AND deleted_at IS NULL AND status = 'draft'
    ");
    $draftInvStmt->execute([$companyId]);
    $draftInvCount = (int)$draftInvStmt->fetchColumn();

    // --- Revenue MTD vs previous month (cash received via allocations) ---
    $mtdStmt = $DB->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN p.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                              THEN pa.amount * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS mtd_zar,
            COALESCE(SUM(CASE WHEN p.payment_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                               AND p.payment_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                              THEN pa.amount * COALESCE(NULLIF(i.exchange_rate,0),1) END), 0) AS prev_month_zar
        FROM payments p
        JOIN payment_allocations pa ON pa.payment_id = p.id
        JOIN invoices i ON i.id = pa.invoice_id
        WHERE p.company_id = ? AND i.deleted_at IS NULL
          AND p.payment_date >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
    ");
    $mtdStmt->execute([$companyId]);
    $mtd = $mtdStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $mtdZar = (float)($mtd['mtd_zar'] ?? 0);
    $prevMonthZar = (float)($mtd['prev_month_zar'] ?? 0);

    // --- 12-month scaffold for sparklines + revenue chart ---
    $monthKeys12 = [];
    $monthLabels12 = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts = strtotime("first day of -{$i} months");
        $monthKeys12[date('Y-m', $ts)] = count($monthKeys12);
        $monthLabels12[] = date('M', $ts);
    }
    $rangeStart12 = array_key_first($monthKeys12) . '-01';
    $zero12 = array_fill(0, count($monthKeys12), 0);

    $revenueSeries = $zero12;
    $monthlyRevenue = $DB->prepare("
        SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym,
               COALESCE(SUM(pa.amount * COALESCE(NULLIF(i.exchange_rate,0),1)), 0) AS collected
        FROM payments p
        JOIN payment_allocations pa ON pa.payment_id = p.id
        JOIN invoices i ON i.id = pa.invoice_id
        WHERE p.company_id = ? AND i.deleted_at IS NULL AND p.payment_date >= ?
        GROUP BY ym
    ");
    $monthlyRevenue->execute([$companyId, $rangeStart12]);
    foreach ($monthlyRevenue->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys12[$row['ym']])) {
            $revenueSeries[$monthKeys12[$row['ym']]] = (int)round((float)$row['collected']);
        }
    }

    $invoicedSeries = $zero12;
    $monthlyInvoiced = $DB->prepare("
        SELECT DATE_FORMAT(i.issue_date, '%Y-%m') AS ym,
               COALESCE(SUM(i.total * COALESCE(NULLIF(i.exchange_rate,0),1)), 0) AS invoiced
        FROM invoices i
        WHERE i.company_id = ? AND i.deleted_at IS NULL
          AND i.status NOT IN ('draft','cancelled') AND i.issue_date >= ?
        GROUP BY ym
    ");
    $monthlyInvoiced->execute([$companyId, $rangeStart12]);
    foreach ($monthlyInvoiced->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys12[$row['ym']])) {
            $invoicedSeries[$monthKeys12[$row['ym']]] = (int)round((float)$row['invoiced']);
        }
    }

    $overdueSeries = $zero12;
    $monthlyOverdue = $DB->prepare("
        SELECT DATE_FORMAT(i.due_date, '%Y-%m') AS ym,
               COALESCE(SUM(i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1)), 0) AS overdue_val
        FROM invoices i
        WHERE i.company_id = ? AND i.deleted_at IS NULL
          AND i.status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded')
          AND i.balance_due > 0 AND i.due_date < CURDATE() AND i.due_date >= ?
        GROUP BY ym
    ");
    $monthlyOverdue->execute([$companyId, $rangeStart12]);
    foreach ($monthlyOverdue->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys12[$row['ym']])) {
            $overdueSeries[$monthKeys12[$row['ym']]] = (int)round((float)$row['overdue_val']);
        }
    }

    $quotedSeries = $zero12;
    $monthlyQuoted = $DB->prepare("
        SELECT DATE_FORMAT(q.issue_date, '%Y-%m') AS ym,
               COALESCE(SUM(q.total * COALESCE(NULLIF(q.exchange_rate,0),1)), 0) AS quoted
        FROM quotes q
        WHERE q.company_id = ? AND q.issue_date >= ?
        GROUP BY ym
    ");
    $monthlyQuoted->execute([$companyId, $rangeStart12]);
    foreach ($monthlyQuoted->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys12[$row['ym']])) {
            $quotedSeries[$monthKeys12[$row['ym']]] = (int)round((float)$row['quoted']);
        }
    }

    // --- Quote KPIs (one pass) ---
    $quoteKpi = $DB->prepare("
        SELECT
            SUM(status = 'draft' OR (status IN ('sent','viewed')
                AND (expiry_date IS NULL OR expiry_date >= CURDATE()))) AS open_cnt,
            COALESCE(SUM(CASE WHEN status = 'draft' OR (status IN ('sent','viewed')
                              AND (expiry_date IS NULL OR expiry_date >= CURDATE()))
                              THEN total * COALESCE(NULLIF(exchange_rate,0),1) END), 0) AS open_value_zar,
            SUM(status = 'draft') AS draft_cnt,
            SUM(status IN ('sent','viewed')
                AND expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS expiring_week
        FROM quotes
        WHERE company_id = ?
    ");
    $quoteKpi->execute([$companyId]);
    $quote = $quoteKpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $openQuoteCount = (int)($quote['open_cnt'] ?? 0);
    $openQuoteValue = (float)($quote['open_value_zar'] ?? 0);
    $expiringWeekCount = (int)($quote['expiring_week'] ?? 0);

    $acceptStmt = $DB->prepare("
        SELECT SUM(status IN ('accepted','converted')) AS won, COUNT(*) AS total
        FROM quotes
        WHERE company_id = ? AND issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ");
    $acceptStmt->execute([$companyId]);
    $acc = $acceptStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $acceptanceRate = (int)($acc['total'] ?? 0) > 0
        ? round(100 * (int)($acc['won'] ?? 0) / (int)$acc['total'], 1)
        : null;

    $collectStmt = $DB->prepare("
        SELECT ROUND(100 * SUM((i.total - i.balance_due) * COALESCE(NULLIF(i.exchange_rate,0),1))
                     / NULLIF(SUM(i.total * COALESCE(NULLIF(i.exchange_rate,0),1)), 0), 1)
        FROM invoices i
        WHERE i.company_id = ? AND i.deleted_at IS NULL
          AND i.status NOT IN ('draft','cancelled')
          AND i.issue_date >= CURDATE() - INTERVAL 90 DAY
    ");
    $collectStmt->execute([$companyId]);
    $collectionRate = $collectStmt->fetchColumn();
    $collectionRate = $collectionRate !== null && $collectionRate !== false ? (float)$collectionRate : null;

    $creditPendingStmt = $DB->prepare("
        SELECT COUNT(*) FROM credit_notes
        WHERE company_id = ? AND status IN ('draft','approved')
    ");
    $creditPendingStmt->execute([$companyId]);
    $creditPendingCount = (int)$creditPendingStmt->fetchColumn();

    // --- Outstanding split by status (overdue derived) for the doughnut ---
    $statusSplitStmt = $DB->prepare("
        SELECT CASE WHEN i.due_date < CURDATE() THEN 'overdue' ELSE i.status END AS status_key,
               COALESCE(SUM(i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1)), 0) AS value_zar
        FROM invoices i
        WHERE i.company_id = ? AND i.deleted_at IS NULL
          AND i.status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded')
          AND i.balance_due > 0
        GROUP BY status_key
    ");
    $statusSplitStmt->execute([$companyId]);
    $invoiceStatusSplit = [];
    foreach ($statusSplitStmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $invoiceStatusSplit[$k] = (int)round((float)$v);
    }

    // --- Quote outcomes (expired derived from expiry_date) ---
    $outcomeStmt = $DB->prepare("
        SELECT CASE
                   WHEN q.status IN ('accepted','converted') THEN 'accepted'
                   WHEN q.status = 'declined' THEN 'declined'
                   WHEN q.status = 'expired'
                        OR (q.status IN ('sent','viewed') AND q.expiry_date < CURDATE()) THEN 'expired'
                   ELSE 'pending'
               END AS outcome,
               COUNT(*) AS cnt
        FROM quotes q
        WHERE q.company_id = ? AND q.issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY outcome
    ");
    $outcomeStmt->execute([$companyId]);
    $quoteOutcomes = [];
    foreach ($outcomeStmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $quoteOutcomes[$k] = (int)$v;
    }

    // --- 6-month volume scaffold (quotes vs invoices created) ---
    $monthKeys6 = [];
    $monthLabels6 = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("first day of -{$i} months");
        $monthKeys6[date('Y-m', $ts)] = count($monthKeys6);
        $monthLabels6[] = date('M', $ts);
    }
    $rangeStart6 = array_key_first($monthKeys6) . '-01';
    $zero6 = array_fill(0, count($monthKeys6), 0);

    $quoteVolumeSeries = $zero6;
    $quoteVolStmt = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
        FROM quotes
        WHERE company_id = ? AND created_at >= ?
        GROUP BY ym
    ");
    $quoteVolStmt->execute([$companyId, $rangeStart6]);
    foreach ($quoteVolStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys6[$row['ym']])) {
            $quoteVolumeSeries[$monthKeys6[$row['ym']]] = (int)$row['cnt'];
        }
    }

    $invoiceVolumeSeries = $zero6;
    $invVolStmt = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
        FROM invoices
        WHERE company_id = ? AND deleted_at IS NULL AND created_at >= ?
        GROUP BY ym
    ");
    $invVolStmt->execute([$companyId, $rangeStart6]);
    foreach ($invVolStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys6[$row['ym']])) {
            $invoiceVolumeSeries[$monthKeys6[$row['ym']]] = (int)$row['cnt'];
        }
    }

    // --- Top debtors by outstanding balance ---
    $debtorStmt = $DB->prepare("
        SELECT ca.id, COALESCE(ca.name, '(deleted customer)') AS name,
               COUNT(i.id) AS open_invoices,
               COALESCE(SUM(i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1)), 0) AS outstanding_zar,
               MIN(i.due_date) AS oldest_due
        FROM invoices i
        LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
        WHERE i.company_id = ? AND i.deleted_at IS NULL
          AND i.status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded')
          AND i.balance_due > 0
        GROUP BY ca.id, ca.name
        ORDER BY outstanding_zar DESC
        LIMIT 5
    ");
    $debtorStmt->execute([$companyId]);
    $topDebtors = $debtorStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Recent documents (latest touched across all three types) ---
    $recentStmt = $DB->prepare("
        (SELECT 'quote' AS doc_type, q.id, q.quote_number AS doc_number,
                ca.name AS customer_name, q.status, q.updated_at
         FROM quotes q
         LEFT JOIN crm_accounts ca ON ca.id = q.customer_id
         WHERE q.company_id = ?)
        UNION ALL
        (SELECT 'invoice', i.id, i.invoice_number, ca.name, i.status, i.updated_at
         FROM invoices i
         LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
         WHERE i.company_id = ? AND i.deleted_at IS NULL)
        UNION ALL
        (SELECT 'credit_note', cn.id, cn.credit_note_number, ca.name, cn.status, cn.updated_at
         FROM credit_notes cn
         LEFT JOIN crm_accounts ca ON ca.id = cn.customer_id
         WHERE cn.company_id = ?)
        ORDER BY updated_at DESC
        LIMIT 8
    ");
    $recentStmt->execute([$companyId, $companyId, $companyId]);
    $recentDocs = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Overdue invoices table ---
    $overdueListStmt = $DB->prepare("
        SELECT i.id, i.invoice_number, ca.name AS customer_name,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
               i.balance_due * COALESCE(NULLIF(i.exchange_rate,0),1) AS balance_zar
        FROM invoices i
        LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
        WHERE i.company_id = ? AND i.deleted_at IS NULL
          AND i.status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded')
          AND i.balance_due > 0 AND i.due_date < CURDATE()
        ORDER BY days_overdue DESC
        LIMIT 8
    ");
    $overdueListStmt->execute([$companyId]);
    $overdueInvoices = $overdueListStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Quotes expiring soon table ---
    $expiringStmt = $DB->prepare("
        SELECT q.id, q.quote_number, ca.name AS customer_name, q.expiry_date,
               DATEDIFF(q.expiry_date, CURDATE()) AS days_left,
               q.total * COALESCE(NULLIF(q.exchange_rate,0),1) AS total_zar
        FROM quotes q
        LEFT JOIN crm_accounts ca ON ca.id = q.customer_id
        WHERE q.company_id = ? AND q.status IN ('sent','viewed')
          AND q.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
        ORDER BY q.expiry_date ASC
        LIMIT 8
    ");
    $expiringStmt->execute([$companyId]);
    $expiringQuotes = $expiringStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Quotes & Invoices – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= QI_ASSET_VERSION ?>">
</head>
<body class="fw-qi" data-theme="<?= ($_COOKIE['fw_theme'] ?? 'dark') === 'light' ? 'light' : 'dark' ?>">
    <div class="fw-qi__container">

        <!-- Header -->
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="10 9 9 9 8 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-qi__app-name">Quotes & Invoices</div>
                </div>
            </div>

            <div class="fw-qi__greeting">
                Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-qi__controls">
                <a href="/" class="fw-qi__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>

                <button class="fw-qi__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-qi__theme-icon fw-qi__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-qi__menu-wrapper">
                    <button class="fw-qi__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-qi__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/qi/quote_new.php" class="fw-qi__kebab-item">New Quote</a>
                        <a href="/qi/invoice_new.php" class="fw-qi__kebab-item">New Invoice</a>
                        <a href="/qi/recurring.php" class="fw-qi__kebab-item">Recurring Invoices</a>
                        <a href="/qi/settings.php" class="fw-qi__kebab-item">Settings</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-qi__main">

            <!-- Tabs -->
            <div class="fw-qi__tabs">
                <a href="?tab=overview" class="fw-qi__tab <?= $activeTab === 'overview' ? 'fw-qi__tab--active' : '' ?>">
                    Overview
                </a>
                <a href="?tab=quotes" class="fw-qi__tab <?= $activeTab === 'quotes' ? 'fw-qi__tab--active' : '' ?>">
                    Quotes <span class="fw-qi__tab-count"><?= $counts['quote_count'] ?? 0 ?></span>
                </a>
                <a href="?tab=invoices" class="fw-qi__tab <?= $activeTab === 'invoices' ? 'fw-qi__tab--active' : '' ?>">
                    Invoices <span class="fw-qi__tab-count"><?= $counts['invoice_count'] ?? 0 ?></span>
                </a>
                <a href="?tab=recurring" class="fw-qi__tab <?= $activeTab === 'recurring' ? 'fw-qi__tab--active' : '' ?>">
                    Recurring <span class="fw-qi__tab-count"><?= $counts['recurring_count'] ?? 0 ?></span>
                </a>
                <a href="?tab=credit_notes" class="fw-qi__tab <?= $activeTab === 'credit_notes' ? 'fw-qi__tab--active' : '' ?>">
                    Credit Notes <span class="fw-qi__tab-count"><?= $counts['credit_count'] ?? 0 ?></span>
                </a>
            </div>

            <!-- Only show toolbar if NOT on overview tab -->
            <?php if ($activeTab !== 'overview'): ?>
                <div class="fw-qi__toolbar">
                    <div class="fw-qi__toolbar-left">
                        <div class="fw-qi__search-wrapper">
                            <svg class="fw-qi__search-icon" viewBox="0 0 24 24" fill="none">
                                <circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
                                <path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <input
                                type="search"
                                class="fw-qi__search"
                                placeholder="Search by number, customer..."
                                id="searchInput"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="fw-qi__toolbar-right">
                    <select class="fw-qi__filter" id="filterStatus">
                        <option value="">All Statuses</option>
                        <?php if ($activeTab === 'quotes'): ?>
                            <option value="draft">Draft</option>
                            <option value="sent">Sent</option>
                            <option value="viewed">Viewed</option>
                            <option value="accepted">Accepted</option>
                            <option value="declined">Declined</option>
                            <option value="expired">Expired</option>
                        <?php elseif ($activeTab === 'invoices'): ?>
                            <option value="draft">Draft</option>
                            <option value="sent">Sent</option>
                            <option value="viewed">Viewed</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                            <option value="part-paid">Partially Paid</option>
                        <?php elseif ($activeTab === 'recurring'): ?>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        <?php elseif ($activeTab === 'credit_notes'): ?>
                            <option value="draft">Draft</option>
                            <option value="approved">Approved</option>
                            <option value="applied">Applied</option>
                        <?php endif; ?>
                    </select>

                    <input type="date" class="fw-qi__filter" id="filterDateFrom" placeholder="From">
                    <input type="date" class="fw-qi__filter" id="filterDateTo" placeholder="To">

                    <button class="fw-qi__btn fw-qi__btn--primary" onclick="QIIndex.createNew()">
                        + New <?= ucfirst(rtrim($activeTab, 's')) ?>
                    </button>
                    </div><!-- /.fw-qi__toolbar-right -->
                </div>
            <?php endif; ?>

            <!-- Overview or List Container -->
            <?php if ($activeTab === 'overview'): ?>
                <?php
                    if (!function_exists('fmtRand')) {
                        function fmtRand($val) {
                            $val = (float)$val;
                            if ($val >= 1000000) return 'R' . number_format($val / 1000000, 1) . 'M';
                            if ($val >= 1000) return 'R' . number_format($val / 1000, 1) . 'k';
                            return 'R' . number_format($val, 0);
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
                    $hour = (int)date('G');
                    $dayGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
                    $revDelta = $mtdZar - $prevMonthZar;
                    $agingBuckets = [
                        'current' => ['label' => 'Current',    'val' => (float)($recv['aging_current'] ?? 0), 'cnt' => (int)($recv['cnt_current'] ?? 0)],
                        'b30'     => ['label' => '1–30 days',  'val' => (float)($recv['aging_b30'] ?? 0),     'cnt' => (int)($recv['cnt_b30'] ?? 0)],
                        'b60'     => ['label' => '31–60 days', 'val' => (float)($recv['aging_b60'] ?? 0),     'cnt' => (int)($recv['cnt_b60'] ?? 0)],
                        'b90'     => ['label' => '61–90 days', 'val' => (float)($recv['aging_b90'] ?? 0),     'cnt' => (int)($recv['cnt_b90'] ?? 0)],
                        'b90plus' => ['label' => '90+ days',   'val' => (float)($recv['aging_b90plus'] ?? 0), 'cnt' => (int)($recv['cnt_b90plus'] ?? 0)],
                    ];
                    $maxAging = max(1, max(array_column($agingBuckets, 'val')));
                    $docMeta = [
                        'quote'       => ['icon' => '📄', 'href' => '/qi/quote_view.php?id='],
                        'invoice'     => ['icon' => '🧾', 'href' => '/qi/invoice_view.php?id='],
                        'credit_note' => ['icon' => '📑', 'href' => '/qi/credit_note_view.php?id='],
                    ];
                ?>
                <div id="qi-overview">

                    <!-- Hero: greeting + alert chips -->
                    <div class="fw-qi__dash-hero">
                        <div>
                            <div class="fw-qi__dash-hello"><?= $dayGreeting ?>, <?= htmlspecialchars($firstName) ?> 👋</div>
                            <div class="fw-qi__dash-date"><?= date('l, j F Y') ?> · <?= (int)($counts['quote_count'] ?? 0) ?> quotes · <?= (int)($counts['invoice_count'] ?? 0) ?> invoices</div>
                        </div>
                        <div class="fw-qi__dash-chips">
                            <?php if ($overdueCount > 0): ?>
                            <a href="?tab=invoices&status=overdue" class="fw-qi__dash-chip fw-qi__dash-chip--danger">⏰ <?= $overdueCount ?> overdue invoice<?= $overdueCount === 1 ? '' : 's' ?> · <?= fmtRand($overdueZar) ?></a>
                            <?php endif; ?>
                            <?php if ($expiringWeekCount > 0): ?>
                            <a href="?tab=quotes&status=sent" class="fw-qi__dash-chip fw-qi__dash-chip--warn">📅 <?= $expiringWeekCount ?> quote<?= $expiringWeekCount === 1 ? '' : 's' ?> expiring this week</a>
                            <?php endif; ?>
                            <?php if ($dueWeekCount > 0): ?>
                            <a href="?tab=invoices" class="fw-qi__dash-chip fw-qi__dash-chip--info">💳 <?= $dueWeekCount ?> invoice<?= $dueWeekCount === 1 ? '' : 's' ?> due this week</a>
                            <?php endif; ?>
                            <a href="/qi/quote_new.php" class="fw-qi__dash-chip">➕ New quote</a>
                        </div>
                    </div>

                    <!-- Hero KPI slabs -->
                    <div class="fw-qi__kpi-grid">
                        <div class="fw-qi__kpi-card">
                            <div class="fw-qi__kpi-top">
                                <div class="fw-qi__kpi-icon">💰</div>
                                <span class="fw-qi__kpi-delta fw-qi__kpi-delta--flat"><?= $openInvCount ?> open invoice<?= $openInvCount === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="fw-qi__kpi-value" data-countup="<?= (int)round($outstandingZar) ?>" data-prefix="R">R<?= number_format(round($outstandingZar)) ?></div>
                            <div class="fw-qi__kpi-label">Outstanding Receivables</div>
                            <canvas class="fw-qi__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($invoicedSeries)) ?>" data-spark-color="#fbbf24"></canvas>
                        </div>

                        <div class="fw-qi__kpi-card">
                            <div class="fw-qi__kpi-top">
                                <div class="fw-qi__kpi-icon fw-qi__kpi-icon--red">⏰</div>
                                <?php if ($overdueCount > 0): ?>
                                <span class="fw-qi__kpi-delta fw-qi__kpi-delta--down">▼ <?= $overdueCount ?> past due</span>
                                <?php else: ?>
                                <span class="fw-qi__kpi-delta fw-qi__kpi-delta--flat">— all current</span>
                                <?php endif; ?>
                            </div>
                            <div class="fw-qi__kpi-value fw-qi__kpi-value--orange" data-countup="<?= (int)round($overdueZar) ?>" data-prefix="R">R<?= number_format(round($overdueZar)) ?></div>
                            <div class="fw-qi__kpi-label">Overdue</div>
                            <canvas class="fw-qi__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($overdueSeries)) ?>" data-spark-color="#ef4444"></canvas>
                        </div>

                        <div class="fw-qi__kpi-card">
                            <div class="fw-qi__kpi-top">
                                <div class="fw-qi__kpi-icon fw-qi__kpi-icon--green">📈</div>
                                <?php if ($revDelta > 0): ?>
                                <span class="fw-qi__kpi-delta fw-qi__kpi-delta--up">▲ <?= fmtRand($revDelta) ?> vs last mo</span>
                                <?php elseif ($revDelta < 0): ?>
                                <span class="fw-qi__kpi-delta fw-qi__kpi-delta--down">▼ <?= fmtRand(abs($revDelta)) ?> vs last mo</span>
                                <?php else: ?>
                                <span class="fw-qi__kpi-delta fw-qi__kpi-delta--flat">— level with last mo</span>
                                <?php endif; ?>
                            </div>
                            <div class="fw-qi__kpi-value fw-qi__kpi-value--green" data-countup="<?= (int)round($mtdZar) ?>" data-prefix="R">R<?= number_format(round($mtdZar)) ?></div>
                            <div class="fw-qi__kpi-label">Revenue This Month</div>
                            <div class="fw-qi__kpi-foot">💵 <?= fmtRand($prevMonthZar) ?> collected last month</div>
                            <canvas class="fw-qi__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($revenueSeries)) ?>" data-spark-color="#10b981"></canvas>
                        </div>

                        <div class="fw-qi__kpi-card">
                            <div class="fw-qi__kpi-top">
                                <div class="fw-qi__kpi-icon fw-qi__kpi-icon--purple">📄</div>
                                <span class="fw-qi__kpi-delta fw-qi__kpi-delta--flat"><?= $openQuoteCount ?> open quote<?= $openQuoteCount === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="fw-qi__kpi-value fw-qi__kpi-value--purple" data-countup="<?= (int)round($openQuoteValue) ?>" data-prefix="R">R<?= number_format(round($openQuoteValue)) ?></div>
                            <div class="fw-qi__kpi-label">Open Quote Pipeline</div>
                            <div class="fw-qi__kpi-foot"><?= $acceptanceRate !== null ? '🎯 ' . $acceptanceRate . '% accepted · 12mo' : '💤 no quotes issued yet' ?></div>
                            <canvas class="fw-qi__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($quotedSeries)) ?>" data-spark-color="#8b5cf6"></canvas>
                        </div>
                    </div>

                    <!-- Secondary KPI strip -->
                    <div class="fw-qi__kpi-strip">
                        <div class="fw-qi__kpi">
                            <div class="fw-qi__kpi-num" data-countup="<?= $openQuoteCount ?>"><?= $openQuoteCount ?></div>
                            <div class="fw-qi__kpi-sub">Active quotes</div>
                        </div>
                        <div class="fw-qi__kpi">
                            <div class="fw-qi__kpi-num" data-countup="<?= $openInvCount ?>"><?= $openInvCount ?></div>
                            <div class="fw-qi__kpi-sub">Awaiting payment</div>
                        </div>
                        <div class="fw-qi__kpi">
                            <div class="fw-qi__kpi-num" data-countup="<?= (int)($quote['draft_cnt'] ?? 0) + $draftInvCount ?>"><?= (int)($quote['draft_cnt'] ?? 0) + $draftInvCount ?></div>
                            <div class="fw-qi__kpi-sub">Drafts</div>
                        </div>
                        <div class="fw-qi__kpi">
                            <div class="fw-qi__kpi-num" data-countup="<?= (int)($counts['recurring_count'] ?? 0) ?>"><?= (int)($counts['recurring_count'] ?? 0) ?></div>
                            <div class="fw-qi__kpi-sub">Active recurring</div>
                        </div>
                        <div class="fw-qi__kpi">
                            <div class="fw-qi__kpi-num" data-countup="<?= $creditPendingCount ?>"><?= $creditPendingCount ?></div>
                            <div class="fw-qi__kpi-sub">Credit notes pending</div>
                        </div>
                        <div class="fw-qi__kpi">
                            <div class="fw-qi__kpi-num"><?= $collectionRate !== null ? $collectionRate . '%' : '—' ?></div>
                            <div class="fw-qi__kpi-sub">Collection rate · 90d</div>
                        </div>
                    </div>

                    <!-- Charts: revenue + receivables aging -->
                    <div class="fw-qi__charts-grid">
                        <div class="fw-qi__chart-card">
                            <h3 class="fw-qi__chart-title">Revenue <small>collected · last 12 months</small></h3>
                            <div class="fw-qi__chart-body">
                                <canvas id="chartRevenue" height="220"></canvas>
                            </div>
                        </div>
                        <div class="fw-qi__chart-card">
                            <h3 class="fw-qi__chart-title">Receivables Aging <small><?= fmtRand($outstandingZar) ?> outstanding</small></h3>
                            <div class="fw-qi__funnel">
                                <?php foreach ($agingBuckets as $bucketKey => $bucket):
                                    $val = $bucket['val'];
                                    $cnt = $bucket['cnt'];
                                    $pct = max(4, (int)round(100 * $val / $maxAging));
                                ?>
                                <div class="fw-qi__funnel-row">
                                    <div class="fw-qi__funnel-label"><?= $bucket['label'] ?></div>
                                    <div class="fw-qi__funnel-track">
                                        <div class="fw-qi__funnel-fill fw-qi__funnel-fill--<?= $bucketKey ?>" style="width:<?= $val > 0 ? $pct : 0 ?>%"><?= $cnt > 0 ? $cnt : '' ?></div>
                                    </div>
                                    <div class="fw-qi__funnel-val"><?= fmtRand($val) ?><small><?= $cnt ?> invoice<?= $cnt === 1 ? '' : 's' ?></small></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="fw-qi__chart-body" style="margin-top:12px">
                                <canvas id="chartInvoiceStatus" height="140"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Charts: document volume + quote outcomes -->
                    <div class="fw-qi__charts-grid">
                        <div class="fw-qi__chart-card">
                            <h3 class="fw-qi__chart-title">Quotes vs Invoices <small>created · 6 months</small></h3>
                            <div class="fw-qi__chart-body">
                                <canvas id="chartVolume" height="200"></canvas>
                            </div>
                        </div>
                        <div class="fw-qi__chart-card">
                            <h3 class="fw-qi__chart-title">Quote Outcomes <small>last 12 months</small></h3>
                            <div class="fw-qi__chart-body">
                                <canvas id="chartQuoteOutcomes" height="200"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Top debtors + recent documents + quick actions -->
                    <div class="fw-qi__dash-grid-3">
                        <div class="fw-qi__table-card">
                            <h3 class="fw-qi__chart-title">Top Debtors <small>by outstanding balance</small></h3>
                            <div class="fw-qi__mini-list">
                                <?php if (empty($topDebtors)): ?>
                                    <div class="fw-qi__empty-note">No outstanding balances 🎉<small>Invoices awaiting payment will rank here</small></div>
                                <?php else: foreach ($topDebtors as $d): ?>
                                <a class="fw-qi__mini-row" href="<?= $d['id'] ? '/crm/account_view.php?id=' . (int)$d['id'] : '?tab=invoices' ?>">
                                    <div class="fw-qi__mini-avatar fw-qi__mini-avatar--customer"><?= htmlspecialchars(fwInitials($d['name'])) ?></div>
                                    <div class="fw-qi__mini-info">
                                        <div class="fw-qi__mini-name"><?= htmlspecialchars($d['name']) ?></div>
                                        <div class="fw-qi__mini-sub"><?= (int)$d['open_invoices'] ?> open invoice<?= (int)$d['open_invoices'] === 1 ? '' : 's' ?> · oldest due <?= date('d M', strtotime($d['oldest_due'])) ?></div>
                                    </div>
                                    <div class="fw-qi__mini-val"><?= fmtRand((float)$d['outstanding_zar']) ?></div>
                                </a>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                        <div class="fw-qi__table-card">
                            <h3 class="fw-qi__chart-title">Recent Documents <small>latest activity</small></h3>
                            <div class="fw-qi__mini-list">
                                <?php if (empty($recentDocs)): ?>
                                    <div class="fw-qi__empty-note">No documents yet<small>Create your first quote to get started</small></div>
                                <?php else: foreach ($recentDocs as $doc):
                                    $meta = $docMeta[$doc['doc_type']] ?? $docMeta['quote'];
                                ?>
                                <a class="fw-qi__mini-row" href="<?= $meta['href'] . (int)$doc['id'] ?>">
                                    <div class="fw-qi__mini-avatar"><?= $meta['icon'] ?></div>
                                    <div class="fw-qi__mini-info">
                                        <div class="fw-qi__mini-name"><?= htmlspecialchars($doc['doc_number']) ?> <span class="fw-qi__badge fw-qi__badge--<?= htmlspecialchars($doc['status']) ?>"><?= htmlspecialchars(ucfirst($doc['status'])) ?></span></div>
                                        <div class="fw-qi__mini-sub"><?= htmlspecialchars($doc['customer_name'] ?: 'No customer') ?></div>
                                    </div>
                                    <div class="fw-qi__mini-val"><?= date('d M', strtotime($doc['updated_at'])) ?></div>
                                </a>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                        <div class="fw-qi__table-card">
                            <h3 class="fw-qi__chart-title">Quick Actions</h3>
                            <div class="fw-qi__quick-actions">
                                <a href="/qi/quote_new.php" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">📄</span><span class="fw-qi__qa-label">New Quote</span></a>
                                <a href="/qi/invoice_new.php" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">🧾</span><span class="fw-qi__qa-label">New Invoice</span></a>
                                <a href="/qi/credit_note_new.php" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">📑</span><span class="fw-qi__qa-label">New Credit Note</span></a>
                                <a href="/qi/recurring.php" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">🔄</span><span class="fw-qi__qa-label">Recurring</span></a>
                                <a href="?tab=quotes" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">📋</span><span class="fw-qi__qa-label">View Quotes</span></a>
                                <a href="?tab=invoices" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">💳</span><span class="fw-qi__qa-label">View Invoices</span></a>
                                <a href="/qi/quote_new.php" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">📥</span><span class="fw-qi__qa-label">Import from Project</span></a>
                                <a href="/qi/settings.php" class="fw-qi__qa-tile"><span class="fw-qi__qa-icon">⚙️</span><span class="fw-qi__qa-label">Settings</span></a>
                            </div>
                        </div>
                    </div>

                    <!-- Overdue invoices + expiring quotes -->
                    <div class="fw-qi__tables-grid">
                        <div class="fw-qi__table-card">
                            <h3 class="fw-qi__chart-title">Overdue Invoices <small>most overdue first</small></h3>
                            <div class="fw-qi__table-scroll">
                                <table class="fw-qi__dash-table">
                                    <thead>
                                        <tr><th>Invoice</th><th>Customer</th><th>Overdue</th><th>Balance</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($overdueInvoices)): ?>
                                            <tr><td colspan="4" class="fw-qi__empty-note">No overdue invoices 🎉</td></tr>
                                        <?php else: foreach ($overdueInvoices as $inv): ?>
                                            <tr>
                                                <td><a href="/qi/invoice_view.php?id=<?= (int)$inv['id'] ?>"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                                                <td><?= htmlspecialchars($inv['customer_name'] ?: 'No customer') ?></td>
                                                <td><span class="fw-qi__due-pill fw-qi__due-pill--overdue"><?= (int)$inv['days_overdue'] ?>d overdue</span></td>
                                                <td><?= fmtRand((float)$inv['balance_zar']) ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="fw-qi__table-card">
                            <h3 class="fw-qi__chart-title">Quotes Expiring Soon <small>next 30 days</small></h3>
                            <div class="fw-qi__table-scroll">
                                <table class="fw-qi__dash-table">
                                    <thead>
                                        <tr><th>Quote</th><th>Customer</th><th>Expires</th><th>Value</th><th></th></tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($expiringQuotes)): ?>
                                            <tr><td colspan="5" class="fw-qi__empty-note">No quotes expiring — all clear 📄</td></tr>
                                        <?php else: foreach ($expiringQuotes as $q):
                                            $daysLeft = (int)$q['days_left'];
                                        ?>
                                            <tr>
                                                <td><a href="/qi/quote_view.php?id=<?= (int)$q['id'] ?>"><?= htmlspecialchars($q['quote_number']) ?></a></td>
                                                <td><?= htmlspecialchars($q['customer_name'] ?: 'No customer') ?></td>
                                                <td><?= date('d M Y', strtotime($q['expiry_date'])) ?></td>
                                                <td><?= fmtRand((float)$q['total_zar']) ?></td>
                                                <td><span class="fw-qi__due-pill fw-qi__due-pill--<?= $daysLeft <= 7 ? 'soon' : 'ok' ?>"><?= $daysLeft ?>d left</span></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            <?php else: ?>
                <div class="fw-qi__list" id="qiList">
                    <div class="fw-qi__loading">
                        <div class="fw-qi__spinner"></div>
                        <p>Loading <?= $activeTab ?>...</p>
                    </div>
                </div>
            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="fw-qi__footer">
            <span>Quotes & Invoices v<?= QI_ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script>
        const QIIndex = {
            activeTab: '<?= $activeTab ?>',

            createNew() {
                const routes = {
                    'quotes': '/qi/quote_new.php',
                    'invoices': '/qi/invoice_new.php',
                    'recurring': '/qi/recurring.php',
                    'credit_notes': '/qi/credit_note_new.php'
                };
                window.location.href = routes[this.activeTab] || '/qi/quote_new.php';
            }
        };
    </script>
    <script>
        // Currency symbols for list/dashboard rows (foreign-currency documents)
        window.QI_CURRENCY_SYMBOLS = <?= json_encode(Currencies::symbolMap()) ?>;
    </script>
    <script src="/qi/assets/qi.js?v=<?= QI_ASSET_VERSION ?>"></script>
    <?php if ($activeTab === 'overview'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (function() {
      'use strict';

      const MONTH_LABELS_12 = <?= json_encode($monthLabels12, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const MONTH_LABELS_6 = <?= json_encode($monthLabels6, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const REVENUE = <?= json_encode($revenueSeries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const INVOICE_STATUS = <?= json_encode($invoiceStatusSplit, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const QUOTE_OUTCOMES = <?= json_encode($quoteOutcomes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const QUOTE_VOLUME = <?= json_encode($quoteVolumeSeries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const INVOICE_VOLUME = <?= json_encode($invoiceVolumeSeries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      const STATUS_COLORS = { sent: '#3b82f6', viewed: '#8b5cf6', 'part-paid': '#f59e0b', overdue: '#ef4444' };
      const OUTCOME_COLORS = { accepted: '#10b981', declined: '#ef4444', expired: '#64748b', pending: '#fbbf24' };

      window.chartInstances = window.chartInstances || {};

      function themePalette() {
        const isDark = document.querySelector('.fw-qi').getAttribute('data-theme') === 'dark';
        return {
          isDark: isDark,
          gold: isDark ? '#fbbf24' : '#d4a017',
          grid: isDark ? 'rgba(255,255,255,.06)' : 'rgba(21,39,50,.06)',
          tick: isDark ? '#9cb4c0' : '#64737d',
          tooltipBg: isDark ? 'rgba(10,20,28,.95)' : 'rgba(255,255,255,.96)',
          tooltipText: isDark ? '#e7f0f4' : '#16212b',
          border: isDark ? 'rgba(255,255,255,.1)' : 'rgba(21,39,50,.1)'
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
        if (v >= 1000000) return 'R' + (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000) return 'R' + (v / 1000).toFixed(0) + 'k';
        return 'R' + v;
      }

      function capitalize(s) {
        return s.charAt(0).toUpperCase() + s.slice(1);
      }

      function destroyCharts() {
        ['revenue', 'invoiceStatus', 'volume', 'quoteOutcomes'].forEach(function(k) {
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

        // Revenue collected — glossy gold gradient bars
        const revenueCanvas = document.getElementById('chartRevenue');
        if (revenueCanvas) {
          window.chartInstances.revenue = new Chart(revenueCanvas, {
            type: 'bar',
            data: {
              labels: MONTH_LABELS_12,
              datasets: [{
                label: 'Collected',
                data: REVENUE,
                borderRadius: 8,
                borderSkipped: false,
                maxBarThickness: 34,
                backgroundColor: function(c) {
                  const area = c.chart.chartArea;
                  return area ? vGradient(c.chart.ctx, area, p.gold) : p.gold;
                }
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              plugins: {
                legend: { display: false },
                tooltip: Object.assign(glossTooltip(p), {
                  callbacks: { label: ctx => ' R' + ctx.parsed.y.toLocaleString() }
                })
              },
              scales: {
                x: { grid: { display: false }, ticks: { color: p.tick } },
                y: { grid: { color: p.grid }, ticks: { color: p.tick, callback: randTick }, border: { display: false } }
              }
            }
          });
        }

        // Outstanding split by status — glowing doughnut
        const statusCanvas = document.getElementById('chartInvoiceStatus');
        if (statusCanvas) {
          const entries = Object.entries(INVOICE_STATUS);
          const hasData = entries.length > 0;
          window.chartInstances.invoiceStatus = new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
              labels: hasData ? entries.map(e => capitalize(e[0])) : ['Nothing outstanding'],
              datasets: [{
                data: hasData ? entries.map(e => parseFloat(e[1])) : [1],
                backgroundColor: hasData ? entries.map(e => STATUS_COLORS[e[0]] || '#64748b') : [p.isDark ? '#1e293b' : '#e2e8f0'],
                borderWidth: 2,
                borderColor: p.isDark ? 'rgba(11,19,30,.9)' : '#ffffff',
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
                  callbacks: { label: ctx => ' ' + ctx.label + ': R' + ctx.parsed.toLocaleString() }
                })
              }
            }
          });
        }

        // Quotes vs invoices created — neon lines
        const volumeCanvas = document.getElementById('chartVolume');
        if (volumeCanvas) {
          window.chartInstances.volume = new Chart(volumeCanvas, {
            type: 'line',
            data: {
              labels: MONTH_LABELS_6,
              datasets: [
                {
                  label: 'Quotes',
                  data: QUOTE_VOLUME,
                  borderColor: p.gold,
                  borderWidth: 3,
                  pointBackgroundColor: p.gold,
                  pointBorderColor: p.isDark ? '#0b131b' : '#ffffff',
                  pointBorderWidth: 2,
                  pointRadius: 4,
                  pointHoverRadius: 6,
                  tension: 0.42,
                  fill: true,
                  backgroundColor: function(c) {
                    const area = c.chart.chartArea;
                    if (!area) return p.gold + '26';
                    const g = c.chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
                    g.addColorStop(0, p.gold + '00');
                    g.addColorStop(1, p.gold + '59');
                    return g;
                  }
                },
                {
                  label: 'Invoices',
                  data: INVOICE_VOLUME,
                  borderColor: '#3b82f6',
                  borderWidth: 3,
                  pointBackgroundColor: '#3b82f6',
                  pointBorderColor: p.isDark ? '#0b131b' : '#ffffff',
                  pointBorderWidth: 2,
                  pointRadius: 4,
                  pointHoverRadius: 6,
                  tension: 0.42,
                  fill: false
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              plugins: {
                legend: { position: 'bottom', labels: { color: p.tick, usePointStyle: true, pointStyle: 'circle', padding: 16 } },
                tooltip: glossTooltip(p)
              },
              scales: {
                x: { grid: { display: false }, ticks: { color: p.tick } },
                y: { grid: { color: p.grid }, ticks: { color: p.tick, stepSize: 1 }, border: { display: false } }
              }
            }
          });
        }

        // Quote outcomes — doughnut
        const outcomesCanvas = document.getElementById('chartQuoteOutcomes');
        if (outcomesCanvas) {
          const entries = Object.entries(QUOTE_OUTCOMES);
          const hasData = entries.length > 0;
          window.chartInstances.quoteOutcomes = new Chart(outcomesCanvas, {
            type: 'doughnut',
            data: {
              labels: hasData ? entries.map(e => capitalize(e[0])) : ['No quotes yet'],
              datasets: [{
                data: hasData ? entries.map(e => parseInt(e[1], 10)) : [1],
                backgroundColor: hasData ? entries.map(e => OUTCOME_COLORS[e[0]] || '#64748b') : [p.isDark ? '#1e293b' : '#e2e8f0'],
                borderWidth: 2,
                borderColor: p.isDark ? 'rgba(11,19,30,.9)' : '#ffffff',
                hoverOffset: 8
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              cutout: '68%',
              plugins: {
                legend: { position: 'right', labels: { color: p.tick, usePointStyle: true, pointStyle: 'circle', padding: 12, boxWidth: 8 } },
                tooltip: glossTooltip(p)
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

      // Re-skin charts when the theme flips (qi.js dispatches qi:theme)
      document.addEventListener('qi:theme', buildCharts);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
