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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_automations') {
    try {
        $settings = [
            'auto_invoice_reminders' => isset($_POST['auto_invoice_reminders']) ? '1' : '0',
            'auto_reminder_days_before' => trim($_POST['auto_reminder_days_before'] ?? '3'),
            'auto_reminder_days_after' => trim($_POST['auto_reminder_days_after'] ?? '7'),
            'auto_overdue_notifications' => isset($_POST['auto_overdue_notifications']) ? '1' : '0',
            'auto_welcome_email' => isset($_POST['auto_welcome_email']) ? '1' : '0',
            'auto_task_assignment_notify' => isset($_POST['auto_task_assignment_notify']) ? '1' : '0',
            'auto_project_deadline_warn' => isset($_POST['auto_project_deadline_warn']) ? '1' : '0',
            'auto_deadline_warn_days' => trim($_POST['auto_deadline_warn_days'] ?? '3'),
            'auto_compliance_expiry_warn' => isset($_POST['auto_compliance_expiry_warn']) ? '1' : '0',
            'auto_compliance_warn_days' => trim($_POST['auto_compliance_warn_days'] ?? '30'),
            'auto_daily_digest' => isset($_POST['auto_daily_digest']) ? '1' : '0',
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'automations_settings_updated', 'Updated automation settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Automation settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'auto_invoice_reminders' => getCRMSetting('auto_invoice_reminders', '0'),
    'auto_reminder_days_before' => getCRMSetting('auto_reminder_days_before', '3'),
    'auto_reminder_days_after' => getCRMSetting('auto_reminder_days_after', '7'),
    'auto_overdue_notifications' => getCRMSetting('auto_overdue_notifications', '1'),
    'auto_welcome_email' => getCRMSetting('auto_welcome_email', '1'),
    'auto_task_assignment_notify' => getCRMSetting('auto_task_assignment_notify', '1'),
    'auto_project_deadline_warn' => getCRMSetting('auto_project_deadline_warn', '1'),
    'auto_deadline_warn_days' => getCRMSetting('auto_deadline_warn_days', '3'),
    'auto_compliance_expiry_warn' => getCRMSetting('auto_compliance_expiry_warn', '1'),
    'auto_compliance_warn_days' => getCRMSetting('auto_compliance_warn_days', '30'),
    'auto_daily_digest' => getCRMSetting('auto_daily_digest', '0'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automations – Admin</title>
    <link rel="stylesheet" href="/admin/style.css?v=2025-01-21-1">
</head>
<body>
<div class="fw-admin">
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="fw-admin__main">
        <div class="fw-admin__container">

            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Automations</h1>
                    <p class="fw-admin__page-subtitle">Configure automated notifications, reminders, and workflows</p>
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
                <input type="hidden" name="action" value="save_automations">

                <!-- Invoice Automations -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Invoice & Payment Reminders</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="auto_invoice_reminders" value="1" <?= $s['auto_invoice_reminders'] === '1' ? 'checked' : '' ?>>
                                <span>Send automatic payment reminders before due date</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Days Before Due Date</label>
                            <input type="number" name="auto_reminder_days_before" class="fw-admin__input" value="<?= htmlspecialchars($s['auto_reminder_days_before']) ?>" min="1" max="30">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Days After Due Date (follow-up)</label>
                            <input type="number" name="auto_reminder_days_after" class="fw-admin__input" value="<?= htmlspecialchars($s['auto_reminder_days_after']) ?>" min="1" max="90">
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="auto_overdue_notifications" value="1" <?= $s['auto_overdue_notifications'] === '1' ? 'checked' : '' ?>>
                                <span>Notify admin when invoices become overdue</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Team Automations -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Team Notifications</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="auto_welcome_email" value="1" <?= $s['auto_welcome_email'] === '1' ? 'checked' : '' ?>>
                                <span>Send welcome email when new users are added</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="auto_task_assignment_notify" value="1" <?= $s['auto_task_assignment_notify'] === '1' ? 'checked' : '' ?>>
                                <span>Notify users when assigned to tasks</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="auto_daily_digest" value="1" <?= $s['auto_daily_digest'] === '1' ? 'checked' : '' ?>>
                                <span>Send daily activity digest to admins</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Deadline & Compliance -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Deadlines & Compliance</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="auto_project_deadline_warn" value="1" <?= $s['auto_project_deadline_warn'] === '1' ? 'checked' : '' ?>>
                                <span>Warn when project deadlines are approaching</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Deadline Warning (days before)</label>
                            <input type="number" name="auto_deadline_warn_days" class="fw-admin__input" value="<?= htmlspecialchars($s['auto_deadline_warn_days']) ?>" min="1" max="30">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">&nbsp;</label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="auto_compliance_expiry_warn" value="1" <?= $s['auto_compliance_expiry_warn'] === '1' ? 'checked' : '' ?>>
                                <span>Warn when supplier compliance documents are expiring</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Compliance Warning (days before expiry)</label>
                            <input type="number" name="auto_compliance_warn_days" class="fw-admin__input" value="<?= htmlspecialchars($s['auto_compliance_warn_days']) ?>" min="1" max="90">
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Automation Settings</button>
                    </div>
                </div>
            </form>

        </div>
    </main>
</div>

<script src="/admin/admin.js?v=2025-01-21-1"></script>
</body>
</html>
