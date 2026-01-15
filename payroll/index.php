<?php
// /payroll/index.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// DB compatibility check
if (!isset($pdo) && isset($DB)) { $pdo = $DB; }

define('ASSET_VERSION', '2025-01-21-PAYROLL-1');

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

// Dashboard stats
$stmt = $DB->prepare("
    SELECT COUNT(*) as total_employees
    FROM employees
    WHERE company_id = ? AND termination_date IS NULL
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch();
$totalEmployees = $stats['total_employees'] ?? 0;

$stmt = $DB->prepare("
    SELECT COUNT(*) as open_runs
    FROM pay_runs
    WHERE company_id = ? AND status IN ('draft','calculated','review')
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch();
$openRuns = $stats['open_runs'] ?? 0;

$stmt = $DB->prepare("
    SELECT 
        COUNT(*) as last_run_count,
        SUM(net_cents) as last_run_net
    FROM pay_run_employees pre
    JOIN pay_runs pr ON pre.run_id = pr.id
    WHERE pr.company_id = ? AND pr.status = 'posted'
    ORDER BY pr.pay_date DESC
    LIMIT 1
");
$stmt->execute([$companyId]);
$lastRun = $stmt->fetch();
$lastRunNet = ($lastRun['last_run_net'] ?? 0) / 100;

// Upcoming pay dates
$stmt = $DB->prepare("
    SELECT id, name, pay_date, status
    FROM pay_runs
    WHERE company_id = ? AND pay_date >= CURDATE()
    ORDER BY pay_date ASC
    LIMIT 5
");
$stmt->execute([$companyId]);
$upcomingRuns = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/payroll/assets/payroll.css?v=<?= ASSET_VERSION ?>">
    <!-- Overview styles for the payroll dashboard -->
    <link rel="stylesheet" href="/payroll/assets/overview.css?v=<?= ASSET_VERSION ?>">
    <!-- Modernised overview styling to match CRM aesthetics -->
    <link rel="stylesheet" href="/payroll/css/payroll.overview.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-payroll">
        <div class="fw-payroll__container">
            
            <!-- Header -->
            <header class="fw-payroll__header">
                <div class="fw-payroll__brand">
                    <div class="fw-payroll__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="fw-payroll__brand-text">
                        <div class="fw-payroll__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-payroll__app-name">Payroll</div>
                    </div>
                </div>

                <div class="fw-payroll__greeting">
                    Hello, <span class="fw-payroll__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-payroll__controls">
                    <a href="/" class="fw-payroll__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <button class="fw-payroll__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-payroll__theme-icon fw-payroll__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                        <svg class="fw-payroll__theme-icon fw-payroll__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>

                    <div class="fw-payroll__menu-wrapper">
                        <button class="fw-payroll__kebab-toggle" id="kebabToggle" aria-label="Menu">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                            </svg>
                        </button>
                        <nav class="fw-payroll__kebab-menu" id="kebabMenu" aria-hidden="true">
                            <a href="/payroll/employees.php" class="fw-payroll__kebab-item">Employees</a>
                            <a href="/payroll/payitems.php" class="fw-payroll__kebab-item">Pay Items</a>
                            <a href="/payroll/runs.php" class="fw-payroll__kebab-item">Pay Runs</a>
                            <a href="/payroll/reports.php" class="fw-payroll__kebab-item">Reports</a>
                            <a href="/payroll/settings.php" class="fw-payroll__kebab-item">Settings</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Main Dashboard -->
            <div class="fw-payroll__main">
                

                <!-- Quick Actions -->
                <div class="fw-payroll__quick-actions">
                    <a href="/payroll/run_new.php" class="fw-payroll__btn fw-payroll__btn--primary fw-payroll__btn--large">
                        + New Pay Run
                    </a>
                    <a href="/payroll/employees.php" class="fw-payroll__btn fw-payroll__btn--secondary fw-payroll__btn--large">
                        Manage Employees
                    </a>
                    <a href="/payroll/reports.php" class="fw-payroll__btn fw-payroll__btn--secondary fw-payroll__btn--large">
                        View Reports
                    </a>
                </div>

                <!-- Upcoming Pay Dates -->
                <?php if (count($upcomingRuns) > 0): ?>
                <div class="fw-payroll__section">
                    <h2 class="fw-payroll__section-title">Upcoming Pay Dates</h2>
                    <div class="fw-payroll__upcoming-list">
                        <?php foreach ($upcomingRuns as $run): ?>
                        <a href="/payroll/run_view.php?id=<?= $run['id'] ?>" class="fw-payroll__upcoming-card">
                            <div class="fw-payroll__upcoming-date">
                                <?= date('d M Y', strtotime($run['pay_date'])) ?>
                            </div>
                            <div class="fw-payroll__upcoming-name"><?= htmlspecialchars($run['name']) ?></div>
                            <span class="fw-payroll__badge fw-payroll__badge--<?= $run['status'] ?>">
                                <?= ucfirst($run['status']) ?>
                            </span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payroll Overview Dashboard -->
                <div id="payroll-overview" class="fw-container">
                    <!-- First row: KPI cards -->
                    <div class="kpi-row">
                        <div class="kpi">
                            <div class="kpi-icon">
                                <!-- Employees icon -->
                                <svg viewBox="0 0 24 24" fill="none"><path d="M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M23 20v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiEmployees">-</div>
                                <div class="kpi-label">Active Employees</div>
                            </div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-icon">
                                <!-- Open runs icon -->
                                <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2"/><line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2"/><line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/></svg>
                            </div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiOpenRuns">-</div>
                                <div class="kpi-label">Open Runs</div>
                            </div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-icon">
                                <!-- Last payroll icon -->
                                <svg viewBox="0 0 24 24" fill="none"><path d="M6 7V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><rect x="3" y="7" width="18" height="13" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiLastPayroll">-</div>
                                <div class="kpi-label">Last Net Payroll</div>
                            </div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-icon">
                                <!-- New employees icon -->
                                <svg viewBox="0 0 24 24" fill="none"><path d="M16 14v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M19 17h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 20v-2a4 4 0 0 0-4-4H4a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </div>
                            <div class="kpi-main">
                                <div class="kpi-value" id="kpiNewEmployees">-</div>
                                <div class="kpi-label">New Employees</div>
                            </div>
                        </div>
                    </div>
                    <!-- Second row: Cost, Headcount, MTD charts -->
                    <div class="fw-grid fw-grid-3">
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Monthly Payroll Cost</h3></div>
                            <canvas id="chartCost" height="220"></canvas>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Monthly Headcount</h3></div>
                            <canvas id="chartHeadcount" height="220"></canvas>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>MTD Payroll</h3></div>
                            <canvas id="chartMtd" height="220"></canvas>
                        </div>
                    </div>
                    <!-- Third row: Top employees table, Employment types and Runs volume charts -->
                    <div class="fw-grid fw-grid-3">
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Top Employees</h3></div>
                            <div class="fw-table-wrap">
                                <table class="fw-table fw-table-compact">
                                    <thead><tr><th>Employee</th><th>Runs</th><th>Total Net</th></tr></thead>
                                    <tbody id="tableTopEmployees"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Employment Types</h3></div>
                            <canvas id="chartEmploymentType" height="220"></canvas>
                        </div>
                        <div class="fw-card">
                            <div class="fw-card-head"><h3>Runs Volume</h3></div>
                            <canvas id="chartRunsVolume" height="220"></canvas>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-payroll__footer">
                <span>Payroll v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>
    </main>

    <script src="/payroll/assets/payroll.js?v=<?= ASSET_VERSION ?>"></script>
    <!-- Chart.js for rendering overview charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Payroll overview logic -->
    <script src="/payroll/assets/overview.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>