<?php
// /crm/index.php - COMPLETE WITH OVERVIEW TAB
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

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

// Get active tab from URL
$activeTab = $_GET['tab'] ?? 'overview';

// Lookup lists for the list-tab filters (global tables)
$filterIndustries = [];
$filterRegions = [];
if ($activeTab === 'suppliers' || $activeTab === 'customers') {
    $filterIndustries = $DB->query("SELECT id, name FROM crm_industries ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $filterRegions = $DB->query("SELECT id, name FROM crm_regions ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Always fetch tab counts (used in tab headers on every tab) - exclude soft-deleted
$suppliersCount = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL");
$suppliersCount->execute([$companyId]);
$totalSuppliers = $suppliersCount->fetchColumn();

$customersCount = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'customer' AND deleted_at IS NULL");
$customersCount->execute([$companyId]);
$totalCustomers = $customersCount->fetchColumn();

// Fetch statistics for the overview command deck.
// Column caveats respected throughout: only crm_accounts has deleted_at;
// 'converted' only ever follows 'won' so it counts as a win; compliance
// health is computed live from expiry_date (stored status goes stale);
// stage_changed_at is NULL for opps never moved since the 2026-07-04
// migration, so close-time KPIs COALESCE it with created_at.
if ($activeTab === 'overview') {
    // --- Account KPIs (one pass) ---
    $acctKpi = $DB->prepare("
        SELECT
            SUM(type = 'supplier') AS suppliers,
            SUM(type = 'customer') AS customers,
            SUM(status = 'prospect') AS prospects,
            SUM(status = 'active') AS active_accounts,
            SUM(preferred = 1) AS preferred_accounts,
            SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS new_this_month,
            SUM(created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS new_last_month
        FROM crm_accounts
        WHERE company_id = ? AND deleted_at IS NULL
    ");
    $acctKpi->execute([$companyId]);
    $acct = $acctKpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalAccounts = (int)($totalSuppliers ?? 0) + (int)($totalCustomers ?? 0);

    $contactsCount = $DB->prepare("SELECT COUNT(*) FROM crm_contacts WHERE company_id = ?");
    $contactsCount->execute([$companyId]);
    $totalContacts = (int)$contactsCount->fetchColumn();

    $interactions30 = $DB->prepare("
        SELECT COUNT(*) FROM crm_interactions
        WHERE company_id = ? AND created_at >= CURDATE() - INTERVAL 30 DAY
    ");
    $interactions30->execute([$companyId]);
    $totalInteractions30 = (int)$interactions30->fetchColumn();

    // --- Open pipeline by stage (funnel) ---
    $pipelineStmt = $DB->prepare("
        SELECT stage, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
        FROM crm_opportunities
        WHERE company_id = ? AND stage NOT IN ('won', 'lost', 'converted')
        GROUP BY stage
        ORDER BY FIELD(stage, 'prospect', 'qualification', 'proposal', 'negotiation')
    ");
    $pipelineStmt->execute([$companyId]);
    $pipelineByStage = $pipelineStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalPipelineValue = array_sum(array_map('floatval', array_column($pipelineByStage, 'total')));
    $totalOpenDeals = array_sum(array_map('intval', array_column($pipelineByStage, 'cnt')));

    // --- Win/loss, weighted pipeline, deal economics (one pass) ---
    $oppKpi = $DB->prepare("
        SELECT
            SUM(stage IN ('won', 'converted')) AS won_cnt,
            SUM(stage = 'lost') AS lost_cnt,
            COALESCE(SUM(CASE WHEN stage IN ('won', 'converted') THEN amount END), 0) AS won_value,
            COALESCE(SUM(CASE WHEN stage = 'lost' THEN amount END), 0) AS lost_value,
            ROUND(AVG(CASE WHEN stage IN ('won', 'converted') THEN amount END), 2) AS avg_won_deal,
            COALESCE(SUM(CASE WHEN stage NOT IN ('won', 'lost', 'converted')
                              THEN amount * COALESCE(probability, 0) / 100 END), 0) AS weighted_pipeline,
            COALESCE(SUM(CASE WHEN COALESCE(stage_changed_at, created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                               AND stage IN ('won', 'converted') THEN amount END), 0) AS won_this_month,
            COALESCE(SUM(CASE WHEN COALESCE(stage_changed_at, created_at) >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                               AND COALESCE(stage_changed_at, created_at) < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                               AND stage IN ('won', 'converted') THEN amount END), 0) AS won_last_month
        FROM crm_opportunities
        WHERE company_id = ?
    ");
    $oppKpi->execute([$companyId]);
    $opp = $oppKpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $wonCnt = (int)($opp['won_cnt'] ?? 0);
    $lostCnt = (int)($opp['lost_cnt'] ?? 0);
    $winRate = ($wonCnt + $lostCnt) > 0 ? round(100 * $wonCnt / ($wonCnt + $lostCnt), 1) : null;
    $totalWonValue = (float)($opp['won_value'] ?? 0);

    // --- Deals closing this month + overdue close dates (open only) ---
    $closingStmt = $DB->prepare("
        SELECT
            SUM(close_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())) AS closing_cnt,
            COALESCE(SUM(CASE WHEN close_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
                              THEN amount END), 0) AS closing_value,
            SUM(close_date < CURDATE()) AS overdue_deals
        FROM crm_opportunities
        WHERE company_id = ? AND stage NOT IN ('won', 'lost', 'converted') AND close_date IS NOT NULL
    ");
    $closingStmt->execute([$companyId]);
    $closing = $closingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // --- 6-month scaffold for charts + sparklines ---
    $monthKeys = [];
    $monthLabels = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("first day of -{$i} months");
        $monthKeys[date('Y-m', $ts)] = count($monthKeys);
        $monthLabels[] = date('M', $ts);
    }
    $rangeStart = array_key_first($monthKeys) . '-01';
    $zeroSeries = array_fill(0, count($monthKeys), 0);

    $growthSuppliers = $zeroSeries;
    $growthCustomers = $zeroSeries;
    $monthlyGrowth = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
               SUM(type = 'supplier') AS suppliers,
               SUM(type = 'customer') AS customers
        FROM crm_accounts
        WHERE company_id = ? AND deleted_at IS NULL AND created_at >= ?
        GROUP BY ym
    ");
    $monthlyGrowth->execute([$companyId, $rangeStart]);
    foreach ($monthlyGrowth->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys[$row['ym']])) {
            $growthSuppliers[$monthKeys[$row['ym']]] = (int)$row['suppliers'];
            $growthCustomers[$monthKeys[$row['ym']]] = (int)$row['customers'];
        }
    }

    $interactionSeries = $zeroSeries;
    $monthlyInteractions = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
        FROM crm_interactions
        WHERE company_id = ? AND created_at >= ?
        GROUP BY ym
    ");
    $monthlyInteractions->execute([$companyId, $rangeStart]);
    foreach ($monthlyInteractions->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys[$row['ym']])) {
            $interactionSeries[$monthKeys[$row['ym']]] = (int)$row['cnt'];
        }
    }

    $newOppSeries = $zeroSeries;
    $wonSeries = $zeroSeries;
    $monthlyOpps = $DB->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS created_ym,
               DATE_FORMAT(COALESCE(stage_changed_at, created_at), '%Y-%m') AS closed_ym,
               stage, amount
        FROM crm_opportunities
        WHERE company_id = ?
          AND (created_at >= ? OR COALESCE(stage_changed_at, created_at) >= ?)
    ");
    $monthlyOpps->execute([$companyId, $rangeStart, $rangeStart]);
    foreach ($monthlyOpps->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($monthKeys[$row['created_ym']])) {
            $newOppSeries[$monthKeys[$row['created_ym']]] += (float)$row['amount'];
        }
        if (in_array($row['stage'], ['won', 'converted'], true) && isset($monthKeys[$row['closed_ym']])) {
            $wonSeries[$monthKeys[$row['closed_ym']]] += (float)$row['amount'];
        }
    }

    // --- Interaction mix (last 30 days) ---
    $mixStmt = $DB->prepare("
        SELECT type, COUNT(*) AS cnt
        FROM crm_interactions
        WHERE company_id = ? AND created_at >= CURDATE() - INTERVAL 30 DAY
        GROUP BY type
    ");
    $mixStmt->execute([$companyId]);
    $interactionMix = $mixStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $mixTotal = array_sum($interactionMix);

    // --- Status breakdown (live accounts only) ---
    $statusBreakdown = $DB->prepare("
        SELECT status, COUNT(*) AS count
        FROM crm_accounts
        WHERE company_id = ? AND deleted_at IS NULL
        GROUP BY status
    ");
    $statusBreakdown->execute([$companyId]);
    $statuses = $statusBreakdown->fetchAll(PDO::FETCH_KEY_PAIR);

    // --- Follow-ups from interactions (overdue + next 7 days) ---
    $followupsStmt = $DB->prepare("
        SELECT i.id, i.subject, i.type, i.next_action_at,
               a.id AS account_id, a.name AS account_name
        FROM crm_interactions i
        JOIN crm_accounts a ON a.id = i.account_id
        WHERE i.company_id = ? AND a.deleted_at IS NULL
          AND i.next_action_at IS NOT NULL
          AND i.next_action_at < NOW() + INTERVAL 7 DAY
        ORDER BY i.next_action_at ASC
        LIMIT 9
    ");
    $followupsStmt->execute([$companyId]);
    $followups = $followupsStmt->fetchAll(PDO::FETCH_ASSOC);
    $overdueFollowups = 0;
    foreach ($followups as $f) {
        if (strtotime($f['next_action_at']) < time()) $overdueFollowups++;
    }

    // --- Compliance health (live from expiry_date) ---
    $compStmt = $DB->prepare("
        SELECT
            COUNT(*) AS total_docs,
            SUM(expiry_date IS NOT NULL AND expiry_date >= CURDATE()
                AND expiry_date <= CURDATE() + INTERVAL 30 DAY) AS expiring_docs,
            SUM(expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired_docs
        FROM crm_compliance_docs
        WHERE company_id = ?
    ");
    $compStmt->execute([$companyId]);
    $comp = $compStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalDocs = (int)($comp['total_docs'] ?? 0);
    $expiredDocs = (int)($comp['expired_docs'] ?? 0);
    $expiringDocsCnt = (int)($comp['expiring_docs'] ?? 0);
    $complianceHealth = $totalDocs > 0 ? round(100 * ($totalDocs - $expiredDocs) / $totalDocs) : null;

    // --- Expiring compliance docs (next 30 days, expiry-driven) ---
    $expiringDocs = $DB->prepare("
        SELECT cd.id, cd.expiry_date, ct.name AS doc_type,
               a.id AS account_id, a.name AS account_name, a.type AS account_type
        FROM crm_compliance_docs cd
        JOIN crm_compliance_types ct ON ct.id = cd.type_id
        JOIN crm_accounts a ON a.id = cd.account_id
        WHERE cd.company_id = ? AND a.deleted_at IS NULL
          AND cd.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
        ORDER BY cd.expiry_date ASC
        LIMIT 8
    ");
    $expiringDocs->execute([$companyId]);
    $expiringCompliance = $expiringDocs->fetchAll(PDO::FETCH_ASSOC);

    // --- Top suppliers / customers (preferred first) ---
    $topSuppliers = $DB->prepare("
        SELECT id, name, phone, email, preferred
        FROM crm_accounts
        WHERE company_id = ? AND type = 'supplier' AND status = 'active' AND deleted_at IS NULL
        ORDER BY preferred DESC, name ASC
        LIMIT 5
    ");
    $topSuppliers->execute([$companyId]);
    $preferredSuppliers = $topSuppliers->fetchAll(PDO::FETCH_ASSOC);

    $topCustomers = $DB->prepare("
        SELECT id, name, phone, email, preferred
        FROM crm_accounts
        WHERE company_id = ? AND type = 'customer' AND status = 'active' AND deleted_at IS NULL
        ORDER BY preferred DESC, name ASC
        LIMIT 5
    ");
    $topCustomers->execute([$companyId]);
    $topCustomerList = $topCustomers->fetchAll(PDO::FETCH_ASSOC);

    // --- Top accounts by open opportunity value ---
    $topOpenStmt = $DB->prepare("
        SELECT a.id, a.name, a.type, COUNT(o.id) AS open_opps, COALESCE(SUM(o.amount), 0) AS open_value
        FROM crm_accounts a
        JOIN crm_opportunities o ON o.account_id = a.id AND o.company_id = a.company_id
             AND o.stage NOT IN ('won', 'lost', 'converted')
        WHERE a.company_id = ? AND a.deleted_at IS NULL
        GROUP BY a.id, a.name, a.type
        ORDER BY open_value DESC
        LIMIT 5
    ");
    $topOpenStmt->execute([$companyId]);
    $topOpenAccounts = $topOpenStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Deals needing attention (longest stuck in stage) ---
    $staleStmt = $DB->prepare("
        SELECT o.id, o.title, o.stage, o.amount, a.id AS account_id, a.name AS account_name,
               DATEDIFF(CURDATE(), COALESCE(o.stage_changed_at, o.created_at)) AS days_in_stage
        FROM crm_opportunities o
        JOIN crm_accounts a ON a.id = o.account_id
        WHERE o.company_id = ? AND o.stage NOT IN ('won', 'lost', 'converted')
        ORDER BY days_in_stage DESC
        LIMIT 6
    ");
    $staleStmt->execute([$companyId]);
    $staleDeals = $staleStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Pipeline by owner (leaderboard) ---
    $ownerStmt = $DB->prepare("
        SELECT u.first_name, u.last_name,
               SUM(o.stage NOT IN ('won', 'lost', 'converted')) AS open_cnt,
               COALESCE(SUM(CASE WHEN o.stage NOT IN ('won', 'lost', 'converted') THEN o.amount END), 0) AS open_value,
               SUM(o.stage IN ('won', 'converted')) AS won_cnt
        FROM crm_opportunities o
        JOIN users u ON u.id = o.owner_id
        WHERE o.company_id = ?
        GROUP BY o.owner_id, u.first_name, u.last_name
        ORDER BY open_value DESC
        LIMIT 5
    ");
    $ownerStmt->execute([$companyId]);
    $ownerBoard = $ownerStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Recent interactions (activity feed) ---
    $recentInteractions = $DB->prepare("
        SELECT i.type, i.subject, i.created_at,
               a.name AS account_name, a.id AS account_id
        FROM crm_interactions i
        JOIN crm_accounts a ON a.id = i.account_id
        WHERE i.company_id = ?
        ORDER BY i.created_at DESC
        LIMIT 8
    ");
    $recentInteractions->execute([$companyId]);
    $latestInteractions = $recentInteractions->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= CRM_ASSET_VERSION ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="fw-crm">
    <div class="fw-crm__container">
        
        <!-- Header -->
        <header class="fw-crm__header">
            <div class="fw-crm__brand">
                <div class="fw-crm__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-crm__brand-text">
                    <div class="fw-crm__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-crm__app-name">CRM</div>
                </div>
            </div>

            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-crm__controls">
                <a href="/" class="fw-crm__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <button class="fw-crm__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-crm__menu-wrapper">
                    <button class="fw-crm__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-crm__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/crm/settings.php" class="fw-crm__kebab-item">CRM Settings</a>
                        <a href="/crm/import.php" class="fw-crm__kebab-item">Import/Export</a>
                        <a href="/crm/dedupe.php" class="fw-crm__kebab-item">Dedupe & Merge</a>
                        <a href="/crm/compliance.php" class="fw-crm__kebab-item">Compliance</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">Supplier & Customer Relationship Management</h1>
                <p class="fw-crm__page-subtitle">
                    Manage your suppliers, customers, and relationships
                </p>
            </div>

            <!-- Tabs -->
            <div class="fw-crm__view-tabs">
                <a href="/crm/?tab=overview" class="fw-crm__view-tab <?= $activeTab === 'overview' ? 'fw-crm__view-tab--active' : '' ?>">
                    📊 Overview
                </a>
                <a href="/crm/?tab=suppliers" class="fw-crm__view-tab <?= $activeTab === 'suppliers' ? 'fw-crm__view-tab--active' : '' ?>">
                    Suppliers (<?= $totalSuppliers ?? 0 ?>)
                </a>
                <a href="/crm/?tab=customers" class="fw-crm__view-tab <?= $activeTab === 'customers' ? 'fw-crm__view-tab--active' : '' ?>">
                    Customers (<?= $totalCustomers ?? 0 ?>)
                </a>
                <a href="/crm/opps_list.php" class="fw-crm__view-tab">
                    Pipeline
                </a>
            </div>

            <!-- Tab Content -->
            <div class="fw-crm__view-content">
                
                <?php if ($activeTab === 'overview'): ?>
                <!-- OVERVIEW — COMMAND DECK (all real data) -->
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
                    $newThisMonth = (int)($acct['new_this_month'] ?? 0);
                    $newLastMonth = (int)($acct['new_last_month'] ?? 0);
                    $acctDelta = $newThisMonth - $newLastMonth;
                    $wonThisMonth = (float)($opp['won_this_month'] ?? 0);
                    $wonLastMonth = (float)($opp['won_last_month'] ?? 0);
                    $maxStageTotal = max(1, max(array_map('floatval', array_column($pipelineByStage, 'total')) ?: [1]));
                    $stageMeta = [
                        'prospect'      => ['label' => 'Prospect'],
                        'qualification' => ['label' => 'Qualifying'],
                        'proposal'      => ['label' => 'Proposal'],
                        'negotiation'   => ['label' => 'Negotiation'],
                    ];
                    $mixMeta = [
                        'email' => '📧', 'call' => '📞', 'visit' => '🏭', 'note' => '📝', 'issue' => '⚠️',
                    ];
                ?>

                <!-- Hero: greeting + alert chips -->
                <div class="fw-crm__dash-hero">
                    <div>
                        <div class="fw-crm__dash-hello"><?= $dayGreeting ?>, <?= htmlspecialchars($firstName) ?> 👋</div>
                        <div class="fw-crm__dash-date"><?= date('l, j F Y') ?> · <?= $totalAccounts ?> accounts · <?= $totalContacts ?> contacts</div>
                    </div>
                    <div class="fw-crm__dash-chips">
                        <?php if ($overdueFollowups > 0): ?>
                        <span class="fw-crm__dash-chip fw-crm__dash-chip--danger">⏰ <?= $overdueFollowups ?> overdue follow-up<?= $overdueFollowups === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                        <?php if ((int)($closing['overdue_deals'] ?? 0) > 0): ?>
                        <a href="/crm/opps_list.php" class="fw-crm__dash-chip fw-crm__dash-chip--alert">📅 <?= (int)$closing['overdue_deals'] ?> deal<?= (int)$closing['overdue_deals'] === 1 ? '' : 's' ?> past close date</a>
                        <?php endif; ?>
                        <?php if ($expiredDocs > 0): ?>
                        <a href="/crm/compliance.php" class="fw-crm__dash-chip fw-crm__dash-chip--danger">🛡️ <?= $expiredDocs ?> expired doc<?= $expiredDocs === 1 ? '' : 's' ?></a>
                        <?php elseif ($expiringDocsCnt > 0): ?>
                        <a href="/crm/compliance.php" class="fw-crm__dash-chip fw-crm__dash-chip--alert">🛡️ <?= $expiringDocsCnt ?> doc<?= $expiringDocsCnt === 1 ? '' : 's' ?> expiring soon</a>
                        <?php endif; ?>
                        <a href="/crm/opp_new.php" class="fw-crm__dash-chip">➕ New deal</a>
                    </div>
                </div>

                <!-- Hero KPI slabs -->
                <div class="fw-crm__kpi-grid">
                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-top">
                            <div class="fw-crm__kpi-icon">🏭</div>
                            <?php if ($acctDelta !== 0): ?>
                            <span class="fw-crm__kpi-delta fw-crm__kpi-delta--<?= $acctDelta > 0 ? 'up' : 'down' ?>"><?= $acctDelta > 0 ? '▲' : '▼' ?> <?= abs($acctDelta) ?> vs last mo</span>
                            <?php else: ?>
                            <span class="fw-crm__kpi-delta fw-crm__kpi-delta--flat">— steady</span>
                            <?php endif; ?>
                        </div>
                        <div class="fw-crm__kpi-value" data-countup="<?= (int)$totalSuppliers ?>"><?= (int)$totalSuppliers ?></div>
                        <div class="fw-crm__kpi-label">Suppliers</div>
                        <canvas class="fw-crm__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($growthSuppliers)) ?>" data-spark-color="#06b6d4"></canvas>
                    </div>

                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-top">
                            <div class="fw-crm__kpi-icon fw-crm__kpi-icon--green">🤝</div>
                            <span class="fw-crm__kpi-delta <?= $newThisMonth > 0 ? 'fw-crm__kpi-delta--up' : 'fw-crm__kpi-delta--flat' ?>"><?= $newThisMonth > 0 ? '▲ ' . $newThisMonth . ' new this mo' : '— none this mo' ?></span>
                        </div>
                        <div class="fw-crm__kpi-value fw-crm__kpi-value--green" data-countup="<?= (int)$totalCustomers ?>"><?= (int)$totalCustomers ?></div>
                        <div class="fw-crm__kpi-label">Customers</div>
                        <canvas class="fw-crm__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($growthCustomers)) ?>" data-spark-color="#10b981"></canvas>
                    </div>

                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-top">
                            <div class="fw-crm__kpi-icon fw-crm__kpi-icon--purple">🚀</div>
                            <span class="fw-crm__kpi-delta fw-crm__kpi-delta--flat"><?= $totalOpenDeals ?> open deal<?= $totalOpenDeals === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="fw-crm__kpi-value fw-crm__kpi-value--purple"><?= fmtRand($totalPipelineValue) ?></div>
                        <div class="fw-crm__kpi-label">Open Pipeline</div>
                        <div class="fw-crm__kpi-foot">⚖️ <?= fmtRand((float)($opp['weighted_pipeline'] ?? 0)) ?> weighted</div>
                        <canvas class="fw-crm__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($newOppSeries)) ?>" data-spark-color="#8b5cf6"></canvas>
                    </div>

                    <div class="fw-crm__kpi-card">
                        <div class="fw-crm__kpi-top">
                            <div class="fw-crm__kpi-icon fw-crm__kpi-icon--orange">🏆</div>
                            <?php if ($winRate !== null): ?>
                            <span class="fw-crm__kpi-delta <?= $winRate >= 50 ? 'fw-crm__kpi-delta--up' : 'fw-crm__kpi-delta--down' ?>"><?= $winRate ?>% win rate</span>
                            <?php else: ?>
                            <span class="fw-crm__kpi-delta fw-crm__kpi-delta--flat">no closed deals yet</span>
                            <?php endif; ?>
                        </div>
                        <div class="fw-crm__kpi-value fw-crm__kpi-value--orange"><?= fmtRand($totalWonValue) ?></div>
                        <div class="fw-crm__kpi-label">Won Deals</div>
                        <div class="fw-crm__kpi-foot">
                            <?= $wonThisMonth > 0 ? '🔥 ' . fmtRand($wonThisMonth) . ' this month' : ($opp['avg_won_deal'] ? '💎 ' . fmtRand((float)$opp['avg_won_deal']) . ' avg deal' : '💤 nothing closed yet') ?>
                        </div>
                        <canvas class="fw-crm__kpi-spark" data-spark="<?= htmlspecialchars(json_encode($wonSeries)) ?>" data-spark-color="#f59e0b"></canvas>
                    </div>
                </div>

                <!-- Secondary KPI strip -->
                <div class="fw-crm__kpi-strip">
                    <div class="fw-crm__kpi">
                        <div class="fw-crm__kpi-num" data-countup="<?= (int)($acct['prospects'] ?? 0) ?>"><?= (int)($acct['prospects'] ?? 0) ?></div>
                        <div class="fw-crm__kpi-sub">Prospects</div>
                    </div>
                    <div class="fw-crm__kpi">
                        <div class="fw-crm__kpi-num" data-countup="<?= (int)($acct['preferred_accounts'] ?? 0) ?>"><?= (int)($acct['preferred_accounts'] ?? 0) ?></div>
                        <div class="fw-crm__kpi-sub">Preferred</div>
                    </div>
                    <div class="fw-crm__kpi">
                        <div class="fw-crm__kpi-num" data-countup="<?= $totalInteractions30 ?>"><?= $totalInteractions30 ?></div>
                        <div class="fw-crm__kpi-sub">Touches · 30d</div>
                    </div>
                    <div class="fw-crm__kpi">
                        <div class="fw-crm__kpi-num"><?= (int)($closing['closing_cnt'] ?? 0) ?> · <?= fmtRand((float)($closing['closing_value'] ?? 0)) ?></div>
                        <div class="fw-crm__kpi-sub">Closing this month</div>
                    </div>
                    <div class="fw-crm__kpi">
                        <div class="fw-crm__kpi-num"><?= $winRate !== null ? $winRate . '%' : '—' ?></div>
                        <div class="fw-crm__kpi-sub">Win rate</div>
                    </div>
                    <div class="fw-crm__kpi">
                        <div class="fw-crm__kpi-num"><?= $complianceHealth !== null ? $complianceHealth . '%' : '—' ?></div>
                        <div class="fw-crm__kpi-sub">Compliance health</div>
                    </div>
                </div>

                <!-- Charts: growth + funnel -->
                <div class="fw-crm__charts-grid">
                    <div class="fw-crm__chart-card">
                        <h3 class="fw-crm__chart-title">Account Growth <small>last 6 months</small></h3>
                        <div class="fw-crm__chart-body">
                            <canvas id="chartGrowth" height="220"></canvas>
                        </div>
                    </div>
                    <div class="fw-crm__chart-card">
                        <h3 class="fw-crm__chart-title">Pipeline Funnel <small><?= fmtRand($totalPipelineValue) ?> open</small></h3>
                        <div class="fw-crm__funnel">
                            <?php foreach ($stageMeta as $stageKey => $metaRow):
                                $row = null;
                                foreach ($pipelineByStage as $p) { if ($p['stage'] === $stageKey) { $row = $p; break; } }
                                $val = (float)($row['total'] ?? 0);
                                $cnt = (int)($row['cnt'] ?? 0);
                                $pct = max(4, round(100 * $val / $maxStageTotal));
                            ?>
                            <div class="fw-crm__funnel-row">
                                <div class="fw-crm__funnel-label"><?= $metaRow['label'] ?></div>
                                <div class="fw-crm__funnel-track">
                                    <div class="fw-crm__funnel-fill fw-crm__funnel-fill--<?= $stageKey ?>" style="width:<?= $val > 0 ? $pct : 0 ?>%"><?= $cnt > 0 ? $cnt : '' ?></div>
                                </div>
                                <div class="fw-crm__funnel-val"><?= fmtRand($val) ?><small><?= $cnt ?> deal<?= $cnt === 1 ? '' : 's' ?></small></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="fw-crm__chart-body" style="margin-top:12px">
                            <canvas id="chartPipeline" height="140"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Charts: engagement + status -->
                <div class="fw-crm__charts-grid">
                    <div class="fw-crm__chart-card">
                        <h3 class="fw-crm__chart-title">Engagement <small>interactions · 6 months</small></h3>
                        <div class="fw-crm__chart-body">
                            <canvas id="chartEngagement" height="200"></canvas>
                        </div>
                        <?php if ($mixTotal > 0): ?>
                        <div class="fw-crm__mix-bar">
                            <?php foreach ($mixMeta as $mixType => $emoji):
                                $cnt = (int)($interactionMix[$mixType] ?? 0);
                                if ($cnt === 0) continue;
                            ?>
                            <div class="fw-crm__mix-seg fw-crm__mix-seg--<?= $mixType ?>" style="width:<?= round(100 * $cnt / $mixTotal, 1) ?>%" title="<?= ucfirst($mixType) ?>: <?= $cnt ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="fw-crm__mix-legend">
                            <?php foreach ($mixMeta as $mixType => $emoji):
                                $cnt = (int)($interactionMix[$mixType] ?? 0);
                                if ($cnt === 0) continue;
                            ?>
                            <span class="fw-crm__mix-legend-item"><span class="fw-crm__mix-dot fw-crm__mix-dot--<?= $mixType ?>"></span><?= ucfirst($mixType) ?> · <?= $cnt ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="fw-crm__chart-card">
                        <h3 class="fw-crm__chart-title">Account Status <small><?= $totalAccounts ?> live accounts</small></h3>
                        <div class="fw-crm__chart-body">
                            <canvas id="chartStatus" height="200"></canvas>
                        </div>
                        <div style="margin-top:14px">
                            <div class="fw-crm__meter">
                                <div class="fw-crm__meter-head"><span class="fw-crm__meter-name">Active accounts</span><span class="fw-crm__meter-pct"><?= $totalAccounts > 0 ? round(100 * (int)($acct['active_accounts'] ?? 0) / $totalAccounts) : 0 ?>%</span></div>
                                <div class="fw-crm__meter-track"><div class="fw-crm__meter-fill fw-crm__meter-fill--good" style="width:<?= $totalAccounts > 0 ? round(100 * (int)($acct['active_accounts'] ?? 0) / $totalAccounts) : 0 ?>%"></div></div>
                            </div>
                            <div class="fw-crm__meter">
                                <div class="fw-crm__meter-head"><span class="fw-crm__meter-name">Compliance health</span><span class="fw-crm__meter-pct"><?= $complianceHealth !== null ? $complianceHealth . '%' : 'n/a' ?></span></div>
                                <div class="fw-crm__meter-track"><div class="fw-crm__meter-fill <?= $complianceHealth === null ? '' : ($complianceHealth >= 80 ? 'fw-crm__meter-fill--good' : ($complianceHealth >= 50 ? 'fw-crm__meter-fill--warn' : 'fw-crm__meter-fill--bad')) ?>" style="width:<?= (int)($complianceHealth ?? 0) ?>%"></div></div>
                            </div>
                            <div class="fw-crm__meter" style="margin-bottom:0">
                                <div class="fw-crm__meter-head"><span class="fw-crm__meter-name">Win rate</span><span class="fw-crm__meter-pct"><?= $winRate !== null ? $winRate . '%' : 'n/a' ?></span></div>
                                <div class="fw-crm__meter-track"><div class="fw-crm__meter-fill" style="width:<?= (int)($winRate ?? 0) ?>%"></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top accounts + quick actions -->
                <div class="fw-crm__dash-grid-3">
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Top Suppliers <small>preferred first</small></h3>
                        <div class="fw-crm__mini-list">
                            <?php if (empty($preferredSuppliers)): ?>
                                <div class="fw-crm__empty-state">No active suppliers yet<small>Add your first supplier to get started</small></div>
                            <?php else: foreach ($preferredSuppliers as $s): ?>
                            <a class="fw-crm__mini-row" href="/crm/account_view.php?id=<?= (int)$s['id'] ?>">
                                <div class="fw-crm__mini-avatar"><?= htmlspecialchars(fwInitials($s['name'])) ?></div>
                                <div class="fw-crm__mini-info">
                                    <div class="fw-crm__mini-name"><?= htmlspecialchars($s['name']) ?> <?= $s['preferred'] ? '⭐' : '' ?></div>
                                    <div class="fw-crm__mini-sub"><?= htmlspecialchars($s['email'] ?: ($s['phone'] ?: 'No contact details')) ?></div>
                                </div>
                            </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Top Customers <small>by open deal value</small></h3>
                        <div class="fw-crm__mini-list">
                            <?php
                                $customerRows = !empty($topOpenAccounts) ? $topOpenAccounts : $topCustomerList;
                            ?>
                            <?php if (empty($customerRows)): ?>
                                <div class="fw-crm__empty-state">No active customers yet<small>Add customers or create opportunities</small></div>
                            <?php else: foreach ($customerRows as $c): ?>
                            <a class="fw-crm__mini-row" href="/crm/account_view.php?id=<?= (int)$c['id'] ?>">
                                <div class="fw-crm__mini-avatar fw-crm__mini-avatar--customer"><?= htmlspecialchars(fwInitials($c['name'])) ?></div>
                                <div class="fw-crm__mini-info">
                                    <div class="fw-crm__mini-name"><?= htmlspecialchars($c['name']) ?><?= !empty($c['preferred']) ? ' ⭐' : '' ?></div>
                                    <div class="fw-crm__mini-sub"><?= isset($c['open_opps']) ? (int)$c['open_opps'] . ' open deal' . ((int)$c['open_opps'] === 1 ? '' : 's') : htmlspecialchars($c['email'] ?: ($c['phone'] ?: 'No contact details')) ?></div>
                                </div>
                                <?php if (isset($c['open_value'])): ?>
                                <div class="fw-crm__mini-val"><?= fmtRand((float)$c['open_value']) ?></div>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Quick Actions</h3>
                        <div class="fw-crm__quick-actions">
                            <a href="/crm/account_new.php?type=supplier" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">🏭</span><span class="fw-crm__qa-label">New Supplier</span></a>
                            <a href="/crm/account_new.php?type=customer" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">🤝</span><span class="fw-crm__qa-label">New Customer</span></a>
                            <a href="/crm/opp_new.php" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">🚀</span><span class="fw-crm__qa-label">New Deal</span></a>
                            <a href="/crm/opps_list.php" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">📊</span><span class="fw-crm__qa-label">Pipeline Board</span></a>
                            <a href="/crm/import.php" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">📥</span><span class="fw-crm__qa-label">Import Data</span></a>
                            <a href="/crm/compliance.php" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">🛡️</span><span class="fw-crm__qa-label">Compliance</span></a>
                            <a href="/crm/dedupe.php" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">🧹</span><span class="fw-crm__qa-label">Dedupe</span></a>
                            <a href="/crm/settings.php" class="fw-crm__qa-tile"><span class="fw-crm__qa-icon">⚙️</span><span class="fw-crm__qa-label">Settings</span></a>
                        </div>
                    </div>
                </div>

                <!-- Follow-ups + compliance -->
                <div class="fw-crm__tables-grid">
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Follow-ups <small>overdue &amp; next 7 days</small></h3>
                        <div class="fw-crm__table-scroll">
                            <table class="fw-crm__data-table">
                                <thead>
                                    <tr><th>When</th><th>Subject</th><th>Account</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($followups)): ?>
                                        <tr><td colspan="4" style="text-align:center;color:var(--fw-text-muted)">Nothing due — inbox zero 🎉</td></tr>
                                    <?php else: foreach ($followups as $f):
                                        $due = strtotime($f['next_action_at']);
                                        $isOverdue = $due < time();
                                        $isToday = date('Y-m-d', $due) === date('Y-m-d');
                                    ?>
                                        <tr>
                                            <td><?= date('d M H:i', $due) ?></td>
                                            <td><?= htmlspecialchars($f['subject'] ?: ucfirst($f['type'])) ?></td>
                                            <td><a href="/crm/account_view.php?id=<?= (int)$f['account_id'] ?>"><?= htmlspecialchars($f['account_name']) ?></a></td>
                                            <td><span class="fw-crm__due-pill fw-crm__due-pill--<?= $isOverdue ? 'overdue' : ($isToday ? 'soon' : 'ok') ?>"><?= $isOverdue ? 'Overdue' : ($isToday ? 'Today' : 'Upcoming') ?></span></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Expiring Compliance <small>next 30 days</small></h3>
                        <div class="fw-crm__table-scroll">
                            <table class="fw-crm__data-table">
                                <thead>
                                    <tr><th>Document</th><th>Account</th><th>Expires</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($expiringCompliance)): ?>
                                        <tr><td colspan="4" style="text-align:center;color:var(--fw-text-muted)">No documents expiring — all clear 🛡️</td></tr>
                                    <?php else: foreach ($expiringCompliance as $doc):
                                        $daysLeft = (int)floor((strtotime($doc['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($doc['doc_type']) ?></td>
                                            <td><a href="/crm/account_view.php?id=<?= (int)$doc['account_id'] ?>"><?= htmlspecialchars($doc['account_name']) ?></a></td>
                                            <td><?= date('d M Y', strtotime($doc['expiry_date'])) ?></td>
                                            <td><span class="fw-crm__due-pill fw-crm__due-pill--<?= $daysLeft <= 7 ? 'overdue' : ($daysLeft <= 14 ? 'soon' : 'ok') ?>"><?= $daysLeft ?>d left</span></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Activity + deals needing attention -->
                <div class="fw-crm__tables-grid">
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Recent Activity <small>latest interactions</small></h3>
                        <div class="fw-crm__feed">
                            <?php if (empty($latestInteractions)): ?>
                                <div class="fw-crm__empty-state">No activity logged yet<small>Interactions you log will show up here</small></div>
                            <?php else: foreach ($latestInteractions as $ia): ?>
                            <div class="fw-crm__feed-item">
                                <div class="fw-crm__feed-icon"><?= $mixMeta[$ia['type']] ?? '📌' ?></div>
                                <div class="fw-crm__feed-body">
                                    <span class="fw-crm__pill fw-crm__pill--<?= htmlspecialchars($ia['type']) ?>"><?= htmlspecialchars(ucfirst($ia['type'])) ?></span>
                                    <?= htmlspecialchars($ia['subject'] ?: 'Interaction') ?> ·
                                    <a href="/crm/account_view.php?id=<?= (int)$ia['account_id'] ?>"><?= htmlspecialchars($ia['account_name']) ?></a>
                                </div>
                                <div class="fw-crm__feed-time"><?= date('d M H:i', strtotime($ia['created_at'])) ?></div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Deals Needing Attention <small>longest in stage</small></h3>
                        <div class="fw-crm__table-scroll">
                            <table class="fw-crm__data-table">
                                <thead>
                                    <tr><th>Deal</th><th>Account</th><th>Stage</th><th>Value</th><th>In stage</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($staleDeals)): ?>
                                        <tr><td colspan="5" style="text-align:center;color:var(--fw-text-muted)">No open deals — pipeline is clear</td></tr>
                                    <?php else: foreach ($staleDeals as $d):
                                        $days = (int)$d['days_in_stage'];
                                    ?>
                                        <tr>
                                            <td><a href="/crm/opp_view.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a></td>
                                            <td><a href="/crm/account_view.php?id=<?= (int)$d['account_id'] ?>"><?= htmlspecialchars($d['account_name']) ?></a></td>
                                            <td><span class="fw-crm__pill"><?= htmlspecialchars(ucfirst($d['stage'])) ?></span></td>
                                            <td><?= fmtRand((float)$d['amount']) ?></td>
                                            <td><span class="fw-crm__due-pill fw-crm__due-pill--<?= $days >= 14 ? 'overdue' : ($days >= 7 ? 'soon' : 'ok') ?>"><?= $days ?>d</span></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if (!empty($ownerBoard)): ?>
                <!-- Owner leaderboard -->
                <div class="fw-crm__tables-grid">
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Pipeline by Owner <small>leaderboard</small></h3>
                        <div class="fw-crm__table-scroll">
                            <table class="fw-crm__data-table">
                                <thead>
                                    <tr><th>Owner</th><th>Open deals</th><th>Open value</th><th>Won</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ownerBoard as $i => $o): ?>
                                    <tr>
                                        <td><?= ['🥇', '🥈', '🥉'][$i] ?? '·' ?> <?= htmlspecialchars(trim($o['first_name'] . ' ' . $o['last_name'])) ?></td>
                                        <td><?= (int)$o['open_cnt'] ?></td>
                                        <td><?= fmtRand((float)$o['open_value']) ?></td>
                                        <td><?= (int)$o['won_cnt'] ?> 🏆</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="fw-crm__table-card">
                        <h3 class="fw-crm__chart-title">Interaction Trend <small>volume by month</small></h3>
                        <div class="fw-crm__chart-body">
                            <canvas id="chartInteractionTrend" height="180"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($activeTab === 'suppliers'): ?>
                <!-- SUPPLIERS TAB -->
                <div class="fw-crm__toolbar">
                    <div class="fw-crm__search-box">
                        <input type="text" id="searchInput" class="fw-crm__search-input" placeholder="Search suppliers...">
                    </div>
                    <div class="fw-crm__filters">
                        <select id="filterStatus" class="fw-crm__select">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="prospect">Prospect</option>
                            <option value="banned">Banned</option>
                        </select>
                        <select id="filterIndustry" class="fw-crm__select">
                            <option value="">All Industries</option>
                            <?php foreach ($filterIndustries as $ind): ?>
                                <option value="<?= (int)$ind['id'] ?>"><?= htmlspecialchars($ind['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filterRegion" class="fw-crm__select">
                            <option value="">All Regions</option>
                            <?php foreach ($filterRegions as $reg): ?>
                                <option value="<?= (int)$reg['id'] ?>"><?= htmlspecialchars($reg['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filterDeleted" class="fw-crm__select" title="Deleted accounts">
                            <option value="0">Active (not deleted)</option>
                            <option value="1">Deleted only</option>
                            <option value="all">All (incl. deleted)</option>
                        </select>
                    </div>
                    <a href="/crm/account_new.php?type=supplier" class="fw-crm__btn fw-crm__btn--primary">
                        + New Supplier
                    </a>
                </div>
                <div class="fw-crm__accounts-list" id="accountsList">
                    <div class="fw-crm__loading">Loading suppliers...</div>
                </div>
                <?php endif; ?>

                <?php if ($activeTab === 'customers'): ?>
                <!-- CUSTOMERS TAB -->
                <div class="fw-crm__toolbar">
                    <div class="fw-crm__search-box">
                        <input type="text" id="searchInput" class="fw-crm__search-input" placeholder="Search customers...">
                    </div>
                    <div class="fw-crm__filters">
                        <select id="filterStatus" class="fw-crm__select">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="prospect">Prospect</option>
                            <option value="banned">Banned</option>
                        </select>
                        <select id="filterIndustry" class="fw-crm__select">
                            <option value="">All Industries</option>
                            <?php foreach ($filterIndustries as $ind): ?>
                                <option value="<?= (int)$ind['id'] ?>"><?= htmlspecialchars($ind['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filterRegion" class="fw-crm__select">
                            <option value="">All Regions</option>
                            <?php foreach ($filterRegions as $reg): ?>
                                <option value="<?= (int)$reg['id'] ?>"><?= htmlspecialchars($reg['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filterDeleted" class="fw-crm__select" title="Deleted accounts">
                            <option value="0">Active (not deleted)</option>
                            <option value="1">Deleted only</option>
                            <option value="all">All (incl. deleted)</option>
                        </select>
                    </div>
                    <a href="/crm/account_new.php?type=customer" class="fw-crm__btn fw-crm__btn--primary">
                        + New Customer
                    </a>
                </div>
                <div class="fw-crm__accounts-list" id="accountsList">
                    <div class="fw-crm__loading">Loading customers...</div>
                </div>
                <?php endif; ?>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= CRM_ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/crm/assets/crm.js?v=<?= CRM_ASSET_VERSION ?>"></script>
    <?php if ($activeTab === 'overview'): ?>
    <script>
    (function() {
      'use strict';

      const MONTH_LABELS = <?= json_encode($monthLabels) ?>;
      const GROWTH_SUPPLIERS = <?= json_encode($growthSuppliers) ?>;
      const GROWTH_CUSTOMERS = <?= json_encode($growthCustomers) ?>;
      const INTERACTIONS = <?= json_encode($interactionSeries) ?>;
      const PIPELINE = <?= json_encode($pipelineByStage) ?>;
      const STATUSES = <?= json_encode($statuses) ?>;

      const STAGE_COLORS = { prospect: '#8b5cf6', qualification: '#3b82f6', proposal: '#06b6d4', negotiation: '#f59e0b' };
      const STATUS_COLORS = { active: '#10b981', prospect: '#8b5cf6', inactive: '#64748b', banned: '#ef4444' };

      window.chartInstances = window.chartInstances || {};

      function themePalette() {
        const isDark = document.querySelector('.fw-crm').getAttribute('data-theme') === 'dark';
        return {
          isDark: isDark,
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

      function destroyCharts() {
        ['growth', 'pipeline', 'engagement', 'status', 'trend'].forEach(function(k) {
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

        // Account growth — glossy gradient bars
        const growthCanvas = document.getElementById('chartGrowth');
        if (growthCanvas) {
          window.chartInstances.growth = new Chart(growthCanvas, {
            type: 'bar',
            data: {
              labels: MONTH_LABELS,
              datasets: [
                {
                  label: 'Suppliers',
                  data: GROWTH_SUPPLIERS,
                  borderRadius: 8,
                  borderSkipped: false,
                  maxBarThickness: 34,
                  backgroundColor: function(c) {
                    const area = c.chart.chartArea;
                    return area ? vGradient(c.chart.ctx, area, '#06b6d4') : '#06b6d4';
                  }
                },
                {
                  label: 'Customers',
                  data: GROWTH_CUSTOMERS,
                  borderRadius: 8,
                  borderSkipped: false,
                  maxBarThickness: 34,
                  backgroundColor: function(c) {
                    const area = c.chart.chartArea;
                    return area ? vGradient(c.chart.ctx, area, '#10b981') : '#10b981';
                  }
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

        // Pipeline value split — glowing doughnut
        const pipelineCanvas = document.getElementById('chartPipeline');
        if (pipelineCanvas) {
          const hasData = PIPELINE.length > 0;
          window.chartInstances.pipeline = new Chart(pipelineCanvas, {
            type: 'doughnut',
            data: {
              labels: hasData ? PIPELINE.map(r => r.stage.charAt(0).toUpperCase() + r.stage.slice(1)) : ['No open deals'],
              datasets: [{
                data: hasData ? PIPELINE.map(r => parseFloat(r.total)) : [1],
                backgroundColor: hasData ? PIPELINE.map(r => STAGE_COLORS[r.stage] || '#64748b') : [p.isDark ? '#1e293b' : '#e2e8f0'],
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

        // Engagement — neon line with gradient fill
        const engagementCanvas = document.getElementById('chartEngagement');
        if (engagementCanvas) {
          window.chartInstances.engagement = new Chart(engagementCanvas, {
            type: 'line',
            data: {
              labels: MONTH_LABELS,
              datasets: [{
                label: 'Interactions',
                data: INTERACTIONS,
                borderColor: '#06b6d4',
                borderWidth: 3,
                pointBackgroundColor: '#06b6d4',
                pointBorderColor: p.isDark ? '#0b131b' : '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.42,
                fill: true,
                backgroundColor: function(c) {
                  const area = c.chart.chartArea;
                  if (!area) return 'rgba(6,182,212,.15)';
                  const g = c.chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
                  g.addColorStop(0, 'rgba(6,182,212,0)');
                  g.addColorStop(1, 'rgba(6,182,212,.35)');
                  return g;
                }
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              plugins: { legend: { display: false }, tooltip: glossTooltip(p) },
              scales: {
                x: { grid: { display: false }, ticks: { color: p.tick } },
                y: { grid: { color: p.grid }, ticks: { color: p.tick, stepSize: 1 }, border: { display: false } }
              }
            }
          });
        }

        // Account status — doughnut
        const statusCanvas = document.getElementById('chartStatus');
        if (statusCanvas) {
          const entries = Object.entries(STATUSES);
          const hasData = entries.length > 0;
          window.chartInstances.status = new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
              labels: hasData ? entries.map(e => e[0].charAt(0).toUpperCase() + e[0].slice(1)) : ['No accounts'],
              datasets: [{
                data: hasData ? entries.map(e => parseInt(e[1], 10)) : [1],
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
                tooltip: glossTooltip(p)
              }
            }
          });
        }

        // Interaction trend bars (leaderboard row)
        const trendCanvas = document.getElementById('chartInteractionTrend');
        if (trendCanvas) {
          window.chartInstances.trend = new Chart(trendCanvas, {
            type: 'bar',
            data: {
              labels: MONTH_LABELS,
              datasets: [{
                label: 'Interactions',
                data: INTERACTIONS,
                borderRadius: 8,
                borderSkipped: false,
                maxBarThickness: 40,
                backgroundColor: function(c) {
                  const area = c.chart.chartArea;
                  return area ? vGradient(c.chart.ctx, area, '#8b5cf6') : '#8b5cf6';
                }
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              plugins: { legend: { display: false }, tooltip: glossTooltip(p) },
              scales: {
                x: { grid: { display: false }, ticks: { color: p.tick } },
                y: { grid: { color: p.grid }, ticks: { color: p.tick, stepSize: 1 }, border: { display: false } }
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

      // Re-skin charts when the theme flips (crm.js dispatches crm:theme)
      document.addEventListener('crm:theme', buildCharts);
    })();
    </script>
    <?php endif; ?>
</body>
</html>