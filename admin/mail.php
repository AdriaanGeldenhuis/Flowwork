<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../includes/companies.php';
fw_require_admin();

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mail') {
    try {
        $settings = [
            'mail_smtp_host' => trim($_POST['mail_smtp_host'] ?? ''),
            'mail_smtp_port' => trim($_POST['mail_smtp_port'] ?? '587'),
            'mail_smtp_encryption' => trim($_POST['mail_smtp_encryption'] ?? 'tls'),
            'mail_smtp_username' => trim($_POST['mail_smtp_username'] ?? ''),
            'mail_from_name' => trim($_POST['mail_from_name'] ?? ''),
            'mail_from_email' => trim($_POST['mail_from_email'] ?? ''),
            'mail_reply_to' => trim($_POST['mail_reply_to'] ?? ''),
            'mail_signature' => trim($_POST['mail_signature'] ?? ''),
            'mail_track_opens' => isset($_POST['mail_track_opens']) ? '1' : '0',
            'mail_track_clicks' => isset($_POST['mail_track_clicks']) ? '1' : '0',
        ];

        // Only update password if provided
        if (!empty($_POST['mail_smtp_password'])) {
            $settings['mail_smtp_password'] = trim($_POST['mail_smtp_password']);
        }

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'mail_settings_updated', 'Updated mail settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Mail settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'mail_smtp_host' => getCRMSetting('mail_smtp_host', ''),
    'mail_smtp_port' => getCRMSetting('mail_smtp_port', '587'),
    'mail_smtp_encryption' => getCRMSetting('mail_smtp_encryption', 'tls'),
    'mail_smtp_username' => getCRMSetting('mail_smtp_username', ''),
    'mail_smtp_password' => getCRMSetting('mail_smtp_password', ''),
    'mail_from_name' => getCRMSetting('mail_from_name', ''),
    'mail_from_email' => getCRMSetting('mail_from_email', ''),
    'mail_reply_to' => getCRMSetting('mail_reply_to', ''),
    'mail_signature' => getCRMSetting('mail_signature', ''),
    'mail_track_opens' => getCRMSetting('mail_track_opens', '0'),
    'mail_track_clicks' => getCRMSetting('mail_track_clicks', '0'),
];
?>
<?php
  $pageTitle = 'Mail Settings – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Mail Settings</h1>
                    <p class="fw-admin__page-subtitle">Configure email sending, SMTP, and default templates</p>
                </div>
                <a href="/mail/settings.php" class="fw-admin__btn fw-admin__btn--secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    Email Accounts
                </a>
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
                <input type="hidden" name="action" value="save_mail">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <!-- Sender Settings -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Sender Details</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">From Name</label>
                            <input type="text" name="mail_from_name" class="fw-admin__input" value="<?= htmlspecialchars($s['mail_from_name']) ?>" placeholder="Your Company Name">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">From Email</label>
                            <input type="email" name="mail_from_email" class="fw-admin__input" value="<?= htmlspecialchars($s['mail_from_email']) ?>" placeholder="noreply@yourcompany.co.za">
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Reply-To Email</label>
                            <input type="email" name="mail_reply_to" class="fw-admin__input" value="<?= htmlspecialchars($s['mail_reply_to']) ?>" placeholder="info@yourcompany.co.za">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">Leave empty to use the From email</small>
                        </div>
                    </div>
                </div>

                <!-- SMTP -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">SMTP Configuration</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">SMTP Host</label>
                            <input type="text" name="mail_smtp_host" class="fw-admin__input" value="<?= htmlspecialchars($s['mail_smtp_host']) ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">SMTP Port</label>
                            <input type="number" name="mail_smtp_port" class="fw-admin__input" value="<?= htmlspecialchars($s['mail_smtp_port']) ?>" placeholder="587">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Encryption</label>
                            <select name="mail_smtp_encryption" class="fw-admin__select">
                                <option value="tls" <?= $s['mail_smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl" <?= $s['mail_smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                <option value="none" <?= $s['mail_smtp_encryption'] === 'none' ? 'selected' : '' ?>>None</option>
                            </select>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Username</label>
                            <input type="text" name="mail_smtp_username" class="fw-admin__input" value="<?= htmlspecialchars($s['mail_smtp_username']) ?>" placeholder="your@email.com">
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Password</label>
                            <input type="password" name="mail_smtp_password" class="fw-admin__input" placeholder="<?= $s['mail_smtp_password'] ? '••••••••' : 'Enter SMTP password' ?>">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">Leave empty to keep current password</small>
                        </div>
                    </div>
                </div>

                <!-- Tracking & Signature -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Options</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="mail_track_opens" value="1" <?= $s['mail_track_opens'] === '1' ? 'checked' : '' ?>>
                                <span>Track email opens</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="mail_track_clicks" value="1" <?= $s['mail_track_clicks'] === '1' ? 'checked' : '' ?>>
                                <span>Track link clicks</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Email Signature</label>
                            <textarea name="mail_signature" class="fw-admin__textarea" rows="4" placeholder="Default email signature appended to outgoing emails..."><?= htmlspecialchars($s['mail_signature']) ?></textarea>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Mail Settings</button>
                    </div>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
