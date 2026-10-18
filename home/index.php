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
require_once __DIR__ . '/../includes/companies.php';

// Asset version for cache busting
define('ASSET_VERSION', '2026-10-18-1');

// Fetch user data from session
$firstName = $_SESSION['user_first_name'] ?? 'Welcome';
$userId    = (int)$_SESSION['user_id'];
$companyId = (int)$_SESSION['company_id'];

// Fetch the active company (resolved by auth_gate, may differ from
// users.company_id if the user has switched).
$stmt = $DB->prepare("SELECT name, business_type FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$companyName   = $company['name'] ?? 'Your Company';
$businessType  = $company['business_type'] ?? 'construction';
$companyLogo   = null; // Placeholder for future logo upload support
$userCompanies = fw_user_companies($userId);
$csrfToken     = Csrf::token();

// --------- Project & Task Metrics ---------
// Only projects the user may open (admins: all), and only live work: archived
// projects, boards and items, closed projects and done items do not count.
$activeProjects = $upcomingTasks = $overdueTasks = $pendingTimesheets = 0;
try {
    // Pending timesheets (not yet allocated to a pay run); counted first, so a
    // problem in Projects does not hide it
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM pay_timesheets WHERE company_id = ? AND (run_id IS NULL OR run_id = 0)"
    );
    $stmt->execute([$companyId]);
    $pendingTimesheets = (int)$stmt->fetchColumn();

    require_once __DIR__ . '/../projects/lib/autoload.php';
    // SQL limit to ProjectLink::visibleProjectIds() (integer ids only, no parameters)
    [$visibleSql] = ProjectLink::repo($DB, $companyId, $userId, (string)($_SESSION['role'] ?? 'viewer'))->visibleSql('p');
    $today = ProjectRepo::today();

    // Active projects: draft, active or on hold, and not archived
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM projects p
          WHERE p.company_id = ? AND p.status IN ('draft','active','on_hold') AND (p.archived = 0 OR p.archived IS NULL) AND $visibleSql"
    );
    $stmt->execute([$companyId]);
    $activeProjects = (int)$stmt->fetchColumn();

    // Upcoming tasks (due today or within the next 7 days) and overdue tasks (due date in the past)
    $stmt = $DB->prepare(
        "SELECT SUM(i.due_date >= ? AND i.due_date <= ?) AS upcoming, SUM(i.due_date < ?) AS overdue
           FROM board_items i
           JOIN project_boards b ON b.board_id = i.board_id AND b.company_id = i.company_id
           JOIN projects p ON p.project_id = b.project_id AND p.company_id = b.company_id
          WHERE i.company_id = ? AND i.due_date IS NOT NULL AND COALESCE(i.progress, 0) < 100 AND (i.archived = 0 OR i.archived IS NULL)
            AND (b.archived = 0 OR b.archived IS NULL) AND (p.archived = 0 OR p.archived IS NULL)
            AND p.status NOT IN ('completed','cancelled','archived') AND $visibleSql"
    );
    $stmt->execute([$today, date('Y-m-d', strtotime($today . ' +7 days')), $today, $companyId]);
    $tasks = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $upcomingTasks = (int)($tasks['upcoming'] ?? 0);
    $overdueTasks  = (int)($tasks['overdue'] ?? 0);
} catch (Throwable $e) {
    // In case of an error, the metrics not counted yet stay zero; log it
    error_log('Dashboard metric error: ' . $e->getMessage());
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flowwork Dashboard – <?= htmlspecialchars($companyName) ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
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
                        <?php if (count($userCompanies) > 1): ?>
                            <div class="fw-dashboard__kebab-section-label">Switch company</div>
                            <?php foreach ($userCompanies as $uc): ?>
                                <?php $isActive = ((int)$uc['id'] === $companyId); ?>
                                <button
                                    type="button"
                                    class="fw-dashboard__kebab-item fw-dashboard__kebab-item--company<?= $isActive ? ' is-active' : '' ?>"
                                    role="menuitem"
                                    data-fw-switch-company="<?= (int)$uc['id'] ?>"
                                    <?= $isActive ? 'aria-current="true" disabled' : '' ?>
                                >
                                    <span class="fw-dashboard__kebab-item-name"><?= htmlspecialchars($uc['name']) ?></span>
                                    <?php if ($isActive): ?>
                                        <span class="fw-dashboard__kebab-item-badge" aria-hidden="true">&#10003;</span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                            <div class="fw-dashboard__kebab-divider" role="separator"></div>
                        <?php endif; ?>
                        <a href="/admin/" class="fw-dashboard__kebab-item" role="menuitem">Admin/Settings</a>
                        <a href="/contact/" class="fw-dashboard__kebab-item" role="menuitem">Contact Us</a>
                        <a href="/help/" class="fw-dashboard__kebab-item" role="menuitem">Help</a>
                        <a href="/account/" class="fw-dashboard__kebab-item" role="menuitem">Signed-in devices</a>
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
    <script src="/shared/company_switcher.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>