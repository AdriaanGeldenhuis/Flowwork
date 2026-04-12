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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_suppliers') {
    try {
        $settings = [
            'suppliers_default_radius_km' => trim($_POST['suppliers_default_radius_km'] ?? '25'),
            'suppliers_require_compliance' => isset($_POST['suppliers_require_compliance']) ? '1' : '0',
            'suppliers_block_expired' => isset($_POST['suppliers_block_expired']) ? '1' : '0',
            'suppliers_rfq_max_recipients' => trim($_POST['suppliers_rfq_max_recipients'] ?? '8'),
            'suppliers_min_score' => trim($_POST['suppliers_min_score'] ?? '0.55'),
            'suppliers_daily_search_cap' => trim($_POST['suppliers_daily_search_cap'] ?? '100'),
            'suppliers_default_payment_terms' => trim($_POST['suppliers_default_payment_terms'] ?? '30'),
            'suppliers_require_po' => isset($_POST['suppliers_require_po']) ? '1' : '0',
            'suppliers_po_approval_threshold' => trim($_POST['suppliers_po_approval_threshold'] ?? '5000'),
        ];

        foreach ($settings as $key => $value) {
            setCRMSetting($key, $value);
        }

        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
            VALUES (?, ?, 'suppliers_settings_updated', 'Updated supplier settings', NOW())
        ");
        $stmt->execute([$companyId, $userId]);

        $success = 'Supplier settings saved successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current settings
$s = [
    'suppliers_default_radius_km' => getCRMSetting('suppliers_default_radius_km', '25'),
    'suppliers_require_compliance' => getCRMSetting('suppliers_require_compliance', '1'),
    'suppliers_block_expired' => getCRMSetting('suppliers_block_expired', '1'),
    'suppliers_rfq_max_recipients' => getCRMSetting('suppliers_rfq_max_recipients', '8'),
    'suppliers_min_score' => getCRMSetting('suppliers_min_score', '0.55'),
    'suppliers_daily_search_cap' => getCRMSetting('suppliers_daily_search_cap', '100'),
    'suppliers_default_payment_terms' => getCRMSetting('suppliers_default_payment_terms', '30'),
    'suppliers_require_po' => getCRMSetting('suppliers_require_po', '1'),
    'suppliers_po_approval_threshold' => getCRMSetting('suppliers_po_approval_threshold', '5000'),
];
?>
<?php
  $pageTitle = 'Suppliers – Admin';
  include __DIR__ . '/_layout_top.php';
?>
            <header class="fw-admin__page-header">
                <div>
                    <h1 class="fw-admin__page-title">Suppliers</h1>
                    <p class="fw-admin__page-subtitle">Configure supplier search, compliance, and procurement rules</p>
                </div>
                <a href="/suppliers_ai/settings.php" class="fw-admin__btn fw-admin__btn--secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    AI Settings
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
                <input type="hidden" name="action" value="save_suppliers">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">

                <!-- Search Settings -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Search Settings</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default Search Radius (km)</label>
                            <input type="number" name="suppliers_default_radius_km" class="fw-admin__input" value="<?= htmlspecialchars($s['suppliers_default_radius_km']) ?>" min="1" max="500">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Min Match Score</label>
                            <input type="number" name="suppliers_min_score" class="fw-admin__input" value="<?= htmlspecialchars($s['suppliers_min_score']) ?>" min="0" max="1" step="0.05">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">Minimum relevance score (0-1) to show in results</small>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Daily Search Limit</label>
                            <input type="number" name="suppliers_daily_search_cap" class="fw-admin__input" value="<?= htmlspecialchars($s['suppliers_daily_search_cap']) ?>" min="1" max="1000">
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Max RFQ Recipients</label>
                            <input type="number" name="suppliers_rfq_max_recipients" class="fw-admin__input" value="<?= htmlspecialchars($s['suppliers_rfq_max_recipients']) ?>" min="1" max="50">
                        </div>
                    </div>
                </div>

                <!-- Compliance -->
                <div class="fw-admin__card">
                    <h2 class="fw-admin__card-title">Compliance & Procurement</h2>
                    <div class="fw-admin__form-grid">
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">Default Payment Terms (days)</label>
                            <select name="suppliers_default_payment_terms" class="fw-admin__select">
                                <option value="0" <?= $s['suppliers_default_payment_terms'] === '0' ? 'selected' : '' ?>>Due on Receipt</option>
                                <option value="7" <?= $s['suppliers_default_payment_terms'] === '7' ? 'selected' : '' ?>>Net 7</option>
                                <option value="14" <?= $s['suppliers_default_payment_terms'] === '14' ? 'selected' : '' ?>>Net 14</option>
                                <option value="30" <?= $s['suppliers_default_payment_terms'] === '30' ? 'selected' : '' ?>>Net 30</option>
                                <option value="60" <?= $s['suppliers_default_payment_terms'] === '60' ? 'selected' : '' ?>>Net 60</option>
                            </select>
                        </div>
                        <div class="fw-admin__form-group">
                            <label class="fw-admin__label">PO Approval Threshold (R)</label>
                            <input type="number" name="suppliers_po_approval_threshold" class="fw-admin__input" value="<?= htmlspecialchars($s['suppliers_po_approval_threshold']) ?>" min="0" step="100">
                            <small style="color: var(--fw-text-muted); font-size: 12px; margin-top: 4px; display: block;">Orders above this amount require approval</small>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="suppliers_require_compliance" value="1" <?= $s['suppliers_require_compliance'] === '1' ? 'checked' : '' ?>>
                                <span>Require compliance documents (BEE, Tax clearance) from suppliers</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="suppliers_block_expired" value="1" <?= $s['suppliers_block_expired'] === '1' ? 'checked' : '' ?>>
                                <span>Block suppliers with expired compliance documents</span>
                            </label>
                        </div>

                        <div class="fw-admin__form-group fw-admin__form-group--full">
                            <label class="fw-admin__checkbox">
                                <input type="checkbox" name="suppliers_require_po" value="1" <?= $s['suppliers_require_po'] === '1' ? 'checked' : '' ?>>
                                <span>Require purchase order for all supplier payments</span>
                            </label>
                        </div>
                    </div>

                    <div class="fw-admin__form-actions">
                        <button type="submit" class="fw-admin__btn fw-admin__btn--primary">Save Supplier Settings</button>
                    </div>
                </div>
            </form>
<?php include __DIR__ . '/_layout_bottom.php'; ?>
