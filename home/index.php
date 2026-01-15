<?php
// home/index.php – New Dashboard Page (Section 12)
//
// This page provides a role‑based dashboard with key metrics and
// notifications. It reuses the existing session and auth mechanisms but
// augments the basic home landing page with a grid of widgets,
// draggable layout, role selection and a notification centre. Metrics
// are calculated server‑side for project counts, tasks and timesheets
// and finance data is fetched asynchronously from existing endpoints.

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Asset version for cache busting
define('ASSET_VERSION', '2025-10-07-1');

// Fetch user data from session
$firstName = $_SESSION['user_first_name'] ?? 'Welcome';
$userId    = $_SESSION['user_id'];
$companyId = $_SESSION['company_id'];

// Fetch company data for branding
$stmt = $DB->prepare("SELECT c.name, c.business_type FROM companies c JOIN users u ON u.company_id = c.id WHERE u.id = ?");
$stmt->execute([$userId]);
$company = $stmt->fetch();

$companyName   = $company['name'] ?? 'Your Company';
$businessType  = $company['business_type'] ?? 'construction';
$companyLogo   = null; // Placeholder for future logo upload support

// --------- Project & Task Metrics ---------
try {
    // Active projects: not completed/archived/cancelled and not archived flag
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM projects WHERE company_id = ? AND status NOT IN ('completed','archived','cancelled') AND archived = 0"
    );
    $stmt->execute([$companyId]);
    $activeProjects = (int)$stmt->fetchColumn();

    // Upcoming tasks (due within next 7 days)
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM board_items WHERE company_id = ? AND archived = 0 AND due_date IS NOT NULL AND due_date >= CURDATE() AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    );
    $stmt->execute([$companyId]);
    $upcomingTasks = (int)$stmt->fetchColumn();

    // Overdue tasks (due date in the past)
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM board_items WHERE company_id = ? AND archived = 0 AND due_date IS NOT NULL AND due_date < CURDATE()"
    );
    $stmt->execute([$companyId]);
    $overdueTasks = (int)$stmt->fetchColumn();

    // Pending timesheets (not yet allocated to a pay run)
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM pay_timesheets WHERE company_id = ? AND (run_id IS NULL OR run_id = 0)"
    );
    $stmt->execute([$companyId]);
    $pendingTimesheets = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // In case of an error, default metrics to zero and log
    error_log('Dashboard metric error: ' . $e->getMessage());
    $activeProjects    = 0;
    $upcomingTasks     = 0;
    $overdueTasks      = 0;
    $pendingTimesheets = 0;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowwork Dashboard – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/home/dashboard.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-dashboard" data-theme="light">
    <div class="fw-dashboard__container">
        <!-- Header -->
        <header class="fw-dashboard__header">
            <div class="fw-dashboard__brand">
                <div class="fw-dashboard__logo-tile">
                    <?php if ($companyLogo): ?>
                        <img src="<?= htmlspecialchars($companyLogo) ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="fw-dashboard__company-logo">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M20 7L12 3L4 7V17L12 21L20 17V7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 12L20 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 12V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 12L4 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="fw-dashboard__brand-text">
                    <div class="fw-dashboard__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-dashboard__app-name">Flowwork</div>
                </div>
            </div>
            <div class="fw-dashboard__greeting">
                Hello, <span class="fw-dashboard__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>
            <div class="fw-dashboard__controls">
                <!-- Notifications -->
                <div class="fw-dashboard__notifications-wrapper">
                    <button class="fw-dashboard__notifications-btn" id="notificationsBtn" aria-label="Notifications" type="button">
                        <!-- Bell icon -->
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M13.73 21a2 2 0 01-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span id="notificationsBadge" class="fw-dashboard__notifications-badge" style="display:none;"></span>
                    </button>
                    <div class="fw-dashboard__notifications-dropdown" id="notificationsDropdown" aria-hidden="true">
                        <div class="fw-dashboard__notifications-header">
                            <h3>Notifications</h3>
                            <button class="fw-dashboard__notifications-mark-all" id="markAllRead" type="button">Mark all as read</button>
                        </div>
                        <div class="fw-dashboard__notifications-list" id="notificationsList"></div>
                    </div>
                </div>
                <!-- Theme toggle -->
                <button class="fw-dashboard__theme-toggle" id="themeToggle" aria-label="Toggle theme" type="button">
                    <svg class="fw-dashboard__theme-icon fw-dashboard__theme-icon--light" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
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
                    <svg class="fw-dashboard__theme-icon fw-dashboard__theme-icon--dark" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <!-- Kebab menu -->
                <div class="fw-dashboard__menu-wrapper">
                    <button class="fw-dashboard__kebab-toggle" id="kebabToggle" aria-label="Open menu" aria-expanded="false" aria-controls="kebabMenu" type="button">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-dashboard__kebab-menu" id="kebabMenu" role="menu" aria-hidden="true">
                        <a href="/admin/" class="fw-dashboard__kebab-item" role="menuitem">Admin/Settings</a>
                        <a href="/contact/" class="fw-dashboard__kebab-item" role="menuitem">Contact Us</a>
                        <a href="/help/" class="fw-dashboard__kebab-item" role="menuitem">Help</a>
                        <a href="/logout.php" class="fw-dashboard__kebab-item" role="menuitem">Logout</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Role Selection -->
        <div class="fw-dashboard__roles" id="roleSelector">
            <button class="fw-dashboard__role-btn" data-role="manager" type="button">Manager</button>
            <button class="fw-dashboard__role-btn" data-role="finance" type="button">Finance</button>
            <button class="fw-dashboard__role-btn" data-role="pm" type="button">Project Manager</button>
        </div>

        <!-- Main Grid -->
        <main class="fw-dashboard__main">
            <div class="fw-dashboard__grid" id="dashboardGrid">
                <!-- Active Projects -->
                <div class="fw-dashboard__widget" id="widget-active-projects" data-roles="manager,pm">
                    <h3>Active Projects</h3>
                    <div class="fw-dashboard__metric" id="metric-active-projects"><?= $activeProjects ?></div>
                </div>

                <!-- Upcoming Tasks -->
                <div class="fw-dashboard__widget" id="widget-upcoming-tasks" data-roles="manager,pm">
                    <h3>Upcoming Tasks (7 days)</h3>
                    <div class="fw-dashboard__metric" id="metric-upcoming-tasks"><?= $upcomingTasks ?></div>
                </div>

                <!-- Overdue Tasks -->
                <div class="fw-dashboard__widget" id="widget-overdue-tasks" data-roles="manager,pm">
                    <h3>Overdue Tasks</h3>
                    <div class="fw-dashboard__metric" id="metric-overdue-tasks"><?= $overdueTasks ?></div>
                </div>

                <!-- Pending Timesheets -->
                <div class="fw-dashboard__widget" id="widget-pending-timesheets" data-roles="manager,pm">
                    <h3>Pending Timesheets</h3>
                    <div class="fw-dashboard__metric" id="metric-pending-timesheets"><?= $pendingTimesheets ?></div>
                </div>

                <!-- Cash Balance -->
                <div class="fw-dashboard__widget" id="widget-cash-balance" data-roles="manager,finance">
                    <h3>Cash &amp; Bank</h3>
                    <div class="fw-dashboard__metric" id="metric-cash-balance">–</div>
                </div>

                <!-- Accounts Receivable -->
                <div class="fw-dashboard__widget" id="widget-ar-outstanding" data-roles="manager,finance">
                    <h3>Accounts Receivable</h3>
                    <div class="fw-dashboard__metric" id="metric-ar-outstanding">–</div>
                </div>

                <!-- Accounts Payable -->
                <div class="fw-dashboard__widget" id="widget-ap-outstanding" data-roles="manager,finance">
                    <h3>Accounts Payable</h3>
                    <div class="fw-dashboard__metric" id="metric-ap-outstanding">–</div>
                </div>

                <!-- Net Profit (MTD) -->
                <div class="fw-dashboard__widget" id="widget-net-profit" data-roles="finance">
                    <h3>Net Profit (MTD)</h3>
                    <div class="fw-dashboard__metric" id="metric-net-profit">–</div>
                </div>

                <!-- VAT Due -->
                <div class="fw-dashboard__widget" id="widget-vat-due" data-roles="finance">
                    <h3>VAT Due</h3>
                    <div class="fw-dashboard__metric" id="metric-vat-due">–</div>
                </div>

                <!-- Bank Reconciliation -->
                <div class="fw-dashboard__widget" id="widget-bank-unrec" data-roles="finance">
                    <h3>Bank Items to Reconcile</h3>
                    <div class="fw-dashboard__metric" id="metric-bank-unrec">–</div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="fw-dashboard__footer">
            <span>Version <?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>

    <!-- Pass base URL into JS for AJAX if needed -->
    <script>
        // Expose base URL for AJAX requests (not currently used but reserved)
        window.FW_BASE_URL = '';
    </script>
    <script src="/home/dashboard.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>