<?php
// /qi/quote_new.php - COMPLETE WITH EDIT MODE
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/Currencies.php';
require_once __DIR__ . '/lib/LineHeadings.php';

define('ASSET_VERSION', QI_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check edit mode
$editMode = false;
$quoteId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$quoteData = null;
$lineItems = [];
$editRows = [];
$editMilestones = [];

if ($quoteId) {
    $editMode = true;
    
    $stmt = $DB->prepare("
        SELECT q.* 
        FROM quotes q
        WHERE q.id = ? AND q.company_id = ? AND q.status = 'draft'
    ");
    $stmt->execute([$quoteId, $companyId]);
    $quoteData = $stmt->fetch();
    
    if (!$quoteData) {
        die('Quote not found or cannot be edited');
    }
    
    // Join the tax code so the per-line VAT dropdown can restore the exact
    // choice (the two 0% options — zero-rated vs exempt — can't be told apart
    // by rate alone).
    $stmt = $DB->prepare(
        "SELECT ql.*, tc.code AS tax_code
           FROM quote_lines ql
           LEFT JOIN gl_tax_codes tc ON tc.tax_code_id = ql.tax_code_id
          WHERE ql.quote_id = ? ORDER BY ql.sort_order"
    );
    $stmt->execute([$quoteId]);
    $lineItems = $stmt->fetchAll();

    // Interleave stored section headings so the editor shows rows in
    // document order (each row carries _kind = 'item' | 'heading')
    $editRows = LineHeadings::merge($lineItems, LineHeadings::fetch($DB, LineHeadings::TYPE_QUOTE, $quoteId));

    $stmt = $DB->prepare("
        SELECT label, percentage, amount, due_date
        FROM payment_milestones
        WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ?
        ORDER BY sort_order
    ");
    $stmt->execute([$quoteId, $companyId]);
    foreach ($stmt->fetchAll() as $ms) {
        $editMilestones[] = [
            'label' => (string)$ms['label'],
            'percentage' => (float)$ms['percentage'],
            'due_date' => $ms['due_date'] ?: null,
        ];
    }
}

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// Load QI settings for defaults
$stmt = $DB->prepare("SELECT * FROM qi_settings WHERE company_id = ? LIMIT 1");
$stmt->execute([$companyId]);
$qiSettings = $stmt->fetch();
$defaultTerms = $qiSettings['default_terms'] ?? '';
$defaultTaxRate = isset($qiSettings['default_tax_rate']) && is_numeric($qiSettings['default_tax_rate']) ? floatval($qiSettings['default_tax_rate']) : 15.0;

// Per-line VAT dropdown. The two 0% options (zero-rated vs exempt) carry the
// SARS tax code so the VAT201 stays correct; the labels read as a plain
// "VAT / No VAT" choice. Selection restores by tax_code on edit, falling back
// to the stored rate when a line has no code yet.
if (!function_exists('qi_line_tax_select')) {
    function qi_line_tax_select(?string $selectedCode, $rate = null): string {
        $options = [
            ['15.00', 'STD',    'VAT (15%)'],
            ['0.00',  'ZERO',   'No VAT (Zero-rated)'],
            ['0.00',  'EXEMPT', 'No VAT (Exempt)'],
        ];
        $sel = $selectedCode ?: (($rate !== null && (float)$rate == 0.0) ? 'ZERO' : 'STD');
        $html = '<select class="fw-qi__input line-tax-rate" name="item_tax_rate[]">';
        foreach ($options as [$val, $code, $label]) {
            $html .= '<option value="' . $val . '" data-tax-code="' . $code . '"'
                   . ($code === $sel ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
        }
        return $html . '</select>';
    }
}

// Drag handle markup shared by all server-rendered editor rows (mirrors
// QIDnD.handleHtml() so PHP-rendered and JS-added rows look identical)
if (!function_exists('qi_drag_handle')) {
    function qi_drag_handle(): string {
        return '<span class="fw-qi__drag-handle" title="Drag to reorder">'
             . '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
             . '<circle cx="9" cy="5" r="1.7"/><circle cx="15" cy="5" r="1.7"/>'
             . '<circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/>'
             . '<circle cx="9" cy="19" r="1.7"/><circle cx="15" cy="19" r="1.7"/>'
             . '</svg></span>';
    }
}

// Document currency: existing quote keeps its currency, new quotes use the company default
$defaultCurrency = Currencies::isValid($qiSettings['default_currency'] ?? null) ? strtoupper($qiSettings['default_currency']) : Currencies::BASE;
$docCurrency = $editMode ? (Currencies::isValid($quoteData['currency'] ?? null) ? strtoupper($quoteData['currency']) : Currencies::BASE) : $defaultCurrency;
$docRate = $editMode ? (float)($quoteData['exchange_rate'] ?? 1) : ($docCurrency === Currencies::BASE ? 1.0 : 0.0);
$docSymbol = Currencies::symbol($docCurrency);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= $editMode ? 'Edit Quote' : 'New Quote' ?> – <?= htmlspecialchars($company['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <?php if ($company['logo_url']): ?>
                        <img src="<?= htmlspecialchars($company['logo_url']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/></svg>
                    <?php endif; ?>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($company['name']) ?></div>
                    <div class="fw-qi__app-name"><?= $editMode ? 'Edit Quote' : 'New Quote' ?></div>
                </div>
            </div>
            <div class="fw-qi__greeting">Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span></div>
            <div class="fw-qi__controls">
                <a href="/qi/" class="fw-qi__home-btn" title="Back"><svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></a>
                <button class="fw-qi__theme-toggle" id="themeToggle"><svg class="fw-qi__theme-icon fw-qi__theme-icon--light" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/></svg><svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/></svg></button>
            </div>
        </header>

        <main class="fw-qi__main">
            
            <div class="fw-qi__page-header">
                <?php if ($editMode): ?>
                    <h1 class="fw-qi__page-title">Edit Quote</h1>
                    <p class="fw-qi__page-subtitle">Editing: <?= htmlspecialchars($quoteData['quote_number']) ?></p>
                <?php else: ?>
                    <h1 class="fw-qi__page-title">New Quote</h1>
                    <p class="fw-qi__page-subtitle">Create a new quote</p>
                <?php endif; ?>
            </div>

            <form id="quoteForm" class="fw-qi__settings-form">
                
                <div class="fw-qi__form-section">
                    <h3 class="fw-qi__form-section-title">Customer Details</h3>
                    
                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Customer *</label>
                        <select name="customer_id" class="fw-qi__input" required>
                            <option value="">Select customer...</option>
                            <?php
                            $stmt = $DB->prepare("SELECT id, name, currency FROM crm_accounts WHERE company_id = ? ORDER BY name");
                            $stmt->execute([$companyId]);
                            while ($customer = $stmt->fetch()) {
                                $selected = ($editMode && $quoteData['customer_id'] == $customer['id']) ? 'selected' : '';
                                $custCur = Currencies::isValid($customer['currency'] ?? null) ? strtoupper($customer['currency']) : '';
                                echo '<option value="' . $customer['id'] . '" data-currency="' . $custCur . '" ' . $selected . '>' . htmlspecialchars($customer['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Issue Date *</label>
                            <input type="date" name="issue_date" class="fw-qi__input" value="<?= $editMode ? htmlspecialchars($quoteData['issue_date']) : date('Y-m-d') ?>" required>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Valid Until *</label>
                            <input type="date" name="expiry_date" class="fw-qi__input" value="<?= $editMode ? htmlspecialchars($quoteData['expiry_date']) : date('Y-m-d', strtotime('+14 days')) ?>" required>
                        </div>
                    </div>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Currency</label>
                            <select name="currency" id="currencySelect" class="fw-qi__input"><?= Currencies::options($docCurrency) ?></select>
                        </div>
                        <div class="fw-qi__form-group" id="exchangeRateGroup" style="<?= $docCurrency === Currencies::BASE ? 'display:none;' : '' ?>">
                            <label class="fw-qi__label">Exchange Rate (1 <span class="qi-currency-code"><?= htmlspecialchars($docCurrency) ?></span> = ? ZAR)</label>
                            <input type="number" name="exchange_rate" id="exchangeRateInput" class="fw-qi__input" step="0.000001" min="0" value="<?= $docRate > 0 ? number_format($docRate, 6, '.', '') : '' ?>" placeholder="Fetched automatically">
                        </div>
                    </div>

                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Project (Optional)</label>
                        <select name="project_id" class="fw-qi__input">
                            <option value="">No project</option>
                            <?php
                            $stmt = $DB->prepare("SELECT project_id, name FROM projects WHERE company_id = ? ORDER BY name");
                            $stmt->execute([$companyId]);
                            while ($project = $stmt->fetch()) {
                                $selected = ($editMode && $quoteData['project_id'] == $project['project_id']) ? 'selected' : '';
                                echo '<option value="' . $project['project_id'] . '" ' . $selected . '>' . htmlspecialchars($project['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                <!-- Project Board selection and import button -->
                <div class="fw-qi__form-group" id="boardGroup" style="display:none;">
                    <label class="fw-qi__label">Board</label>
                    <select id="boardSelect" class="fw-qi__input">
                        <option value="">Select board…</option>
                    </select>
                    <button type="button" class="fw-qi__btn fw-qi__btn--secondary" id="importBoardBtn" style="margin-top:8px;">Import Items</button>
                </div>
                </div>

                <div class="fw-qi__form-section">
                    <h3 class="fw-qi__form-section-title">Line Items</h3>
                    
                    <div id="lineItemsContainer">
                        <?php if ($editMode && count($editRows) > 0): ?>
                            <?php foreach ($editRows as $item): ?>
                                <?php if (($item['_kind'] ?? 'item') === 'heading'): ?>
                                    <div class="fw-qi-form__heading-row">
                                        <?= qi_drag_handle() ?>
                                        <input type="text" class="fw-qi__input" placeholder="Section heading (e.g. Aluminium)" name="item_heading[]" value="<?= htmlspecialchars($item['item_description']) ?>">
                                        <button type="button" class="fw-qi-form__remove-line" data-action="remove-heading" title="Remove heading">×</button>
                                    </div>
                                <?php else: ?>
                                    <div class="fw-qi-form__line-item">
                                        <?= qi_drag_handle() ?>
                                        <input type="text" class="fw-qi__input" placeholder="Description" name="item_description[]" value="<?= htmlspecialchars($item['item_description']) ?>" required>
                                        <input type="number" class="fw-qi__input" placeholder="Qty" name="item_quantity[]" value="<?= $item['quantity'] ?>" step="0.01" min="0" required>
                                        <input type="number" class="fw-qi__input" placeholder="Unit Price" name="item_price[]" value="<?= $item['unit_price'] ?>" step="0.01" min="0" required>
                                        <?= qi_line_tax_select($item['tax_code'] ?? null, $item['tax_rate'] ?? null) ?>
                                        <input type="text" class="fw-qi__input" placeholder="R 0.00" readonly name="item_total[]" value="<?= number_format($item['line_total'], 2) ?>">
                                        <button type="button" class="fw-qi-form__remove-line" onclick="removeLine(this)">×</button>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="fw-qi-form__line-item">
                                <?= qi_drag_handle() ?>
                                <input type="text" class="fw-qi__input" placeholder="Description" name="item_description[]" required>
                                <input type="number" class="fw-qi__input" placeholder="Qty" name="item_quantity[]" value="1" step="0.01" min="0" required>
                                <input type="number" class="fw-qi__input" placeholder="Unit Price" name="item_price[]" step="0.01" min="0" required>
                                <?= qi_line_tax_select(null) ?>
                                <input type="text" class="fw-qi__input" placeholder="R 0.00" readonly name="item_total[]">
                                <button type="button" class="fw-qi-form__remove-line" onclick="removeLine(this)">×</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="fw-qi__btn fw-qi__btn--secondary" onclick="addLine()">+ Add Line</button>
                    <button type="button" class="fw-qi__btn fw-qi__btn--secondary" style="margin-left:8px;" onclick="addHeading()">+ Add Heading</button>
                    <button type="button" id="suggestLinesBtn" class="fw-qi__btn fw-qi__btn--secondary" style="margin-left:8px;">✨ Suggest Lines</button>

                    <!-- AI suggestions panel for line items -->
                    <div id="aiLineSuggestions" class="fw-qi__ai-panel" style="display:none;">
                        <p style="margin-bottom:8px;font-weight:600;">Suggested Items:</p>
                        <div id="aiLineList"></div>
                        <button type="button" id="importAllLinesBtn" class="fw-qi__btn fw-qi__btn--primary" style="margin-top:8px;display:none;">Add All</button>
                    </div>

                    <div class="fw-qi-form__totals">
                        <div class="fw-qi-form__total-row">
                            <span>Subtotal:</span>
                            <span id="subtotalDisplay"><?= htmlspecialchars($docSymbol) ?> 0.00</span>
                        </div>
                        <div class="fw-qi-form__total-row">
                            <span>VAT:</span>
                            <span id="taxDisplay"><?= htmlspecialchars($docSymbol) ?> 0.00</span>
                        </div>
                        <div class="fw-qi-form__total-row fw-qi-form__total-row--grand">
                            <span>TOTAL:</span>
                            <span id="totalDisplay"><?= htmlspecialchars($docSymbol) ?> 0.00</span>
                        </div>
                    </div>
                </div>

                <div class="fw-qi__form-section">
                    <h3 class="fw-qi__form-section-title">Payment Milestones</h3>
                    <div class="fw-qi__milestone-toggle">
                        <label class="fw-qi__checkbox-label">
                            <input type="checkbox" id="enableMilestones" onchange="QI.toggleMilestones()">
                            <span>Enable payment phases (deposit & percentage-based payments)</span>
                        </label>
                    </div>
                    <div id="milestonesSection" style="display:none;">
                        <div id="milestonesContainer"></div>
                        <div class="fw-qi__milestone-actions">
                            <button type="button" class="fw-qi__btn fw-qi__btn--secondary fw-qi__btn--small" onclick="QI.addMilestone()">+ Add Phase</button>
                        </div>
                        <div class="fw-qi__milestone-summary">
                            <div class="fw-qi__milestone-summary-row">
                                <span>Total allocated:</span>
                                <strong id="milestoneTotalPct">0%</strong>
                            </div>
                            <div id="milestoneValidation" class="fw-qi__milestone-validation" style="display:none;"></div>
                        </div>
                    </div>
                </div>

                <div class="fw-qi__form-section">
                    <h3 class="fw-qi__form-section-title">Terms & Notes</h3>
                    
                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Terms & Conditions <button type="button" id="suggestTermsBtn" class="fw-qi__btn fw-qi__btn--secondary" style="font-size:12px;padding:2px 6px;">✨ Suggest</button></label>
                        <textarea name="terms" class="fw-qi__textarea" id="termsTextarea" rows="4"><?= $editMode ? htmlspecialchars($quoteData['terms'] ?? '') : htmlspecialchars($defaultTerms) ?></textarea>
                        <!-- AI suggested terms panel -->
                        <div id="aiTermsSuggestion" class="fw-qi__ai-panel" style="display:none;"></div>
                    </div>

                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Internal Notes</label>
                        <textarea name="notes" class="fw-qi__textarea" rows="3"><?= $editMode ? htmlspecialchars($quoteData['notes'] ?? '') : '' ?></textarea>
                    </div>
                </div>

                <div class="fw-qi__form-actions">
                    <button type="submit" class="fw-qi__btn fw-qi__btn--primary"><?= $editMode ? 'Update Quote' : 'Create Quote' ?></button>
                    <a href="/qi/" class="fw-qi__btn fw-qi__btn--secondary">Cancel</a>
                </div>

            </form>

        </main>

        <footer class="fw-qi__footer">
            <span>Q&I v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <script src="/qi/assets/qi.ui.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/qi/assets/qi.currency.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/qi/assets/qi.dnd.js?v=<?= ASSET_VERSION ?>"></script>
    <!-- Quote-specific logic -->
    <script>
        // Provide configuration for the quote form to the external JS
        window.quoteConfig = {
            editMode: <?= $editMode ? 'true' : 'false' ?>,
            quoteId: <?= $editMode ? $quoteId : 'null' ?>
        };
        // Default tax rate percentage for quote calculations
        window.defaultTaxRate = <?= json_encode($defaultTaxRate) ?>;
        // Document currency state (rate = 1 unit of currency in ZAR)
        window.qiCurrency = {
            code: <?= json_encode($docCurrency) ?>,
            rate: <?= json_encode($docRate) ?>,
            base: <?= json_encode(Currencies::BASE) ?>,
            symbols: <?= json_encode(Currencies::symbolMap()) ?>
        };
    </script>
    <script src="/qi/assets/qi.quote.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        window.QI_EDIT_MILESTONES = <?= json_encode($editMilestones, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <!-- Payment milestones (quote form does not load qi-form.js; its initForm would double-bind #quoteForm) -->
    <script>
    (function() {
        'use strict';
        const QI = window.QI = window.QI || {};
        let milestoneCounter = 0;

        function curSym() {
            return (window.QICurrency) ? QICurrency.symbol() : 'R';
        }

        function grandTotal() {
            let subtotal = 0;
            let tax = 0;
            document.querySelectorAll('.fw-qi-form__line-item').forEach(function(line) {
                const qty = parseFloat(line.querySelector('[name="item_quantity[]"]')?.value) || 0;
                const price = parseFloat(line.querySelector('[name="item_price[]"]')?.value) || 0;
                const lineNet = qty * price;
                subtotal += lineNet;
                // Per-line VAT, consistent with the totals shown and the payload.
                const taxSel = line.querySelector('.line-tax-rate');
                const rate = taxSel ? (parseFloat(taxSel.value) || 0) / 100 : 0.15;
                tax += lineNet * rate;
            });
            return subtotal + tax;
        }

        QI.toggleMilestones = function() {
            const enabled = document.getElementById('enableMilestones')?.checked;
            const section = document.getElementById('milestonesSection');
            if (!section) return;
            section.style.display = enabled ? 'block' : 'none';
            if (enabled && document.querySelectorAll('.fw-qi__milestone-row').length === 0) {
                QI.addMilestone('Deposit', 50);
                QI.addMilestone('Final Payment', 50);
            }
        };

        QI.addMilestone = function(defaultLabel, defaultPct, defaultAmount, defaultDueDate) {
            milestoneCounter++;
            const container = document.getElementById('milestonesContainer');
            if (!container) return;
            const row = document.createElement('div');
            row.className = 'fw-qi__milestone-row';
            row.dataset.milestoneId = milestoneCounter;
            row.innerHTML = `
              <div class="fw-qi__milestone-fields">
                <div class="fw-qi__form-group">
                  <label class="fw-qi__label">Phase Name</label>
                  <input type="text" class="fw-qi__input ms-label" value="${defaultLabel || ''}" placeholder="e.g. Deposit, Progress, Final">
                </div>
                <div class="fw-qi__form-group">
                  <label class="fw-qi__label">Type</label>
                  <select class="fw-qi__input ms-type" onchange="QI.onMilestoneTypeChange(${milestoneCounter})">
                    <option value="percentage" ${!defaultAmount ? 'selected' : ''}>Percentage (%)</option>
                    <option value="fixed" ${defaultAmount ? 'selected' : ''}>Fixed Amount (${curSym()})</option>
                  </select>
                </div>
                <div class="fw-qi__form-group ms-pct-group" ${defaultAmount ? 'style="display:none;"' : ''}>
                  <label class="fw-qi__label">Percentage (%)</label>
                  <input type="number" class="fw-qi__input ms-percentage" value="${defaultPct || ''}" step="0.01" min="0.01" max="100" placeholder="e.g. 50">
                </div>
                <div class="fw-qi__form-group ms-fixed-group" ${!defaultAmount ? 'style="display:none;"' : ''}>
                  <label class="fw-qi__label">Amount (<span class="qi-currency-symbol">${curSym()}</span>)</label>
                  <input type="number" class="fw-qi__input ms-fixed-amount" value="${defaultAmount || ''}" step="0.01" min="0.01" placeholder="e.g. 5000">
                </div>
                <div class="fw-qi__form-group">
                  <label class="fw-qi__label">Due Date</label>
                  <input type="date" class="fw-qi__input ms-due-date">
                </div>
                <div class="fw-qi__form-group">
                  <label class="fw-qi__label">Calculated</label>
                  <div class="fw-qi__milestone-amount" data-ms-id="${milestoneCounter}">${curSym()} 0.00</div>
                </div>
                <div class="fw-qi__form-group fw-qi__milestone-remove-col">
                  <button type="button" class="fw-qi__btn fw-qi__btn--small fw-qi__btn--danger" onclick="QI.removeMilestone(${milestoneCounter})">Remove</button>
                </div>
              </div>
            `;
            container.appendChild(row);
            if (defaultDueDate) row.querySelector('.ms-due-date').value = defaultDueDate;
            row.querySelector('.ms-percentage').addEventListener('input', QI.calculateMilestones);
            row.querySelector('.ms-fixed-amount').addEventListener('input', QI.calculateMilestones);
            QI.calculateMilestones();
        };

        QI.onMilestoneTypeChange = function(msId) {
            const row = document.querySelector(`.fw-qi__milestone-row[data-milestone-id="${msId}"]`);
            if (!row) return;
            const type = row.querySelector('.ms-type').value;
            row.querySelector('.ms-pct-group').style.display = type === 'percentage' ? '' : 'none';
            row.querySelector('.ms-fixed-group').style.display = type === 'percentage' ? 'none' : '';
            QI.calculateMilestones();
        };

        QI.removeMilestone = function(msId) {
            const row = document.querySelector(`.fw-qi__milestone-row[data-milestone-id="${msId}"]`);
            if (row) {
                row.remove();
                QI.calculateMilestones();
            }
        };

        QI.calculateMilestones = function() {
            const total = grandTotal();
            let totalAllocated = 0;
            document.querySelectorAll('.fw-qi__milestone-row').forEach(function(row) {
                const type = row.querySelector('.ms-type')?.value || 'percentage';
                let amount = 0;
                if (type === 'percentage') {
                    const pct = parseFloat(row.querySelector('.ms-percentage')?.value) || 0;
                    amount = total * (pct / 100);
                } else {
                    amount = parseFloat(row.querySelector('.ms-fixed-amount')?.value) || 0;
                }
                totalAllocated += amount;
                const amountDisplay = row.querySelector('.fw-qi__milestone-amount');
                if (amountDisplay) amountDisplay.textContent = curSym() + ' ' + amount.toFixed(2);
            });
            const totalPct = total > 0 ? (totalAllocated / total) * 100 : 0;
            const pctDisplay = document.getElementById('milestoneTotalPct');
            if (pctDisplay) {
                pctDisplay.textContent = totalPct.toFixed(2) + '% (' + curSym() + ' ' + totalAllocated.toFixed(2) + ')';
                pctDisplay.style.color = Math.abs(totalPct - 100) < 0.01 ? 'var(--neon-green)' : 'var(--neon-red)';
            }
            const validationEl = document.getElementById('milestoneValidation');
            if (validationEl) {
                if (Math.abs(totalPct - 100) < 0.01) {
                    validationEl.style.display = 'none';
                } else if (totalPct < 100) {
                    validationEl.textContent = `${(100 - totalPct).toFixed(2)}% (${curSym()} ${(total - totalAllocated).toFixed(2)}) still unallocated`;
                    validationEl.style.display = 'block';
                } else {
                    validationEl.textContent = `${(totalPct - 100).toFixed(2)}% (${curSym()} ${(totalAllocated - total).toFixed(2)}) over-allocated`;
                    validationEl.style.display = 'block';
                }
            }
        };

        QI.collectMilestones = function() {
            if (!document.getElementById('enableMilestones')?.checked) return [];
            const total = grandTotal();
            const milestones = [];
            document.querySelectorAll('.fw-qi__milestone-row').forEach(function(row) {
                const type = row.querySelector('.ms-type')?.value || 'percentage';
                let percentage, fixedAmount;
                if (type === 'percentage') {
                    percentage = parseFloat(row.querySelector('.ms-percentage')?.value) || 0;
                    fixedAmount = null;
                } else {
                    fixedAmount = parseFloat(row.querySelector('.ms-fixed-amount')?.value) || 0;
                    percentage = total > 0 ? (fixedAmount / total) * 100 : 0;
                }
                milestones.push({
                    label: row.querySelector('.ms-label')?.value || '',
                    percentage: percentage,
                    fixed_amount: fixedAmount,
                    due_date: row.querySelector('.ms-due-date')?.value || null
                });
            });
            return milestones;
        };

        // qi.quote.js builds the save payload without milestones; inject them
        // into the save_quote request so enabled phases actually persist.
        // The key is always sent: [] means "explicitly disabled" and clears
        // stored phases on edit; save_quote leaves rows alone only when the
        // key is absent (non-shim clients).
        const origFetch = window.fetch;
        window.fetch = function(url, options) {
            if (typeof url === 'string' && url.indexOf('/qi/ajax/save_quote.php') !== -1 &&
                options && typeof options.body === 'string') {
                try {
                    const payload = JSON.parse(options.body);
                    payload.milestones = QI.collectMilestones();
                    options = Object.assign({}, options, { body: JSON.stringify(payload) });
                } catch (e) { /* leave request untouched */ }
            }
            return origFetch.call(this, url, options);
        };

        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('lineItemsContainer');
            if (container) container.addEventListener('input', QI.calculateMilestones);

            // Edit mode: rebuild stored phases so saving the form round-trips
            // them instead of silently discarding them.
            const stored = window.QI_EDIT_MILESTONES;
            if (Array.isArray(stored) && stored.length) {
                const toggle = document.getElementById('enableMilestones');
                if (toggle && !toggle.checked) {
                    toggle.checked = true;
                    const section = document.getElementById('milestonesSection');
                    if (section) section.style.display = 'block';
                    stored.forEach(function(ms) {
                        QI.addMilestone(ms.label, ms.percentage, null, ms.due_date);
                    });
                }
            }
        });
    })();
    </script>

    <!-- Import from project board functionality -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const projectSelect = document.querySelector('select[name="project_id"]');
        const boardGroup    = document.getElementById('boardGroup');
        const boardSelect   = document.getElementById('boardSelect');
        const importBtn     = document.getElementById('importBoardBtn');

        async function loadBoards(projectId) {
            if (!boardGroup || !boardSelect) return;
            if (!projectId) {
                boardGroup.style.display = 'none';
                boardSelect.innerHTML = '<option value="">Select board…</option>';
                return;
            }
            try {
                boardSelect.innerHTML = '<option value="">Loading…</option>';
                const res = await fetch('/qi/ajax/get_project_boards.php?project_id=' + encodeURIComponent(projectId));
                const data = await res.json();
                if (data.ok && Array.isArray(data.boards) && data.boards.length > 0) {
                    boardSelect.innerHTML = '<option value="">Select board…</option>';
                    data.boards.forEach(function(b) {
                        const opt = document.createElement('option');
                        opt.value = b.board_id;
                        opt.textContent = b.name;
                        boardSelect.appendChild(opt);
                    });
                    boardGroup.style.display = '';
                } else {
                    boardSelect.innerHTML = '<option value="">No boards</option>';
                    boardGroup.style.display = 'none';
                }
            } catch (err) {
                console.error(err);
                boardSelect.innerHTML = '<option value="">Error loading boards</option>';
                boardGroup.style.display = 'none';
            }
        }

        if (projectSelect) {
            projectSelect.addEventListener('change', function() {
                loadBoards(this.value);
            });
            // If editing and a project is pre-selected, load boards initially
            <?php if ($editMode && $quoteData['project_id']): ?>
            loadBoards(<?= (int)$quoteData['project_id'] ?>);
            <?php endif; ?>
        }

        if (importBtn) {
            importBtn.addEventListener('click', async function() {
                const boardId = boardSelect ? boardSelect.value : '';
                if (!boardId) {
                    alert('Please select a board to import items');
                    return;
                }
                try {
                    const res = await fetch('/qi/ajax/import_from_project.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ board_id: parseInt(boardId) })
                    });
                    const data = await res.json();
                    if (data.ok && Array.isArray(data.items)) {
                        data.items.forEach(function(item) {
                            // Board group headings arrive as type:'heading'
                            if (item.type === 'heading') {
                                if (typeof addHeading === 'function') {
                                    addHeading(item.description || '');
                                }
                                return;
                            }
                            // Use global addLine() function from qi.quote.js
                            if (typeof addLine === 'function') {
                                addLine();
                                // Get last line item element
                                const lines = document.querySelectorAll('.fw-qi-form__line-item');
                                const lastLine = lines[lines.length - 1];
                                if (lastLine) {
                                    const descInput  = lastLine.querySelector('[name="item_description[]"]');
                                    const qtyInput   = lastLine.querySelector('[name="item_quantity[]"]');
                                    const priceInput = lastLine.querySelector('[name="item_price[]"]');
                                    if (descInput)  descInput.value  = item.description || '';
                                    if (qtyInput)   qtyInput.value   = item.quantity || 1;
                                    if (priceInput) priceInput.value = item.unit_price || 0;
                                }
                            }
                        });
                        // Recalculate totals after import
                        if (typeof calculateTotals === 'function') {
                            calculateTotals();
                        }
                    } else {
                        alert(data.error || 'Failed to import items');
                    }
                } catch (err) {
                    alert('Error importing items: ' + err.message);
                }
            });
        }
    });
    </script>
    <!-- AI Suggestion logic for quotes -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const suggestLinesBtn  = document.getElementById('suggestLinesBtn');
        const aiPanel         = document.getElementById('aiLineSuggestions');
        const aiList          = document.getElementById('aiLineList');
        const importAllBtn    = document.getElementById('importAllLinesBtn');
        const projectSelect   = document.querySelector('select[name="project_id"]');
        const suggestTermsBtn = document.getElementById('suggestTermsBtn');
        const termsTextarea   = document.getElementById('termsTextarea');
        const aiTermsBox      = document.getElementById('aiTermsSuggestion');
        // Helper to create a suggestion item element
        function createSuggestionItem(item, index) {
            const div = document.createElement('div');
            div.className = 'fw-qi__ai-suggest-item';
            div.style.marginBottom = '6px';
            div.style.display = 'flex';
            div.style.justifyContent = 'space-between';
            div.style.alignItems = 'center';
            const info = document.createElement('span');
            info.textContent = item.description + ' (Qty: ' + item.quantity + ', Rate: ' + item.unit_price + ')';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fw-qi__btn fw-qi__btn--secondary';
            btn.textContent = '+';
            btn.title = 'Add to quote';
            btn.addEventListener('click', function() {
                // Add a new line item using global addLine()
                if (typeof addLine === 'function') {
                    addLine();
                    const lines = document.querySelectorAll('.fw-qi-form__line-item');
                    const lastLine = lines[lines.length - 1];
                    if (lastLine) {
                        const descInput  = lastLine.querySelector('[name="item_description[]"]');
                        const qtyInput   = lastLine.querySelector('[name="item_quantity[]"]');
                        const priceInput = lastLine.querySelector('[name="item_price[]"]');
                        if (descInput)  descInput.value  = item.description || '';
                        if (qtyInput)   qtyInput.value   = item.quantity || 1;
                        if (priceInput) priceInput.value = item.unit_price || 0;
                    }
                    // Remove this suggestion item from list
                    div.remove();
                    // Recalculate totals
                    if (typeof calculateTotals === 'function') {
                        calculateTotals();
                    }
                }
            });
            div.appendChild(info);
            div.appendChild(btn);
            return div;
        }
        if (suggestLinesBtn) {
            suggestLinesBtn.addEventListener('click', async function() {
                // Determine context: use selected project ID if available
                const projId = projectSelect ? parseInt(projectSelect.value) : 0;
                // Build payload
                const payload = {};
                if (projId) {
                    payload.project_id = projId;
                }
                try {
                    const res = await fetch('/qi/ai/suggest_lines.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.ok && data.data && Array.isArray(data.data.items)) {
                        const items = data.data.items;
                        if (items.length > 0) {
                            aiList.innerHTML = '';
                            items.forEach(function(item, idx) {
                                const el = createSuggestionItem(item, idx);
                                aiList.appendChild(el);
                            });
                            aiPanel.style.display = '';
                            importAllBtn.style.display = '';
                            importAllBtn.onclick = function() {
                                items.forEach(function(item) {
                                    if (typeof addLine === 'function') {
                                        addLine();
                                        const lines = document.querySelectorAll('.fw-qi-form__line-item');
                                        const lastLine = lines[lines.length - 1];
                                        if (lastLine) {
                                            const descInput  = lastLine.querySelector('[name="item_description[]"]');
                                            const qtyInput   = lastLine.querySelector('[name="item_quantity[]"]');
                                            const priceInput = lastLine.querySelector('[name="item_price[]"]');
                                            if (descInput)  descInput.value  = item.description || '';
                                            if (qtyInput)   qtyInput.value   = item.quantity || 1;
                                            if (priceInput) priceInput.value = item.unit_price || 0;
                                        }
                                    }
                                });
                                aiPanel.style.display = 'none';
                                aiList.innerHTML = '';
                                importAllBtn.style.display = 'none';
                                if (typeof calculateTotals === 'function') {
                                    calculateTotals();
                                }
                            };
                        } else {
                            alert('No suggestions found.');
                        }
                    } else {
                        alert(data.error || 'Failed to get suggestions');
                    }
                } catch (err) {
                    alert('Error fetching suggestions: ' + err.message);
                }
            });
        }
        if (suggestTermsBtn) {
            suggestTermsBtn.addEventListener('click', async function() {
                // Determine customer and risk profile (use medium as default)
                const customerSelect = document.querySelector('select[name="customer_id"]');
                const customerId = customerSelect ? parseInt(customerSelect.value) : 0;
                const payload = { customer_id: customerId, risk_profile: 'medium' };
                try {
                    const res = await fetch('/qi/ai/smart_terms.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.ok && data.data && data.data.terms) {
                        const terms = data.data.terms;
                        // Show suggestion with accept button
                        aiTermsBox.style.display = '';
                        aiTermsBox.innerHTML = '';
                        const p = document.createElement('p');
                        p.style.whiteSpace = 'pre-wrap';
                        p.textContent = terms;
                        aiTermsBox.appendChild(p);
                        const btnUse = document.createElement('button');
                        btnUse.type = 'button';
                        btnUse.className = 'fw-qi__btn fw-qi__btn--primary';
                        btnUse.style.marginTop = '6px';
                        btnUse.textContent = 'Use Suggested Terms';
                        btnUse.addEventListener('click', function() {
                            if (termsTextarea) {
                                // Append suggested terms instead of overwrite
                                const current = termsTextarea.value.trim();
                                const combined = current ? (current + '\n\n' + terms) : terms;
                                termsTextarea.value = combined;
                                aiTermsBox.style.display = 'none';
                            }
                        });
                        aiTermsBox.appendChild(btnUse);
                    } else {
                        alert(data.error || 'Failed to get terms suggestion');
                    }
                } catch (err) {
                    alert('Error fetching terms: ' + err.message);
                }
            });
        }
    });
    </script>
</body>
</html>