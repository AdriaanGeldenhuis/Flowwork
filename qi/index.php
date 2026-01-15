<?php
// /qi/index.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-1');

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
        (SELECT COUNT(*) FROM invoices WHERE company_id = ? AND status NOT IN ('cancelled')) as invoice_count,
        (SELECT COUNT(*) FROM recurring_invoices WHERE company_id = ? AND active = 1) as recurring_count,
        (SELECT COUNT(*) FROM credit_notes WHERE company_id = ?) as credit_count
");
$stmt->execute([$companyId, $companyId, $companyId, $companyId]);
$counts = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotes & Invoices – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <?php if ($activeTab === 'overview'): ?>
        <!-- Overview styles are loaded only on the overview tab to avoid extra CSS on other pages -->
        <link rel="stylesheet" href="/qi/assets/overview.css?v=<?= ASSET_VERSION ?>">
    <?php endif; ?>
</head>
<body class="fw-qi">
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
                            <option value="partial">Partially Paid</option>
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
                </div>
            <?php endif; ?>

            <!-- Overview or List Container -->
            <?php if ($activeTab === 'overview'): ?>
                <!-- Overview Dashboard: KPI cards and charts -->
                <div id="qi-overview" class="fw-container">
                    <!-- KPI Cards Row -->
                    <div class="fw-grid fw-grid-4 kpi-row">
                        <div class="kpi">
                            <div class="kpi-icon">📄</div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiActiveQuotes">0</div>
                                <div class="kpi-label">Active Quotes</div>
                            </div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-icon">⚠️</div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiOverdueInvoices">0</div>
                                <div class="kpi-label">Overdue Invoices</div>
                            </div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-icon">📋</div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiPendingInvoices">0</div>
                                <div class="kpi-label">Pending Invoices</div>
                            </div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-icon">🔄</div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiRecurringInvoices">0</div>
                                <div class="kpi-label">Recurring Invoices</div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="fw-grid fw-grid-3" style="margin-top:24px;">
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Total Revenue (12M)</h3></div>
                            <canvas id="chartRevenue" height="240"></canvas>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Quote Conversion</h3></div>
                            <canvas id="chartConversion" height="240"></canvas>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>MTD Actual vs Target</h3></div>
                            <canvas id="chartTarget" height="240"></canvas>
                        </div>
                    </div>

                    <!-- Analytics Row -->
                    <div class="fw-grid fw-grid-3" style="margin-top:24px;">
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Top Customers (30d)</h3></div>
                            <div class="fw-table-wrap">
                                <table class="fw-table">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th style="text-align:right;">Invoices</th>
                                            <th style="text-align:right;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableSalesBody">
                                        <tr><td colspan="3" style="text-align:center;padding:12px;">Loading…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Customers by Region</h3></div>
                            <canvas id="chartRegion" height="240"></canvas>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Invoices vs Quotes</h3></div>
                            <canvas id="chartVolume" height="240"></canvas>
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
            <span>Quotes & Invoices v<?= ASSET_VERSION ?></span>
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
    <?php if ($activeTab === 'overview'): ?>
    <!-- Load Chart.js and the overview logic when on the overview tab -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="/qi/assets/overview.js?v=<?= ASSET_VERSION ?>"></script>
    <?php endif; ?>
    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>