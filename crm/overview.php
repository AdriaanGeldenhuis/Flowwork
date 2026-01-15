<?php
// CRM Overview Page
// This page provides a dashboard-like overview of key metrics, charts and recent records.
// It follows the CRM application's existing design language by reusing the core header,
// footer and theme toggle, while introducing a new grid layout for KPIs, charts and tables.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Define asset version to assist with cache busting. Keep this consistent with index.php.
define('ASSET_VERSION', '2025-01-21-CRM-4');

// Fetch user and company information for the header and greeting.
$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user  = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Placeholder data for KPIs. Replace with real queries or API calls as needed.
$kpiMetrics = [
    [
        'label' => 'Total Sales',
        'value' => 'R20k',
        'delta' => '+6% from yesterday',
        'icon'  => '💰',
    ],
    [
        'label' => 'Total Orders',
        'value' => '500',
        'delta' => '+1% from yesterday',
        'icon'  => '🧾',
    ],
    [
        'label' => 'Products Sold',
        'value' => '50',
        'delta' => '+2% from yesterday',
        'icon'  => '📦',
    ],
    [
        'label' => 'New Customers',
        'value' => '15',
        'delta' => '+5% from yesterday',
        'icon'  => '🧑‍🤝‍🧑',
    ],
];

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM – <?= htmlspecialchars($companyName) ?> Overview</title>
    <!-- Global CRM styles -->
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
    <!-- Overview-specific styles -->
    <link rel="stylesheet" href="/crm/assets/overview.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-crm">
    <div class="fw-crm__container">
        <!-- Header copied from index.php to ensure consistent look and feel -->
        <header class="fw-crm__header">
            <div class="fw-crm__brand">
                <div class="fw-crm__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
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
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Overview Content -->
        <main class="fw-crm__main">
            <!-- Page header for Overview -->
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">Overview</h1>
                <p class="fw-crm__page-subtitle">At-a-glance metrics for your business</p>
            </div>

            <!-- Overview controls: Export and Range filter -->
            <div class="fw-container">
                <div class="fw-page-head">
                    <div></div>
                    <div class="fw-head-actions">
                        <button class="fw-btn fw-btn-outline" id="btn-export">Export</button>
                        <div class="fw-filters">
                            <select id="range" class="fw-select">
                                <option value="today">Today</option>
                                <option value="7d">Last 7 days</option>
                                <option value="30d" selected>Last 30 days</option>
                                <option value="ytd">YTD</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- KPI cards row -->
                <section class="fw-grid fw-grid-4">
                    <?php foreach ($kpiMetrics as $k): ?>
                        <div class="fw-card kpi">
                            <div class="kpi-icon">
                                <?= htmlspecialchars($k['icon']) ?>
                            </div>
                            <div class="kpi-main">
                                <div class="kpi-value">
                                    <?= htmlspecialchars($k['value']) ?>
                                </div>
                                <div class="kpi-label">
                                    <?= htmlspecialchars($k['label']) ?>
                                </div>
                            </div>
                            <div class="kpi-delta">
                                <?= htmlspecialchars($k['delta']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>

                <!-- Charts section -->
                <section class="fw-grid fw-grid-3">
                    <div class="fw-card">
                        <div class="fw-card-head">
                            <h3>Total Revenue</h3>
                        </div>
                        <canvas id="chartRevenue" height="120"></canvas>
                    </div>
                    <div class="fw-card">
                        <div class="fw-card-head">
                            <h3>Customer Satisfaction</h3>
                        </div>
                        <canvas id="chartCSAT" height="120"></canvas>
                    </div>
                    <div class="fw-card">
                        <div class="fw-card-head">
                            <h3>Reality vs Target</h3>
                        </div>
                        <div class="rv-container">
                            <canvas id="chartRVT" height="140"></canvas>
                            <div class="rv-gauges">
                                <div class="rv-gauge">
                                    <div class="rv-gauge-value" id="rv-actual">9.2%</div>
                                    <div class="rv-gauge-label">Actual</div>
                                </div>
                                <div class="rv-gauge">
                                    <div class="rv-gauge-value" id="rv-target">12.5%</div>
                                    <div class="rv-gauge-label">Target</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Tables section -->
                <section class="fw-grid fw-grid-2">
                    <div class="fw-card">
                        <div class="fw-card-head">
                            <h3>Recent Activity</h3>
                            <a href="/crm/activity.php" class="fw-link">See all</a>
                        </div>
                        <div class="fw-table-wrap">
                            <table class="fw-table fw-table-compact">
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Type</th>
                                        <th>Ref</th>
                                        <th>Account</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="tbl-activity">
                                    <!-- Populated by overview.js -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="fw-card">
                        <div class="fw-card-head">
                            <h3>Top Accounts</h3>
                            <a href="/crm/accounts.php" class="fw-link">Open</a>
                        </div>
                        <div class="fw-table-wrap">
                            <table class="fw-table fw-table-compact">
                                <thead>
                                    <tr>
                                        <th>Account</th>
                                        <th>Orders</th>
                                        <th>Revenue</th>
                                        <th>Last Seen</th>
                                    </tr>
                                </thead>
                                <tbody id="tbl-accounts">
                                    <!-- Populated by overview.js -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <!-- Footer consistent with the rest of CRM -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="/crm/assets/overview.js?v=<?= ASSET_VERSION ?>"></script>
    <!-- Reuse existing CRM JS for theme toggling and interactions -->
    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>