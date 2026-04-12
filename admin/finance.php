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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_finance') {
    try {
        $settings = [
            'finance_currency' => trim($_POST['finance_currency'] ?? 'ZAR'),
            'finance_fiscal_year_start' => trim($_POST['finance_fiscal_year_start'] ?? '03'),
            'finance_tax_rate' => trim($_POST['finance_tax_rate'] ?? '15'),
            'finance_default_payment_terms' => trim($_POST['finance_default_payment_terms'] ?? '30'),
            'finance_bank_name' => trim($_POST['finance_bank_name'] ?? ''),
            'finance_bank_account_number' => trim($_POST['finance_bank_account_number'] ?? ''),
            'finance_bank_branch_code' => trim($_POST['finance_bank_branch_code'] ?? ''),
            'finance_bank_account_type' => trim($_POST['finance_bank_account_type'] ?? 'cheque'),
            'finance_auto_reconcile' => isset($_POST['finance_auto_reconcile']) ? '1' : '0',
            'finance_require_approval' => isset($_POST['finance_require_approval']) ? '1' : '0',
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        // Save SARS company registration fields to companies table
        try {
            $stmt = $DB->prepare(
                "UPDATE companies SET vat_number = ?, reg_number = ?, tax_reference = ? WHERE id = ?"
            );
            $stmt->execute([
                trim($_POST['company_vat_number'] ?? ''),
                trim($_POST['company_reg_number'] ?? ''),
                trim($_POST['company_tax_reference'] ?? ''),
                $companyId
            ]);
        } catch (PDOException $e) {
            // Fallback if tax_reference column doesn't exist yet
            $stmt = $DB->prepare(
                "UPDATE companies SET vat_number = ?, reg_number = ? WHERE id = ?"
            );
            $stmt->execute([
                trim($_POST['company_vat_number'] ?? ''),
                trim($_POST['company_reg_number'] ?? ''),
                $companyId
            ]);
        }

        // Log audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'finance_settings_updated', 'Updated finance settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Finance settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load company SARS details (tax_reference may not exist if migration not yet applied)
try {
    $stmt = $DB->prepare("SELECT vat_number, reg_number, tax_reference FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyDetails = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Fallback if tax_reference column doesn't exist yet
    $stmt = $DB->prepare("SELECT vat_number, reg_number FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyDetails = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Load current settings
$settings = [
    'finance_currency' => getCRMSetting('finance_currency', 'ZAR'),
    'finance_fiscal_year_start' => getCRMSetting('finance_fiscal_year_start', '03'),
    'finance_tax_rate' => getCRMSetting('finance_tax_rate', '15'),
    'finance_default_payment_terms' => getCRMSetting('finance_default_payment_terms', '30'),
    'finance_bank_name' => getCRMSetting('finance_bank_name', ''),
    'finance_bank_account_number' => getCRMSetting('finance_bank_account_number', ''),
    'finance_bank_branch_code' => getCRMSetting('finance_bank_branch_code', ''),
    'finance_bank_account_type' => getCRMSetting('finance_bank_account_type', 'cheque'),
    'finance_auto_reconcile' => getCRMSetting('finance_auto_reconcile', '0'),
    'finance_require_approval' => getCRMSetting('finance_require_approval', '1'),
];
?>
<?php
  $pageTitle = 'Finance Settings – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Finance Settings</h1>
                    <p class="fw-admin__page-subtitle">Configure currency, tax, banking, and financial defaults</p>
                </div>
                <a href="/finances/settings.php" class="fw-admin__btn fw-admin__btn--secondary">
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
                <input type="hidden" name="action" value="save_finance">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <!-- General -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">General</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Currency</label>
                            <select name="finance_currency" class="fw-admin__select">
                                <option value="ZAR" <?= $settings['finance_currency'] === 'ZAR' ? 'selected' : '' ?>>ZAR - South African Rand</option>
                                <option value="USD" <?= $settings['finance_currency'] === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                                <option value="EUR" <?= $settings['finance_currency'] === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                                <option value="GBP" <?= $settings['finance_currency'] === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Fiscal Year Start Month</label>
                            <select name="finance_fiscal_year_start" class="fw-admin__select">
                                <?php
                                $months = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April','05'=>'May','06'=>'June','07'=>'July','08'=>'August','09'=>'September','10'=>'October','11'=>'November','12'=>'December'];
                                foreach ($months as $num => $name):
                                ?>
                                <option value="<?= $num ?>" <?= $settings['finance_fiscal_year_start'] === $num ? 'selected' : '' ?>><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default VAT/Tax Rate (%)</label>
                            <input type="number" name="finance_tax_rate" class="fw-admin__input" value="<?= htmlspecialchars($settings['finance_tax_rate']) ?>" min="0" max="100" step="0.5">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default Payment Terms (days)</label>
                            <select name="finance_default_payment_terms" class="fw-admin__select">
                                <option value="0" <?= $settings['finance_default_payment_terms'] === '0' ? 'selected' : '' ?>>Due on Receipt</option>
                                <option value="7" <?= $settings['finance_default_payment_terms'] === '7' ? 'selected' : '' ?>>Net 7</option>
                                <option value="14" <?= $settings['finance_default_payment_terms'] === '14' ? 'selected' : '' ?>>Net 14</option>
                                <option value="30" <?= $settings['finance_default_payment_terms'] === '30' ? 'selected' : '' ?>>Net 30</option>
                                <option value="60" <?= $settings['finance_default_payment_terms'] === '60' ? 'selected' : '' ?>>Net 60</option>
                                <option value="90" <?= $settings['finance_default_payment_terms'] === '90' ? 'selected' : '' ?>>Net 90</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="finance_require_approval" value="1" <?= $settings['finance_require_approval'] === '1' ? 'checked' : '' ?>>
                                <span>Require manager approval for transactions above a threshold</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="finance_auto_reconcile" value="1" <?= $settings['finance_auto_reconcile'] === '1' ? 'checked' : '' ?>>
                                <span>Enable automatic bank reconciliation matching</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- SARS Registration -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">SARS Registration</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">VAT Registration Number</label>
                            <input type="text" name="company_vat_number" class="fw-admin__input" value="<?= htmlspecialchars($companyDetails['vat_number'] ?? '') ?>" placeholder="e.g. 4123456789">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Company Registration (CIPC)</label>
                            <input type="text" name="company_reg_number" class="fw-admin__input" value="<?= htmlspecialchars($companyDetails['reg_number'] ?? '') ?>" placeholder="e.g. 2020/123456/07">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Income Tax Reference Number</label>
                            <input type="text" name="company_tax_reference" class="fw-admin__input" value="<?= htmlspecialchars($companyDetails['tax_reference'] ?? '') ?>" placeholder="e.g. 9123456789">
                        </div>
                    </div>
                </div>

                <!-- Banking Details -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Banking Details</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Bank Name</label>
                            <input type="text" name="finance_bank_name" class="fw-admin__input" value="<?= htmlspecialchars($settings['finance_bank_name']) ?>" placeholder="e.g. Standard Bank">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Account Type</label>
                            <select name="finance_bank_account_type" class="fw-admin__select">
                                <option value="cheque" <?= $settings['finance_bank_account_type'] === 'cheque' ? 'selected' : '' ?>>Cheque / Current</option>
                                <option value="savings" <?= $settings['finance_bank_account_type'] === 'savings' ? 'selected' : '' ?>>Savings</option>
                                <option value="transmission" <?= $settings['finance_bank_account_type'] === 'transmission' ? 'selected' : '' ?>>Transmission</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Account Number</label>
                            <input type="text" name="finance_bank_account_number" class="fw-admin__input" value="<?= htmlspecialchars($settings['finance_bank_account_number']) ?>" placeholder="Account number">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Branch Code</label>
                            <input type="text" name="finance_bank_branch_code" class="fw-admin__input" value="<?= htmlspecialchars($settings['finance_bank_branch_code']) ?>" placeholder="Branch code">
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Finance Settings</button>
                    </div>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
