<?php
// /qi/quote_new.php - COMPLETE WITH EDIT MODE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-FORM');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check edit mode
$editMode = false;
$quoteId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$quoteData = null;
$lineItems = [];

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
    
    $stmt = $DB->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
    $stmt->execute([$quoteId]);
    $lineItems = $stmt->fetchAll();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editMode ? 'Edit Quote' : 'New Quote' ?> – <?= htmlspecialchars($company['name']) ?></title>
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <style>
        .fw-qi-form__line-item { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 40px; gap: 12px; margin-bottom: 12px; align-items: start; }
        .fw-qi-form__remove-line { background: #ef4444; color: white; border: none; border-radius: 6px; width: 40px; height: 40px; cursor: pointer; font-weight: 700; }
        .fw-qi-form__remove-line:hover { background: #dc2626; }
        .fw-qi-form__totals { max-width: 400px; margin-left: auto; padding: 20px; background: rgba(251,191,36,0.08); border-radius: 8px; margin-top: 20px; }
        .fw-qi-form__total-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px; }
        .fw-qi-form__total-row--grand { font-size: 22px; font-weight: 700; color: #fbbf24; border-top: 2px solid #fbbf24; padding-top: 12px; margin-top: 8px; }
    </style>
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
                            $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? ORDER BY name");
                            $stmt->execute([$companyId]);
                            while ($customer = $stmt->fetch()) {
                                $selected = ($editMode && $quoteData['customer_id'] == $customer['id']) ? 'selected' : '';
                                echo '<option value="' . $customer['id'] . '" ' . $selected . '>' . htmlspecialchars($customer['name']) . '</option>';
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
                        <?php if ($editMode && count($lineItems) > 0): ?>
                            <?php foreach ($lineItems as $item): ?>
                                <div class="fw-qi-form__line-item">
                                    <input type="text" class="fw-qi__input" placeholder="Description" name="item_description[]" value="<?= htmlspecialchars($item['item_description']) ?>" required>
                                    <input type="number" class="fw-qi__input" placeholder="Qty" name="item_quantity[]" value="<?= $item['quantity'] ?>" step="0.01" min="0" required>
                                    <input type="number" class="fw-qi__input" placeholder="Unit Price" name="item_price[]" value="<?= $item['unit_price'] ?>" step="0.01" min="0" required>
                                    <input type="text" class="fw-qi__input" placeholder="R 0.00" readonly name="item_total[]" value="<?= number_format($item['line_total'], 2) ?>">
                                    <button type="button" class="fw-qi-form__remove-line" onclick="removeLine(this)">×</button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="fw-qi-form__line-item">
                                <input type="text" class="fw-qi__input" placeholder="Description" name="item_description[]" required>
                                <input type="number" class="fw-qi__input" placeholder="Qty" name="item_quantity[]" value="1" step="0.01" min="0" required>
                                <input type="number" class="fw-qi__input" placeholder="Unit Price" name="item_price[]" step="0.01" min="0" required>
                                <input type="text" class="fw-qi__input" placeholder="R 0.00" readonly name="item_total[]">
                                <button type="button" class="fw-qi-form__remove-line" onclick="removeLine(this)">×</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="button" class="fw-qi__btn fw-qi__btn--secondary" onclick="addLine()">+ Add Line</button>
                    <button type="button" id="suggestLinesBtn" class="fw-qi__btn fw-qi__btn--secondary" style="margin-left:8px;">✨ Suggest Lines</button>

                    <!-- AI suggestions panel for line items -->
                    <div id="aiLineSuggestions" class="fw-qi__panel" style="display:none;margin-top:10px;padding:10px;border:1px dashed #e5e7eb;border-radius:8px;background:rgba(243,244,246,0.4);">
                        <p style="margin-bottom:8px;font-weight:600;">Suggested Items:</p>
                        <div id="aiLineList"></div>
                        <button type="button" id="importAllLinesBtn" class="fw-qi__btn fw-qi__btn--primary" style="margin-top:8px;display:none;">Add All</button>
                    </div>

                    <div class="fw-qi-form__totals">
                        <div class="fw-qi-form__total-row">
                            <span>Subtotal:</span>
                            <span id="subtotalDisplay">R 0.00</span>
                        </div>
                        <div class="fw-qi-form__total-row">
                            <span>VAT (15%):</span>
                            <span id="taxDisplay">R 0.00</span>
                        </div>
                        <div class="fw-qi-form__total-row fw-qi-form__total-row--grand">
                            <span>TOTAL:</span>
                            <span id="totalDisplay">R 0.00</span>
                        </div>
                    </div>
                </div>

                <div class="fw-qi__form-section">
                    <h3 class="fw-qi__form-section-title">Terms & Notes</h3>
                    
                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Terms & Conditions <button type="button" id="suggestTermsBtn" class="fw-qi__btn fw-qi__btn--secondary" style="font-size:12px;padding:2px 6px;">✨ Suggest</button></label>
                        <textarea name="terms" class="fw-qi__textarea" id="termsTextarea" rows="4"><?= $editMode ? htmlspecialchars($quoteData['terms'] ?? '') : htmlspecialchars($defaultTerms) ?></textarea>
                        <!-- AI suggested terms panel -->
                        <div id="aiTermsSuggestion" style="display:none;margin-top:6px;padding:8px;border:1px dashed #e5e7eb;border-radius:8px;background:rgba(243,244,246,0.4);"></div>
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

    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <!-- Quote-specific logic -->
    <script>
        // Provide configuration for the quote form to the external JS
        window.quoteConfig = {
            editMode: <?= $editMode ? 'true' : 'false' ?>,
            quoteId: <?= $editMode ? $quoteId : 'null' ?>
        };
        // Default tax rate percentage for quote calculations
        window.defaultTaxRate = <?= json_encode($defaultTaxRate) ?>;
    </script>
    <script src="/qi/assets/qi.quote.js?v=<?= ASSET_VERSION ?>"></script>

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