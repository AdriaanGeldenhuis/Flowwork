<?php
// /calendar/settings.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CAL-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user settings
$stmt = $DB->prepare("
    SELECT * FROM calendar_settings
    WHERE company_id = ? AND user_id = ?
");
$stmt->execute([$companyId, $userId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    $settings = [
        'timezone' => 'Africa/Johannesburg',
        'week_start' => 1,
        'work_hours_start' => '08:00:00',
        'work_hours_end' => '17:00:00',
        'default_reminder_minutes' => 15,
        'default_view' => 'week'
    ];
}

// Ensure integration toggle defaults
if (!isset($settings['enable_invoice_due'])) {
    $settings['enable_invoice_due'] = 1;
}
if (!isset($settings['enable_project_dates'])) {
    $settings['enable_project_dates'] = 1;
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar Settings – <?= htmlspecialchars($companyName) ?></title>
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
                    </svg>
                </div>
                <div class="fw-calendar__brand-text">
                    <div class="fw-calendar__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-calendar__app-name">Calendar</div>
                </div>
            </div>

            <div class="fw-calendar__greeting">
                Settings
            </div>

            <div class="fw-calendar__controls">
                <a href="/calendar/" class="fw-calendar__home-btn" title="Back to Calendar">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <button class="fw-calendar__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    <svg class="fw-calendar__theme-icon fw-calendar__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-calendar__main">
            
            <div class="fw-calendar__form-container">
                
                <form id="settingsForm" class="fw-calendar__event-form">
                    
                    <!-- General Settings -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">General Settings</h2>
                        
                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Default View</label>
                            <select name="default_view" class="fw-calendar__input">
                                <option value="day" <?= $settings['default_view'] === 'day' ? 'selected' : '' ?>>Day</option>
                                <option value="week" <?= $settings['default_view'] === 'week' ? 'selected' : '' ?>>Week</option>
                                <option value="month" <?= $settings['default_view'] === 'month' ? 'selected' : '' ?>>Month</option>
                                <option value="agenda" <?= $settings['default_view'] === 'agenda' ? 'selected' : '' ?>>Agenda</option>
                            </select>
                        </div>

                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Week Starts On</label>
                            <select name="week_start" class="fw-calendar__input">
                                <option value="0" <?= $settings['week_start'] == 0 ? 'selected' : '' ?>>Sunday</option>
                                <option value="1" <?= $settings['week_start'] == 1 ? 'selected' : '' ?>>Monday</option>
                            </select>
                        </div>

                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Timezone</label>
                            <select name="timezone" class="fw-calendar__input">
                                <option value="Africa/Johannesburg" <?= $settings['timezone'] === 'Africa/Johannesburg' ? 'selected' : '' ?>>
                                    Africa/Johannesburg (SAST)
                                </option>
                                <option value="UTC" <?= $settings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option>
                                <option value="Europe/London" <?= $settings['timezone'] === 'Europe/London' ? 'selected' : '' ?>>Europe/London</option>
                                <option value="America/New_York" <?= $settings['timezone'] === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                            </select>
                        </div>
                    </div>

                    <!-- Working Hours -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Working Hours</h2>
                        
                        <div class="fw-calendar__form-row">
                            <div class="fw-calendar__form-group">
                                <label class="fw-calendar__label">Start Time</label>
                                <input type="time" 
                                       name="work_hours_start" 
                                       class="fw-calendar__input"
                                       value="<?= substr($settings['work_hours_start'], 0, 5) ?>">
                            </div>

                            <div class="fw-calendar__form-group">
                                <label class="fw-calendar__label">End Time</label>
                                <input type="time" 
                                       name="work_hours_end" 
                                       class="fw-calendar__input"
                                       value="<?= substr($settings['work_hours_end'], 0, 5) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Reminders -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Default Reminders</h2>
                        
                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label">Default reminder time for new events</label>
                            <select name="default_reminder_minutes" class="fw-calendar__input">
                                <option value="0" <?= $settings['default_reminder_minutes'] == 0 ? 'selected' : '' ?>>None</option>
                                <option value="5" <?= $settings['default_reminder_minutes'] == 5 ? 'selected' : '' ?>>5 minutes before</option>
                                <option value="15" <?= $settings['default_reminder_minutes'] == 15 ? 'selected' : '' ?>>15 minutes before</option>
                                <option value="30" <?= $settings['default_reminder_minutes'] == 30 ? 'selected' : '' ?>>30 minutes before</option>
                                <option value="60" <?= $settings['default_reminder_minutes'] == 60 ? 'selected' : '' ?>>1 hour before</option>
                                <option value="1440" <?= $settings['default_reminder_minutes'] == 1440 ? 'selected' : '' ?>>1 day before</option>
                            </select>
                        </div>
                    </div>

                    <!-- Integrations -->
                    <div class="fw-calendar__form-section">
                        <h2 class="fw-calendar__form-section-title">Integrations</h2>
                        
                        <!-- Integration Toggles -->
                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label" for="enable_invoice_due">Invoice Due Events</label>
                            <select name="enable_invoice_due" id="enable_invoice_due" class="fw-calendar__input">
                                <option value="1" <?= (int)($settings['enable_invoice_due'] ?? 1) === 1 ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= (int)($settings['enable_invoice_due'] ?? 1) === 0 ? 'selected' : '' ?>>Disabled</option>
                            </select>
                            <small class="fw-calendar__help-text">
                                Automatically create events for invoice due dates
                            </small>
                        </div>

                        <div class="fw-calendar__form-group">
                            <label class="fw-calendar__label" for="enable_project_dates">Project Date Events</label>
                            <select name="enable_project_dates" id="enable_project_dates" class="fw-calendar__input">
                                <option value="1" <?= (int)($settings['enable_project_dates'] ?? 1) === 1 ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= (int)($settings['enable_project_dates'] ?? 1) === 0 ? 'selected' : '' ?>>Disabled</option>
                            </select>
                            <small class="fw-calendar__help-text">
                                Automatically create events for project start and end dates
                            </small>
                        </div>

                        <!-- Manual sync buttons for convenience -->
                        <div class="fw-calendar__form-group">
                            <button type="button" class="fw-calendar__btn fw-calendar__btn--secondary" id="btnSyncProjects">
                                🔄 Sync Project Dates
                            </button>
                            <small class="fw-calendar__help-text">
                                Import project start/end dates as calendar events
                            </small>
                        </div>

                        <div class="fw-calendar__form-group">
                            <button type="button" class="fw-calendar__btn fw-calendar__btn--secondary" id="btnSyncInvoices">
                                🔄 Sync Invoice Due Dates
                            </button>
                            <small class="fw-calendar__help-text">
                                Import unpaid invoice due dates as calendar events
                            </small>
                        </div>

                        <div class="fw-calendar__form-group">
                            <button type="button" class="fw-calendar__btn fw-calendar__btn--secondary" id="btnSyncBoardItems">
                                🔄 Sync Board Item Due Dates
                            </button>
                            <small class="fw-calendar__help-text">
                                Import board task due dates as calendar events
                            </small>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="fw-calendar__form-actions">
                        <a href="/calendar/" class="fw-calendar__btn fw-calendar__btn--secondary">Cancel</a>
                        <button type="submit" class="fw-calendar__btn fw-calendar__btn--primary">Save Settings</button>
                    </div>

                    <div id="formMessage" class="fw-calendar__form-message" style="display: none;"></div>

                </form>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-calendar__footer">
            <span>Calendar v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/calendar/assets/calendar.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/calendar/assets/settings.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>