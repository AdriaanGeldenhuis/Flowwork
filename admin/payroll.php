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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_payroll') {
    try {
        $settings = [
            'payroll_default_frequency' => trim($_POST['payroll_default_frequency'] ?? 'monthly'),
            'payroll_monthly_anchor_day' => trim($_POST['payroll_monthly_anchor_day'] ?? '25'),
            'payroll_bank_file_format' => trim($_POST['payroll_bank_file_format'] ?? 'standard_bank_csv'),
            'payroll_rounding' => trim($_POST['payroll_rounding'] ?? 'nearest'),
            'payroll_auto_post_finance' => isset($_POST['payroll_auto_post_finance']) ? '1' : '0',
            'payroll_require_approval' => isset($_POST['payroll_require_approval']) ? '1' : '0',
            'payroll_variance_threshold' => trim($_POST['payroll_variance_threshold'] ?? '10'),
            'payroll_default_wage_gl' => trim($_POST['payroll_default_wage_gl'] ?? '6000'),
            'payroll_default_paye_gl' => trim($_POST['payroll_default_paye_gl'] ?? '2100'),
            'payroll_default_uif_gl' => trim($_POST['payroll_default_uif_gl'] ?? '2101'),
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'payroll_settings_updated', 'Updated payroll settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Payroll settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'payroll_default_frequency' => getCRMSetting('payroll_default_frequency', 'monthly'),
    'payroll_monthly_anchor_day' => getCRMSetting('payroll_monthly_anchor_day', '25'),
    'payroll_bank_file_format' => getCRMSetting('payroll_bank_file_format', 'standard_bank_csv'),
    'payroll_rounding' => getCRMSetting('payroll_rounding', 'nearest'),
    'payroll_auto_post_finance' => getCRMSetting('payroll_auto_post_finance', '0'),
    'payroll_require_approval' => getCRMSetting('payroll_require_approval', '1'),
    'payroll_variance_threshold' => getCRMSetting('payroll_variance_threshold', '10'),
    'payroll_default_wage_gl' => getCRMSetting('payroll_default_wage_gl', '6000'),
    'payroll_default_paye_gl' => getCRMSetting('payroll_default_paye_gl', '2100'),
    'payroll_default_uif_gl' => getCRMSetting('payroll_default_uif_gl', '2101'),
];
?>
<?php
  $pageTitle = 'Payroll Settings – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Payroll Settings</h1>
                    <p class="fw-admin__page-subtitle">Configure pay periods, tax, and payroll processing</p>
                </div>
                <a href="/payroll/settings.php" class="fw-admin__btn fw-admin__btn--secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    Advanced Settings
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
                <input type="hidden" name="action" value="save_payroll">

                <!-- Pay Schedule -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Pay Schedule</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default Pay Frequency</label>
                            <select name="payroll_default_frequency" class="fw-admin__select">
                                <option value="monthly" <?= $s['payroll_default_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="fortnightly" <?= $s['payroll_default_frequency'] === 'fortnightly' ? 'selected' : '' ?>>Fortnightly</option>
                                <option value="weekly" <?= $s['payroll_default_frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Monthly Pay Day</label>
                            <input type="number" name="payroll_monthly_anchor_day" class="fw-admin__input" value="<?= htmlspecialchars($s['payroll_monthly_anchor_day']) ?>" min="1" max="31">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">Day of month salaries are paid</small>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Bank File Format</label>
                            <select name="payroll_bank_file_format" class="fw-admin__select">
                                <option value="standard_bank_csv" <?= $s['payroll_bank_file_format'] === 'standard_bank_csv' ? 'selected' : '' ?>>Standard Bank CSV</option>
                                <option value="fnb_csv" <?= $s['payroll_bank_file_format'] === 'fnb_csv' ? 'selected' : '' ?>>FNB CSV</option>
                                <option value="absa_csv" <?= $s['payroll_bank_file_format'] === 'absa_csv' ? 'selected' : '' ?>>ABSA CSV</option>
                                <option value="nedbank_csv" <?= $s['payroll_bank_file_format'] === 'nedbank_csv' ? 'selected' : '' ?>>Nedbank CSV</option>
                                <option value="generic_csv" <?= $s['payroll_bank_file_format'] === 'generic_csv' ? 'selected' : '' ?>>Generic CSV</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Rounding</label>
                            <select name="payroll_rounding" class="fw-admin__select">
                                <option value="nearest" <?= $s['payroll_rounding'] === 'nearest' ? 'selected' : '' ?>>Nearest Cent</option>
                                <option value="down" <?= $s['payroll_rounding'] === 'down' ? 'selected' : '' ?>>Round Down</option>
                                <option value="up" <?= $s['payroll_rounding'] === 'up' ? 'selected' : '' ?>>Round Up</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Processing -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Processing</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Variance Alert Threshold (%)</label>
                            <input type="number" name="payroll_variance_threshold" class="fw-admin__input" value="<?= htmlspecialchars($s['payroll_variance_threshold']) ?>" min="1" max="100">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">Alert when payroll cost varies by more than this % from last run</small>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">&nbsp;</label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="payroll_require_approval" value="1" <?= $s['payroll_require_approval'] === '1' ? 'checked' : '' ?>>
                                <span>Require approval before processing payroll runs</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="payroll_auto_post_finance" value="1" <?= $s['payroll_auto_post_finance'] === '1' ? 'checked' : '' ?>>
                                <span>Auto-post payroll journals to Finance module</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- GL Codes -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Default GL Codes</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Wages & Salaries</label>
                            <input type="text" name="payroll_default_wage_gl" class="fw-admin__input" value="<?= htmlspecialchars($s['payroll_default_wage_gl']) ?>" placeholder="6000">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">PAYE Tax</label>
                            <input type="text" name="payroll_default_paye_gl" class="fw-admin__input" value="<?= htmlspecialchars($s['payroll_default_paye_gl']) ?>" placeholder="2100">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">UIF</label>
                            <input type="text" name="payroll_default_uif_gl" class="fw-admin__input" value="<?= htmlspecialchars($s['payroll_default_uif_gl']) ?>" placeholder="2101">
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Payroll Settings</button>
                    </div>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
