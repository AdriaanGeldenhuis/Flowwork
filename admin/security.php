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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_security') {
    try {
        $settings = [
            'security_min_password_length' => trim($_POST['security_min_password_length'] ?? '8'),
            'security_require_uppercase' => isset($_POST['security_require_uppercase']) ? '1' : '0',
            'security_require_numbers' => isset($_POST['security_require_numbers']) ? '1' : '0',
            'security_require_special' => isset($_POST['security_require_special']) ? '1' : '0',
            'security_session_timeout' => trim($_POST['security_session_timeout'] ?? '480'),
            'security_max_login_attempts' => trim($_POST['security_max_login_attempts'] ?? '5'),
            'security_lockout_duration' => trim($_POST['security_lockout_duration'] ?? '15'),
            'security_force_single_session' => isset($_POST['security_force_single_session']) ? '1' : '0',
            'security_ip_whitelist' => trim($_POST['security_ip_whitelist'] ?? ''),
            'security_2fa_enabled' => isset($_POST['security_2fa_enabled']) ? '1' : '0',
            'security_password_expiry_days' => trim($_POST['security_password_expiry_days'] ?? '0'),
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'security_settings_updated', 'Updated security settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Security settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'security_min_password_length' => getCRMSetting('security_min_password_length', '8'),
    'security_require_uppercase' => getCRMSetting('security_require_uppercase', '1'),
    'security_require_numbers' => getCRMSetting('security_require_numbers', '1'),
    'security_require_special' => getCRMSetting('security_require_special', '0'),
    'security_session_timeout' => getCRMSetting('security_session_timeout', '480'),
    'security_max_login_attempts' => getCRMSetting('security_max_login_attempts', '5'),
    'security_lockout_duration' => getCRMSetting('security_lockout_duration', '15'),
    'security_force_single_session' => getCRMSetting('security_force_single_session', '1'),
    'security_ip_whitelist' => getCRMSetting('security_ip_whitelist', ''),
    'security_2fa_enabled' => getCRMSetting('security_2fa_enabled', '0'),
    'security_password_expiry_days' => getCRMSetting('security_password_expiry_days', '0'),
];

// Fetch recent login activity
$stmt = $DB->prepare("
    SELECT al.*, u.first_name, u.last_name, u.email
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.company_id = ? AND al.action IN ('login', 'login_failed', 'logout', 'password_changed')
    ORDER BY al.timestamp DESC
    LIMIT 20
");
$stmt->execute([$companyId]);
$loginActivity = $stmt->fetchAll();
?>
<?php
  $pageTitle = 'Security – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Security</h1>
                    <p class="fw-admin__page-subtitle">Password policies, session management, and access control</p>
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
                <input type="hidden" name="action" value="save_security">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <!-- Password Policy -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Password Policy</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Minimum Password Length</label>
                            <input type="number" name="security_min_password_length" class="fw-admin__input" value="<?= htmlspecialchars($s['security_min_password_length']) ?>" min="6" max="32">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Password Expiry (days)</label>
                            <input type="number" name="security_password_expiry_days" class="fw-admin__input" value="<?= htmlspecialchars($s['security_password_expiry_days']) ?>" min="0" max="365">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">Set to 0 to disable password expiry</small>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="security_require_uppercase" value="1" <?= $s['security_require_uppercase'] === '1' ? 'checked' : '' ?>>
                                <span>Require at least one uppercase letter</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="security_require_numbers" value="1" <?= $s['security_require_numbers'] === '1' ? 'checked' : '' ?>>
                                <span>Require at least one number</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="security_require_special" value="1" <?= $s['security_require_special'] === '1' ? 'checked' : '' ?>>
                                <span>Require at least one special character (!@#$%^&*)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Session & Login -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Session & Login</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Session Timeout (minutes)</label>
                            <select name="security_session_timeout" class="fw-admin__select">
                                <option value="30" <?= $s['security_session_timeout'] === '30' ? 'selected' : '' ?>>30 minutes</option>
                                <option value="60" <?= $s['security_session_timeout'] === '60' ? 'selected' : '' ?>>1 hour</option>
                                <option value="120" <?= $s['security_session_timeout'] === '120' ? 'selected' : '' ?>>2 hours</option>
                                <option value="240" <?= $s['security_session_timeout'] === '240' ? 'selected' : '' ?>>4 hours</option>
                                <option value="480" <?= $s['security_session_timeout'] === '480' ? 'selected' : '' ?>>8 hours</option>
                                <option value="1440" <?= $s['security_session_timeout'] === '1440' ? 'selected' : '' ?>>24 hours</option>
                            </select>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Max Login Attempts</label>
                            <input type="number" name="security_max_login_attempts" class="fw-admin__input" value="<?= htmlspecialchars($s['security_max_login_attempts']) ?>" min="3" max="20">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Lockout Duration (minutes)</label>
                            <input type="number" name="security_lockout_duration" class="fw-admin__input" value="<?= htmlspecialchars($s['security_lockout_duration']) ?>" min="1" max="60">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">&nbsp;</label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="security_force_single_session" value="1" <?= $s['security_force_single_session'] === '1' ? 'checked' : '' ?>>
                                <span>Force single session per user (new login logs out other sessions)</span>
                            </label>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="security_2fa_enabled" value="1" <?= $s['security_2fa_enabled'] === '1' ? 'checked' : '' ?>>
                                <span>Enable two-factor authentication (2FA)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- IP Whitelist -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">IP Whitelist</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Allowed IP Addresses</label>
                            <textarea name="security_ip_whitelist" class="fw-admin__textarea" rows="4" placeholder="Enter one IP address per line. Leave empty to allow all IPs."><?= htmlspecialchars($s['security_ip_whitelist']) ?></textarea>
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">One IP per line. Leave empty to allow access from any IP address.</small>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Security Settings</button>
                    </div>
                </div>
            </form>

            <!-- Recent Login Activity -->
            <div class="fw-admin__section">
                <h2 class="fw-admin__section-title">Recent Login Activity</h2>
                <div class="fw-admin__card">
                    <div class="fw-admin__table-wrapper">
                        <table class="fw-admin__table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($loginActivity)): ?>
                                <tr><td colspan="4" class="fw-admin__empty-state">No recent login activity</td></tr>
                                <?php else: ?>
                                <?php foreach ($loginActivity as $log): ?>
                                <tr>
                                    <td><?= date('M j, g:i A', strtotime($log['timestamp'])) ?></td>
                                    <td><?= htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?></td>
                                    <td>
                                        <span class="fw-admin__badge fw-admin__badge--<?= $log['action'] === 'login' ? 'success' : ($log['action'] === 'login_failed' ? 'danger' : 'muted') ?>">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </span>
                                    </td>
                                    <td><code style="font-size: 12px;"><?= htmlspecialchars($log['ip'] ?? '—') ?></code></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
