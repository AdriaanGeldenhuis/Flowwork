<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Check admin access
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Access denied - Admin only');
}

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_calendar') {
    try {
        $settings = [
            'calendar_timezone' => trim($_POST['calendar_timezone'] ?? 'Africa/Johannesburg'),
            'calendar_week_start' => trim($_POST['calendar_week_start'] ?? '1'),
            'calendar_work_hours_start' => trim($_POST['calendar_work_hours_start'] ?? '08:00'),
            'calendar_work_hours_end' => trim($_POST['calendar_work_hours_end'] ?? '17:00'),
            'calendar_default_view' => trim($_POST['calendar_default_view'] ?? 'week'),
            'calendar_default_reminder' => trim($_POST['calendar_default_reminder'] ?? '15'),
            'calendar_show_weekends' => isset($_POST['calendar_show_weekends']) ? '1' : '0',
            'calendar_sync_invoice_due' => isset($_POST['calendar_sync_invoice_due']) ? '1' : '0',
            'calendar_sync_project_dates' => isset($_POST['calendar_sync_project_dates']) ? '1' : '0',
            'calendar_sync_payroll_dates' => isset($_POST['calendar_sync_payroll_dates']) ? '1' : '0',
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'calendar_settings_updated', 'Updated calendar settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Calendar settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'calendar_timezone' => getCRMSetting('calendar_timezone', 'Africa/Johannesburg'),
    'calendar_week_start' => getCRMSetting('calendar_week_start', '1'),
    'calendar_work_hours_start' => getCRMSetting('calendar_work_hours_start', '08:00'),
    'calendar_work_hours_end' => getCRMSetting('calendar_work_hours_end', '17:00'),
    'calendar_default_view' => getCRMSetting('calendar_default_view', 'week'),
    'calendar_default_reminder' => getCRMSetting('calendar_default_reminder', '15'),
    'calendar_show_weekends' => getCRMSetting('calendar_show_weekends', '1'),
    'calendar_sync_invoice_due' => getCRMSetting('calendar_sync_invoice_due', '1'),
    'calendar_sync_project_dates' => getCRMSetting('calendar_sync_project_dates', '1'),
    'calendar_sync_payroll_dates' => getCRMSetting('calendar_sync_payroll_dates', '0'),
];

$timezones = ['Africa/Johannesburg','Africa/Cairo','Africa/Lagos','Africa/Nairobi','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Europe/London','Europe/Paris','Europe/Berlin','Asia/Dubai','Asia/Singapore','Asia/Tokyo','Australia/Sydney','Pacific/Auckland'];
?>
<?php
  $pageTitle = 'Calendar Settings – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Calendar Settings</h1>
                    <p class="fw-admin__page-subtitle">Configure work hours, timezone, and calendar defaults</p>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="fw-admin__alert fw-admin__alert--success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="fw-admin__alert fw-admin__alert--error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="save_calendar">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <!-- General -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">General</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Timezone</label>
                            <select name="calendar_timezone" class="fw-admin__select">
                                <?php foreach ($timezones as $tz): ?>
                                <option value="<?= $tz ?>" <?= $s['calendar_timezone'] === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Week Starts On</label>
                            <select name="calendar_week_start" class="fw-admin__select">
                                <option value="0" <?= $s['calendar_week_start'] === '0' ? 'selected' : '' ?>>Sunday</option>
                                <option value="1" <?= $s['calendar_week_start'] === '1' ? 'selected' : '' ?>>Monday</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default View</label>
                            <select name="calendar_default_view" class="fw-admin__select">
                                <option value="day" <?= $s['calendar_default_view'] === 'day' ? 'selected' : '' ?>>Day</option>
                                <option value="week" <?= $s['calendar_default_view'] === 'week' ? 'selected' : '' ?>>Week</option>
                                <option value="month" <?= $s['calendar_default_view'] === 'month' ? 'selected' : '' ?>>Month</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default Reminder</label>
                            <select name="calendar_default_reminder" class="fw-admin__select">
                                <option value="0" <?= $s['calendar_default_reminder'] === '0' ? 'selected' : '' ?>>None</option>
                                <option value="5" <?= $s['calendar_default_reminder'] === '5' ? 'selected' : '' ?>>5 minutes</option>
                                <option value="15" <?= $s['calendar_default_reminder'] === '15' ? 'selected' : '' ?>>15 minutes</option>
                                <option value="30" <?= $s['calendar_default_reminder'] === '30' ? 'selected' : '' ?>>30 minutes</option>
                                <option value="60" <?= $s['calendar_default_reminder'] === '60' ? 'selected' : '' ?>>1 hour</option>
                                <option value="1440" <?= $s['calendar_default_reminder'] === '1440' ? 'selected' : '' ?>>1 day</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Work Hours -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Work Hours</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Start Time</label>
                            <input type="time" name="calendar_work_hours_start" class="fw-admin__input" value="<?= htmlspecialchars($s['calendar_work_hours_start']) ?>">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">End Time</label>
                            <input type="time" name="calendar_work_hours_end" class="fw-admin__input" value="<?= htmlspecialchars($s['calendar_work_hours_end']) ?>">
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="calendar_show_weekends" value="1" <?= $s['calendar_show_weekends'] === '1' ? 'checked' : '' ?>>
                                <span>Show weekends in calendar views</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Integrations -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Calendar Integrations</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="calendar_sync_invoice_due" value="1" <?= $s['calendar_sync_invoice_due'] === '1' ? 'checked' : '' ?>>
                                <span>Show invoice due dates on calendar</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="calendar_sync_project_dates" value="1" <?= $s['calendar_sync_project_dates'] === '1' ? 'checked' : '' ?>>
                                <span>Show project deadlines on calendar</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="calendar_sync_payroll_dates" value="1" <?= $s['calendar_sync_payroll_dates'] === '1' ? 'checked' : '' ?>>
                                <span>Show payroll dates on calendar</span>
                            </label>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Calendar Settings</button>
                    </div>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
