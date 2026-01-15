<?php
// /qi/invoice_new.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user/company info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch customers
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'customer' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$customers = $stmt->fetchAll();

// Fetch projects
$stmt = $DB->prepare("SELECT project_id, name FROM projects WHERE company_id = ? AND status IN ('active','draft') AND archived = 0 ORDER BY name");
$stmt->execute([$companyId]);

$projects = $stmt->fetchAll();

// Fetch inventory items for this company to allow linking invoice lines to stock items
$stmt = $DB->prepare("SELECT id, sku, name FROM inventory_items WHERE company_id = ? ORDER BY name");
$stmt->execute([$companyId]);
$INV_ITEMS = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load QI settings for default terms and payment terms
$stmt = $DB->prepare("SELECT * FROM qi_settings WHERE company_id = ? LIMIT 1");
$stmt->execute([$companyId]);
$qiSettings = $stmt->fetch();
$defaultPaymentTerms = isset($qiSettings['default_payment_terms']) && is_numeric($qiSettings['default_payment_terms']) ? (int)$qiSettings['default_payment_terms'] : 30;
$defaultTerms = $qiSettings['default_terms'] ?? '';

$issueDate = date('Y-m-d');
$dueDate = date('Y-m-d', strtotime('+' . $defaultPaymentTerms . ' days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Invoice – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <!-- Expose inventory items to the client-side JS -->
    <script>window.FW_INV_ITEMS = <?= json_encode($INV_ITEMS) ?>;</script>
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        <line x1="3" y1="9" x2="21" y2="9" stroke="currentColor" stroke-width="2"/>
                        <line x1="9" y1="21" x2="9" y2="9" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-qi__app-name">New Invoice</div>
                </div>
            </div>

            <div class="fw-qi__greeting">
                Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-qi__controls">
                <a href="/qi/" class="fw-qi__home-btn" title="Back">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <button class="fw-qi__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-qi__theme-icon fw-qi__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                        <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </header>

        <main class="fw-qi__main">
            <div class="fw-qi__form-header">
                <h1 class="fw-qi__form-title">Create New Invoice</h1>
            </div>

            <form id="invoiceForm" class="fw-qi__form">
                
                <div class="fw-qi__form-section">
                    <h2 class="fw-qi__form-section-title">Basic Information</h2>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Customer <span class="fw-qi__required">*</span></label>
                            <select name="customer_id" class="fw-qi__input" required>
                                <option value="">Select Customer...</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Project (Optional)</label>
                            <select name="project_id" class="fw-qi__input">
                                <option value="">None</option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= $p['project_id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

        <!-- Board selection and import button for project items -->
        <div class="fw-qi__form-row" id="boardRow" style="display:none;">
            <div class="fw-qi__form-group">
                <label class="fw-qi__label">Board</label>
                <select id="boardSelect" class="fw-qi__input">
                    <option value="">Select board…</option>
                </select>
            </div>
            <div class="fw-qi__form-group" style="align-self:end;">
                <button type="button" class="fw-qi__btn fw-qi__btn--secondary" id="importBoardBtn">Import Items</button>
            </div>
        </div>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Issue Date <span class="fw-qi__required">*</span></label>
                            <input type="date" name="issue_date" class="fw-qi__input" value="<?= $issueDate ?>" required>
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Due Date <span class="fw-qi__required">*</span></label>
                            <input type="date" name="due_date" class="fw-qi__input" value="<?= $dueDate ?>" required>
                        </div>
                    </div>
                </div>

                <div class="fw-qi__form-section">
                    <h2 class="fw-qi__form-section-title">Line Items</h2>
                    <div id="lineItemsContainer"></div>
                    <button type="button" class="fw-qi__btn fw-qi__btn--secondary" onclick="QI.addLineItem()">+ Add Line Item</button>
                </div>

                <div class="fw-qi__form-section">
                    <h2 class="fw-qi__form-section-title">Totals</h2>
                    <div class="fw-qi__totals-grid">
                        <div class="fw-qi__total-row">
                            <span>Subtotal:</span>
                            <strong id="displaySubtotal">R 0.00</strong>
                        </div>
                        <div class="fw-qi__total-row">
                            <span>Discount:</span>
                            <strong id="displayDiscount">R 0.00</strong>
                        </div>
                        <div class="fw-qi__total-row">
                            <span>VAT (15%):</span>
                            <strong id="displayTax">R 0.00</strong>
                        </div>
                        <div class="fw-qi__total-row fw-qi__total-row--grand">
                            <span>Total:</span>
                            <strong id="displayTotal">R 0.00</strong>
                        </div>
                    </div>
                    <input type="hidden" name="subtotal" id="inputSubtotal" value="0">
                    <input type="hidden" name="discount" id="inputDiscount" value="0">
                    <input type="hidden" name="tax" id="inputTax" value="0">
                    <input type="hidden" name="total" id="inputTotal" value="0">
                </div>

                <div class="fw-qi__form-section">
                    <h2 class="fw-qi__form-section-title">Terms & Notes</h2>
                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Payment Terms</label>
                        <textarea name="terms" class="fw-qi__textarea" rows="4" placeholder="Payment due within 30 days..."><?= htmlspecialchars($defaultTerms) ?></textarea>
                    </div>
                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Internal Notes</label>
                        <textarea name="notes" class="fw-qi__textarea" rows="3" placeholder="Private notes..."></textarea>
                    </div>
                </div>

                <div class="fw-qi__form-actions">
                    <a href="/qi/" class="fw-qi__btn fw-qi__btn--secondary">Cancel</a>
                    <button type="submit" class="fw-qi__btn fw-qi__btn--primary" id="btnSaveInvoice">
                        <span class="fw-qi__btn-text">Create Invoice</span>
                        <span class="fw-qi__spinner" style="display:none;"></span>
                    </button>
                </div>

                <div id="formMessage"></div>
            </form>
        </main>

        <footer class="fw-qi__footer">
            <span>Quotes & Invoices v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>

    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/qi/assets/qi-form.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        // Override form endpoint for invoices
        document.getElementById('invoiceForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            // Ensure at least one line item exists
            if (document.querySelectorAll('.fw-qi__line-item').length === 0) {
                alert('Please add at least one line item');
                return;
            }

            const btn = document.getElementById('btnSaveInvoice');
            btn.disabled = true;
            btn.querySelector('.fw-qi__btn-text').style.display = 'none';
            btn.querySelector('.fw-qi__spinner').style.display = 'inline-block';

            try {
                // Build a JSON payload from form inputs and line items
                const form = this;
                const payload = {};
                payload.customer_id = form.customer_id ? form.customer_id.value : '';
                payload.contact_id = form.contact_id ? form.contact_id.value || null : null;
                payload.project_id = form.project_id ? form.project_id.value || null : null;
                payload.issue_date = form.issue_date ? form.issue_date.value : '';
                payload.due_date = form.due_date ? form.due_date.value : '';
                // Use totals calculated by QI.calculateTotals
                payload.subtotal = parseFloat(document.getElementById('inputSubtotal').value) || 0;
                payload.discount = parseFloat(document.getElementById('inputDiscount').value) || 0;
                payload.tax = parseFloat(document.getElementById('inputTax').value) || 0;
                payload.total = parseFloat(document.getElementById('inputTotal').value) || 0;
                payload.terms = form.terms ? form.terms.value : '';
                payload.notes = form.notes ? form.notes.value : '';
                payload.line_items = [];

                document.querySelectorAll('.fw-qi__line-item').forEach(function(lineDiv) {
                    const descriptionInput = lineDiv.querySelector('input[name*="[description]"]');
                    const qtyInput = lineDiv.querySelector('.line-quantity');
                    const priceInput = lineDiv.querySelector('.line-price');
                    const discountInput = lineDiv.querySelector('.line-discount');
                    const taxSelect = lineDiv.querySelector('.line-tax-rate');
                    const itemSelect = lineDiv.querySelector('select[name*="[inventory_item_id]"]');

                    const description = descriptionInput ? descriptionInput.value : '';
                    const quantity = qtyInput ? parseFloat(qtyInput.value) || 0 : 0;
                    const unit_price = priceInput ? parseFloat(priceInput.value) || 0 : 0;
                    const discountLine = discountInput ? parseFloat(discountInput.value) || 0 : 0;
                    const tax_rate = taxSelect ? parseFloat(taxSelect.value) || 0 : 0;
                    const inventory_item_id = itemSelect && itemSelect.value ? itemSelect.value : null;
                    const line_total = quantity * unit_price;

                    payload.line_items.push({
                        inventory_item_id: inventory_item_id,
                        description: description,
                        quantity: quantity,
                        unit_price: unit_price,
                        discount: discountLine,
                        tax_rate: tax_rate,
                        line_total: line_total
                    });
                });

                const res = await fetch('/qi/ajax/save_invoice.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.ok) {
                    document.getElementById('formMessage').className = 'fw-qi__form-message fw-qi__form-message--success';
                    document.getElementById('formMessage').textContent = 'Invoice created successfully!';
                    document.getElementById('formMessage').style.display = 'block';
                    setTimeout(() => {
                        window.location.href = '/qi/invoice_view.php?id=' + data.invoice_id;
                    }, 1000);
                } else {
                    throw new Error(data.error || 'Failed to create invoice');
                }
            } catch (err) {
                document.getElementById('formMessage').className = 'fw-qi__form-message fw-qi__form-message--error';
                document.getElementById('formMessage').textContent = err.message;
                document.getElementById('formMessage').style.display = 'block';
                btn.disabled = false;
                btn.querySelector('.fw-qi__btn-text').style.display = 'inline';
                btn.querySelector('.fw-qi__spinner').style.display = 'none';
            }
        });
    </script>

    <!-- Import items from project board functionality -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const projectSelect = document.querySelector('select[name="project_id"]');
        const boardRow     = document.getElementById('boardRow');
        const boardSelect  = document.getElementById('boardSelect');
        const importBtn    = document.getElementById('importBoardBtn');

        async function loadBoards(projectId) {
            if (!boardRow || !boardSelect) return;
            if (!projectId) {
                boardRow.style.display = 'none';
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
                    boardRow.style.display = 'flex';
                } else {
                    boardSelect.innerHTML = '<option value="">No boards</option>';
                    boardRow.style.display = 'none';
                }
            } catch (err) {
                console.error(err);
                boardSelect.innerHTML = '<option value="">Error loading boards</option>';
                boardRow.style.display = 'none';
            }
        }

        if (projectSelect) {
            projectSelect.addEventListener('change', function() {
                loadBoards(this.value);
            });
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
                            if (typeof QI.addLineItem === 'function') {
                                QI.addLineItem();
                                // Get last line item element
                                const lines = document.querySelectorAll('.fw-qi__line-item');
                                const lastLine = lines[lines.length - 1];
                                if (lastLine) {
                                    const descInput  = lastLine.querySelector('input[name*="[description]"]');
                                    const qtyInput   = lastLine.querySelector('.line-quantity');
                                    const unitInput  = lastLine.querySelector('input[name*="[unit]"]');
                                    const priceInput = lastLine.querySelector('.line-price');
                                    const discountInput = lastLine.querySelector('.line-discount');
                                    const taxSelect  = lastLine.querySelector('.line-tax-rate');
                                    if (descInput)   descInput.value = item.description || '';
                                    if (qtyInput)    qtyInput.value = item.quantity || 1;
                                    if (unitInput)   unitInput.value = unitInput.value || 'unit';
                                    if (priceInput)  priceInput.value = item.unit_price || 0;
                                    if (discountInput) discountInput.value = 0;
                                    if (taxSelect) {
                                        // Set tax rate if matches option
                                        const rate = parseFloat(item.tax_rate || 0).toFixed(2);
                                        for (let i = 0; i < taxSelect.options.length; i++) {
                                            if (parseFloat(taxSelect.options[i].value).toFixed(2) === rate) {
                                                taxSelect.selectedIndex = i;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                        if (typeof QI.calculateTotals === 'function') {
                            QI.calculateTotals();
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
</body>
</html>