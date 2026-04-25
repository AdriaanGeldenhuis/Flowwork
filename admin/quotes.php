<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

require_once __DIR__ . '/../includes/companies.php';
fw_require_admin();

// Handle form submission
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_quotes') {
    try {
        $settings = [
            'qi_quote_prefix' => trim($_POST['qi_quote_prefix'] ?? 'QUO'),
            'qi_invoice_prefix' => trim($_POST['qi_invoice_prefix'] ?? 'INV'),
            'qi_credit_note_prefix' => trim($_POST['qi_credit_note_prefix'] ?? 'CN'),
            'qi_next_quote_number' => trim($_POST['qi_next_quote_number'] ?? '1'),
            'qi_next_invoice_number' => trim($_POST['qi_next_invoice_number'] ?? '1'),
            'qi_default_validity_days' => trim($_POST['qi_default_validity_days'] ?? '30'),
            'qi_default_payment_terms' => trim($_POST['qi_default_payment_terms'] ?? '30'),
            'qi_footer_text' => trim($_POST['qi_footer_text'] ?? ''),
            'qi_notes_text' => trim($_POST['qi_notes_text'] ?? ''),
            'qi_show_vat' => isset($_POST['qi_show_vat']) ? '1' : '0',
            'qi_show_discount' => isset($_POST['qi_show_discount']) ? '1' : '0',
            'qi_auto_convert_quote' => isset($_POST['qi_auto_convert_quote']) ? '1' : '0',
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'quotes_settings_updated', 'Updated quotes & invoicing settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Quotes & Invoicing settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'qi_quote_prefix' => getCRMSetting('qi_quote_prefix', 'QUO'),
    'qi_invoice_prefix' => getCRMSetting('qi_invoice_prefix', 'INV'),
    'qi_credit_note_prefix' => getCRMSetting('qi_credit_note_prefix', 'CN'),
    'qi_next_quote_number' => getCRMSetting('qi_next_quote_number', '1'),
    'qi_next_invoice_number' => getCRMSetting('qi_next_invoice_number', '1'),
    'qi_default_validity_days' => getCRMSetting('qi_default_validity_days', '30'),
    'qi_default_payment_terms' => getCRMSetting('qi_default_payment_terms', '30'),
    'qi_footer_text' => getCRMSetting('qi_footer_text', ''),
    'qi_notes_text' => getCRMSetting('qi_notes_text', ''),
    'qi_show_vat' => getCRMSetting('qi_show_vat', '1'),
    'qi_show_discount' => getCRMSetting('qi_show_discount', '1'),
    'qi_auto_convert_quote' => getCRMSetting('qi_auto_convert_quote', '0'),
];
?>
<?php
  $pageTitle = 'Quotes & Invoicing – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Quotes & Invoicing</h1>
                    <p class="fw-admin__page-subtitle">Configure document numbering, defaults, and templates</p>
                </div>
                <a href="/qi/settings.php" class="fw-admin__btn fw-admin__btn--secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    Template Settings
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
                <input type="hidden" name="action" value="save_quotes">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <!-- Numbering -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Document Numbering</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Quote Prefix</label>
                            <input type="text" name="qi_quote_prefix" class="fw-admin__input" value="<?= htmlspecialchars($s['qi_quote_prefix']) ?>" placeholder="QUO">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Next Quote Number</label>
                            <input type="number" name="qi_next_quote_number" class="fw-admin__input" value="<?= htmlspecialchars($s['qi_next_quote_number']) ?>" min="1">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Invoice Prefix</label>
                            <input type="text" name="qi_invoice_prefix" class="fw-admin__input" value="<?= htmlspecialchars($s['qi_invoice_prefix']) ?>" placeholder="INV">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Next Invoice Number</label>
                            <input type="number" name="qi_next_invoice_number" class="fw-admin__input" value="<?= htmlspecialchars($s['qi_next_invoice_number']) ?>" min="1">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Credit Note Prefix</label>
                            <input type="text" name="qi_credit_note_prefix" class="fw-admin__input" value="<?= htmlspecialchars($s['qi_credit_note_prefix']) ?>" placeholder="CN">
                        </div>
                    </div>
                </div>

                <!-- Defaults -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Defaults</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Quote Validity (days)</label>
                            <select name="qi_default_validity_days" class="fw-admin__select">
                                <option value="7" <?= $s['qi_default_validity_days'] === '7' ? 'selected' : '' ?>>7 days</option>
                                <option value="14" <?= $s['qi_default_validity_days'] === '14' ? 'selected' : '' ?>>14 days</option>
                                <option value="30" <?= $s['qi_default_validity_days'] === '30' ? 'selected' : '' ?>>30 days</option>
                                <option value="60" <?= $s['qi_default_validity_days'] === '60' ? 'selected' : '' ?>>60 days</option>
                                <option value="90" <?= $s['qi_default_validity_days'] === '90' ? 'selected' : '' ?>>90 days</option>
                            </select>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Payment Terms (days)</label>
                            <select name="qi_default_payment_terms" class="fw-admin__select">
                                <option value="0" <?= $s['qi_default_payment_terms'] === '0' ? 'selected' : '' ?>>Due on Receipt</option>
                                <option value="7" <?= $s['qi_default_payment_terms'] === '7' ? 'selected' : '' ?>>Net 7</option>
                                <option value="14" <?= $s['qi_default_payment_terms'] === '14' ? 'selected' : '' ?>>Net 14</option>
                                <option value="30" <?= $s['qi_default_payment_terms'] === '30' ? 'selected' : '' ?>>Net 30</option>
                                <option value="60" <?= $s['qi_default_payment_terms'] === '60' ? 'selected' : '' ?>>Net 60</option>
                            </select>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="qi_show_vat" value="1" <?= $s['qi_show_vat'] === '1' ? 'checked' : '' ?>>
                                <span>Show VAT breakdown on documents</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="qi_show_discount" value="1" <?= $s['qi_show_discount'] === '1' ? 'checked' : '' ?>>
                                <span>Allow line-item discounts</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="qi_auto_convert_quote" value="1" <?= $s['qi_auto_convert_quote'] === '1' ? 'checked' : '' ?>>
                                <span>Auto-convert accepted quotes to invoices</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Footer & Notes -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Default Text</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Default Notes</label>
                            <textarea name="qi_notes_text" class="fw-admin__textarea" rows="3" placeholder="e.g. Thank you for your business"><?= htmlspecialchars($s['qi_notes_text']) ?></textarea>
                        </div>
                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__label">Footer Text</label>
                            <textarea name="qi_footer_text" class="fw-admin__textarea" rows="3" placeholder="e.g. Banking details, terms and conditions..."><?= htmlspecialchars($s['qi_footer_text']) ?></textarea>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Settings</button>
                    </div>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
