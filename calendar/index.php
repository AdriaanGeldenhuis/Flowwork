<?php
// /calendar/index.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CAL-1');

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

// Default view
$activeView = $_GET['view'] ?? 'week';
$allowedViews = ['day', 'week', 'month', 'agenda', 'timeline', 'year'];
if (!in_array($activeView, $allowedViews)) {
    $activeView = 'week';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/calendar/assets/calendar.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-calendar">
    <div class="fw-calendar__container">
        
        <!-- Header -->
        <header class="fw-calendar__header">
            <div class="fw-calendar__brand">
                <div class="fw-calendar__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                        <circle cx="8" cy="14" r="1" fill="currentColor"/>
                        <circle cx="12" cy="14" r="1" fill="currentColor"/>
                        <circle cx="16" cy="14" r="1" fill="currentColor"/>
                        <circle cx="8" cy="18" r="1" fill="currentColor"/>
                        <circle cx="12" cy="18" r="1" fill="currentColor"/>
                    </svg>
                </div>
                <div class="fw-calendar__brand-text">
                    <div class="fw-calendar__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-calendar__app-name">Calendar</div>
                </div>
            </div>

            <div class="fw-calendar__greeting">
                Hello, <span class="fw-calendar__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-calendar__controls">
                <a href="/" class="fw-calendar__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <button class="fw-calendar__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-calendar__menu-wrapper">
                    <button class="fw-calendar__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-calendar__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/calendar/event_new.php" class="fw-calendar__kebab-item">New Event</a>
                        <a href="/calendar/settings.php" class="fw-calendar__kebab-item">Settings</a>
                        <button class="fw-calendar__kebab-item" id="btnSyncCalendars">Sync Calendars</button>
                        <button class="fw-calendar__kebab-item" id="btnPrintCalendar">Print</button>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Layout -->
        <main class="fw-calendar__main">
            
            <!-- Toolbar -->
            <div class="fw-calendar__toolbar">
                <div class="fw-calendar__nav">
                    <button class="fw-calendar__btn fw-calendar__btn--icon" id="btnToday">Today</button>
                    <button class="fw-calendar__btn fw-calendar__btn--icon" id="btnPrev" title="Previous">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2"/></svg>
                    </button>
                    <button class="fw-calendar__btn fw-calendar__btn--icon" id="btnNext" title="Next">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2"/></svg>
                    </button>
                    <div class="fw-calendar__date-display" id="dateDisplay">Loading...</div>
                </div>

                <div class="fw-calendar__view-switcher">
                    <a href="?view=day" class="fw-calendar__view-tab <?= $activeView === 'day' ? 'fw-calendar__view-tab--active' : '' ?>">Day</a>
                    <a href="?view=week" class="fw-calendar__view-tab <?= $activeView === 'week' ? 'fw-calendar__view-tab--active' : '' ?>">Week</a>
                    <a href="?view=month" class="fw-calendar__view-tab <?= $activeView === 'month' ? 'fw-calendar__view-tab--active' : '' ?>">Month</a>
                    <a href="?view=agenda" class="fw-calendar__view-tab <?= $activeView === 'agenda' ? 'fw-calendar__view-tab--active' : '' ?>">Agenda</a>
                    <a href="?view=timeline" class="fw-calendar__view-tab <?= $activeView === 'timeline' ? 'fw-calendar__view-tab--active' : '' ?>">Timeline</a>
                    <a href="?view=year" class="fw-calendar__view-tab <?= $activeView === 'year' ? 'fw-calendar__view-tab--active' : '' ?>">Year</a>
                </div>

                <button class="fw-calendar__btn fw-calendar__btn--primary" id="btnNewEvent">
                    + New Event
                </button>
            </div>

            <!-- Content Area -->
            <div class="fw-calendar__content">
                
                <!-- Sidebar (My Day + Calendars) -->
                <aside class="fw-calendar__sidebar">
                    <div class="fw-calendar__sidebar-section">
                        <h3 class="fw-calendar__sidebar-title">My Day</h3>
                        <div class="fw-calendar__myday" id="myDayPane">
                            <div class="fw-calendar__loading">Loading tasks...</div>
                        </div>
                    </div>

                    <div class="fw-calendar__sidebar-section">
                        <div class="fw-calendar__sidebar-header">
                            <h3 class="fw-calendar__sidebar-title">Calendars</h3>
                            <button class="fw-calendar__btn fw-calendar__btn--small" id="btnAddCalendar">+</button>
                        </div>
                        <div class="fw-calendar__calendar-list" id="calendarList">
                            <div class="fw-calendar__loading">Loading...</div>
                        </div>
                    </div>
                </aside>

                <!-- Main View -->
                <div class="fw-calendar__view" id="calendarView">
                    <div class="fw-calendar__loading">Loading calendar...</div>
                </div>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-calendar__footer">
            <span>Calendar v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script>
        window.CALENDAR_CONFIG = {
            userId: <?= $userId ?>,
            companyId: <?= $companyId ?>,
            activeView: '<?= $activeView ?>',
            assetVersion: '<?= ASSET_VERSION ?>'
        };
    </script>
    <script src="/calendar/assets/calendar.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>