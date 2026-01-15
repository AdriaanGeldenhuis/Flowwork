<?php
// /finances/index.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Include permissions helper and restrict access
require_once __DIR__ . '/permissions.php';
// Only admin, bookkeeper or viewer roles may access the finance dashboard. Members/pos users should use other modules.
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', '2025-01-21-FIN-1');

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

if ($accountCount == 0) {
    // Seed default accounts
    $stmtSeed = $DB->prepare("CALL seed_default_accounts(?)");
    $stmtSeed->execute([$companyId]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <!-- Finance overview specific styles -->
    <link rel="stylesheet" href="/finances/css/finance.overview.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        
       <!-- Header -->
        <header class="fw-finance__header">
            <div class="fw-finance__brand">
                <div class="fw-finance__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-finance__brand-text">
                    <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-finance__app-name">Finance</div>
                </div>
            </div>

            <div class="fw-finance__greeting">
                Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-finance__controls">
                <a href="/" class="fw-finance__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <button class="fw-finance__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-finance__theme-icon fw-finance__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-finance__theme-icon fw-finance__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-finance__menu-wrapper">
                    <button class="fw-finance__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-finance__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/finances/chart.php" class="fw-finance__kebab-item">Chart of Accounts</a>
                        <a href="/finances/journals.php" class="fw-finance__kebab-item">Journal Entries</a>
                        <a href="/finances/reports.php" class="fw-finance__kebab-item">Reports</a>
                        <a href="/finances/settings.php" class="fw-finance__kebab-item">Settings</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="fw-finance__main">
            
            <!-- Quick Stats -->
            <div class="fw-finance__stats-grid">
                <div class="fw-finance__stat-card" id="cashStat">
                    <div class="fw-finance__stat-value">R 0.00</div>
                    <div class="fw-finance__stat-label">Cash &amp; Bank</div>
                </div>
                <div class="fw-finance__stat-card" id="arStat">
                    <div class="fw-finance__stat-value">R 0.00</div>
                    <div class="fw-finance__stat-label">AR Open</div>
                </div>
                <div class="fw-finance__stat-card" id="apStat">
                    <div class="fw-finance__stat-value">R 0.00</div>
                    <div class="fw-finance__stat-label">AP Open</div>
                </div>
                <div class="fw-finance__stat-card" id="plStat">
                    <div class="fw-finance__stat-value">R 0.00</div>
                    <div class="fw-finance__stat-label">This Month P&amp;L</div>
                </div>
                <div class="fw-finance__stat-card" id="vatStat">
                    <div class="fw-finance__stat-value">R 0.00</div>
                    <div class="fw-finance__stat-label">Net VAT Due</div>
                </div>
                <div class="fw-finance__stat-card" id="bankStat">
                    <div class="fw-finance__stat-value">0</div>
                    <div class="fw-finance__stat-label">Bank to Reconcile
                        <span id="bankBadge" class="fw-finance__badge" style="display:none;"></span>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="fw-finance__quick-actions">
                <h2 class="fw-finance__section-title">Quick Actions</h2>
                <div class="fw-finance__action-grid">
                    <a href="/finances/chart.php" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">📊</div>
                        <div class="fw-finance__action-title">Chart of Accounts</div>
                        <div class="fw-finance__action-desc">Manage your accounts</div>
                    </a>
                    <a href="/finances/journals.php" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">📝</div>
                        <div class="fw-finance__action-title">Journal Entries</div>
                        <div class="fw-finance__action-desc">Post transactions</div>
                    </a>
                    <a href="/finances/reports.php" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">📈</div>
                        <div class="fw-finance__action-title">Reports</div>
                        <div class="fw-finance__action-desc">Financial statements</div>
                    </a>
                    <a href="/finances/settings.php" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">⚙️</div>
                        <div class="fw-finance__action-title">Settings</div>
                        <div class="fw-finance__action-desc">Configure finance</div>
                    </a>
                    <a href="/finances/ar/" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">📄</div>
                        <div class="fw-finance__action-title">Invoices (AR)</div>
                        <div class="fw-finance__action-desc">Accounts receivable</div>
                    </a>
                    <a href="/finances/ap/" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">📑</div>
                        <div class="fw-finance__action-title">Bills (AP)</div>
                        <div class="fw-finance__action-desc">Accounts payable</div>
                    </a>
                    <a href="/finances/bank/" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">🏦</div>
                        <div class="fw-finance__action-title">Bank Feeds</div>
                        <div class="fw-finance__action-desc">Import & reconcile</div>
                    </a>
                    <a href="/finances/vat.php" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">🧾</div>
                        <div class="fw-finance__action-title">VAT Returns</div>
                        <div class="fw-finance__action-desc">VAT201 filing</div>
                    </a>
                    <!-- Fixed Assets -->
                    <a href="/finances/fa/" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">🏗️</div>
                        <div class="fw-finance__action-title">Fixed Assets</div>
                        <div class="fw-finance__action-desc">Manage assets & depreciation</div>
                    </a>
                    <!-- Budgets -->
                    <a href="/finances/budgets/edit.php" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">📅</div>
                        <div class="fw-finance__action-title">Budgets</div>
                        <div class="fw-finance__action-desc">Set & compare budgets</div>
                    </a>
                    <!-- Budget vs Actual Report -->
                    <a href="/finances/reports/budget_vs_actual.php" class="fw-finance__action-card">
                        <div class="fw-finance__action-icon">📋</div>
                        <div class="fw-finance__action-title">Budget vs Actual</div>
                        <div class="fw-finance__action-desc">Analyse budget variances</div>
                    </a>
                </div>
            </div>
            

            <!-- Finance Overview -->
            <section id="finance-overview" class="fw-finance-overview">
                <!-- Filters -->
                <div class="fw-finance-overview__filters">
                    <select id="ov-period" class="fw-finance-overview__select">
                        <option value="mtd">MTD</option>
                        <option value="qtd">QTD</option>
                        <option value="ytd">YTD</option>
                        <option value="fy">FY</option>
                    </select>
                    <input type="date" id="ov-from" class="fw-finance-overview__date">
                    <input type="date" id="ov-to" class="fw-finance-overview__date">
                </div>
                <?php
                    // Include KPI cards and tables for the finance overview.
                    $overviewCardsPath = __DIR__ . '/partials/overview-cards.php';
                    if (file_exists($overviewCardsPath)) {
                        include $overviewCardsPath;
                    }
                ?>
                <!-- Charts -->
                <div class="fw-finance-overview__row fw-finance-overview__charts">
                    <div id="chart-revexp" class="fw-finance-overview__card"></div>
                    <div id="chart-araging" class="fw-finance-overview__card"></div>
                </div>
                <!-- Bank & VAT cards -->
                <div class="fw-finance-overview__row fw-finance-overview__small-cards">
                    <div id="card-banks" class="fw-finance-overview__card"></div>
                    <div id="card-vat" class="fw-finance-overview__card"></div>
                </div>
                <?php
                    // Include worklist tables for the finance overview.
                    $overviewTablesPath = __DIR__ . '/partials/overview-tables.php';
                    if (file_exists($overviewTablesPath)) {
                        include $overviewTablesPath;
                    }
                ?>
            </section>

        </div>

        <!-- Footer -->
        <footer class="fw-finance__footer">
            <span>Finance v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <!-- Initialize theme from COOKIE (matching projects page) -->
    <script>
    (function() {
        function getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : null;
        }
        
        const savedTheme = getCookie('fw_theme') || 'light';
        const root = document.querySelector('.fw-finance');
        if (root) {
            root.setAttribute('data-theme', savedTheme);
            console.log('✅ Finance: Theme loaded from cookie:', savedTheme);
        }
    })();
    </script>

    <script src="/finances/assets/finances.js?v=<?= ASSET_VERSION ?>"></script>
    <!-- Finance overview logic -->
    <script src="/finances/js/finance.overview.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>