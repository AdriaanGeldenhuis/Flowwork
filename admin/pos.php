<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../includes/companies.php';
fw_require_admin();

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pos') {
    try {
        $settings = [
            'pos_enabled' => isset($_POST['pos_enabled']) ? '1' : '0',
            'pos_receipt_header' => trim($_POST['pos_receipt_header'] ?? ''),
            'pos_receipt_footer' => trim($_POST['pos_receipt_footer'] ?? ''),
            'pos_default_tax_rate' => trim($_POST['pos_default_tax_rate'] ?? '15'),
            'pos_allow_discounts' => isset($_POST['pos_allow_discounts']) ? '1' : '0',
            'pos_max_discount_pct' => trim($_POST['pos_max_discount_pct'] ?? '20'),
            'pos_require_customer' => isset($_POST['pos_require_customer']) ? '1' : '0',
            'pos_auto_print_receipt' => isset($_POST['pos_auto_print_receipt']) ? '1' : '0',
            'pos_cash_rounding' => isset($_POST['pos_cash_rounding']) ? '1' : '0',
            'pos_show_stock_levels' => isset($_POST['pos_show_stock_levels']) ? '1' : '0',
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'pos_settings_updated', 'Updated POS settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'POS settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'pos_enabled' => getCRMSetting('pos_enabled', '0'),
    'pos_receipt_header' => getCRMSetting('pos_receipt_header', ''),
    'pos_receipt_footer' => getCRMSetting('pos_receipt_footer', ''),
    'pos_default_tax_rate' => getCRMSetting('pos_default_tax_rate', '15'),
    'pos_allow_discounts' => getCRMSetting('pos_allow_discounts', '1'),
    'pos_max_discount_pct' => getCRMSetting('pos_max_discount_pct', '20'),
    'pos_require_customer' => getCRMSetting('pos_require_customer', '0'),
    'pos_auto_print_receipt' => getCRMSetting('pos_auto_print_receipt', '1'),
    'pos_cash_rounding' => getCRMSetting('pos_cash_rounding', '1'),
    'pos_show_stock_levels' => getCRMSetting('pos_show_stock_levels', '1'),
];
?>
<?php
  $pageTitle = 'POS Settings – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Point of Sale</h1>
                    <p class="fw-admin__page-subtitle">Configure POS terminal, receipts, and sales defaults</p>
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
                <input type="hidden" name="action" value="save_pos">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <!-- General -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">General</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="pos_enabled" value="1" <?= $s['pos_enabled'] === '1' ? 'checked' : '' ?>>
                                <span>Enable Point of Sale module</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default Tax Rate (%)</label>
                            <input type="number" name="pos_default_tax_rate" class="fw-admin__input" value="<?= htmlspecialchars($s['pos_default_tax_rate']) ?>" min="0" max="100" step="0.5">
                        </div>

                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Max Discount (%)</label>
                            <input type="number" name="pos_max_discount_pct" class="fw-admin__input" value="<?= htmlspecialchars($s['pos_max_discount_pct']) ?>" min="0" max="100">
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="pos_allow_discounts" value="1" <?= $s['pos_allow_discounts'] === '1' ? 'checked' : '' ?>>
                                <span>Allow cashiers to apply discounts</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="pos_require_customer" value="1" <?= $s['pos_require_customer'] === '1' ? 'checked' : '' ?>>
                                <span>Require customer selection for each sale</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="pos_cash_rounding" value="1" <?= $s['pos_cash_rounding'] === '1' ? 'checked' : '' ?>>
                                <span>Enable cash rounding (nearest R0.05)</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="pos_show_stock_levels" value="1" <?= $s['pos_show_stock_levels'] === '1' ? 'checked' : '' ?>>
                                <span>Show stock levels on POS screen</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Receipt Settings -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Receipt</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="pos_auto_print_receipt" value="1" <?= $s['pos_auto_print_receipt'] === '1' ? 'checked' : '' ?>>
                                <span>Auto-print receipt after each sale</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Receipt Header</label>
                            <textarea name="pos_receipt_header" class="fw-admin__textarea" rows="3" placeholder="Text shown at the top of receipts"><?= htmlspecialchars($s['pos_receipt_header']) ?></textarea>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Receipt Footer</label>
                            <textarea name="pos_receipt_footer" class="fw-admin__textarea" rows="3" placeholder="e.g. Thank you for your purchase! No refunds after 30 days."><?= htmlspecialchars($s['pos_receipt_footer']) ?></textarea>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save POS Settings</button>
                    </div>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
