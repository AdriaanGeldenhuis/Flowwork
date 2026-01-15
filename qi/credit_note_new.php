<?php
// /qi/credit_note_new.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Optional: pre-fill from invoice
$invoiceId = filter_input(INPUT_GET, 'invoice_id', FILTER_VALIDATE_INT);
$invoice = null;
$invoiceLines = [];

if ($invoiceId) {
    $stmt = $DB->prepare("
        SELECT i.*, ca.name AS customer_name
        FROM invoices i
        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
        WHERE i.id = ? AND i.company_id = ?
    ");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch();
    
    if ($invoice) {
        $stmt = $DB->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
        $stmt->execute([$invoiceId]);
        $invoiceLines = $stmt->fetchAll();
    }
}

// Fetch customers
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'customer' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$customers = $stmt->fetchAll();

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$issueDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Credit Note – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-qi__app-name">New Credit Note</div>
                </div>
            </div>

            <div class="fw-qi__greeting">
                Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-qi__controls">
                <a href="/qi/?tab=credit_notes" class="fw-qi__home-btn" title="Back">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <button class="fw-qi__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-qi__theme-icon fw-qi__theme-icon--light" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    <svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </header>

        <main class="fw-qi__main">
            <div class="fw-qi__form-header">
                <h1 class="fw-qi__form-title">Create Credit Note</h1>
            </div>

            <form id="creditNoteForm" class="fw-qi__form">
                
                <div class="fw-qi__form-section">
                    <h2 class="fw-qi__form-section-title">Basic Information</h2>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Customer <span class="fw-qi__required">*</span></label>
                            <select name="customer_id" class="fw-qi__input" required>
                                <option value="">Select Customer...</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $invoice && $invoice['customer_id'] == $c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Issue Date <span class="fw-qi__required">*</span></label>
                            <input type="date" name="issue_date" class="fw-qi__input" value="<?= $issueDate ?>" required>
                        </div>
                    </div>

                    <?php if ($invoice): ?>
                        <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                        <div class="fw-qi__alert fw-qi__alert--info">
                            <strong>Linked to Invoice:</strong> <?= htmlspecialchars($invoice['invoice_number']) ?> (R <?= number_format($invoice['total'], 2) ?>)
                        </div>
                    <?php endif; ?>

                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Reason for Credit Note <span class="fw-qi__required">*</span></label>
                        <textarea name="reason" class="fw-qi__textarea" rows="3" placeholder="e.g., Product return, billing error, discount adjustment..." required></textarea>
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
                            <span>VAT (15%):</span>
                            <strong id="displayTax">R 0.00</strong>
                        </div>
                        <div class="fw-qi__total-row fw-qi__total-row--grand">
                            <span>Credit Total:</span>
                            <strong id="displayTotal">R 0.00</strong>
                        </div>
                    </div>
                    <input type="hidden" name="subtotal" id="inputSubtotal" value="0">
                    <input type="hidden" name="tax" id="inputTax" value="0">
                    <input type="hidden" name="total" id="inputTotal" value="0">
                </div>

                <div class="fw-qi__form-actions">
                    <a href="/qi/?tab=credit_notes" class="fw-qi__btn fw-qi__btn--secondary">Cancel</a>
                    <button type="submit" class="fw-qi__btn fw-qi__btn--primary" id="btnSaveCreditNote">
                        <span class="fw-qi__btn-text">Create Credit Note</span>
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
        <?php if (!empty($invoiceLines)): ?>
        // Pre-fill lines from invoice
        window.addEventListener('DOMContentLoaded', () => {
            <?php foreach ($invoiceLines as $line): ?>
                QI.addLineItem();
                const lastLine = document.querySelector('.fw-qi__line-item:last-child');
                lastLine.querySelector('[name*="[description]"]').value = <?= json_encode($line['item_description']) ?>;
                lastLine.querySelector('[name*="[quantity]"]').value = <?= json_encode($line['quantity']) ?>;
                lastLine.querySelector('[name*="[unit]"]').value = <?= json_encode($line['unit'] ?? 'unit') ?>;
                lastLine.querySelector('[name*="[unit_price]"]').value = <?= json_encode($line['unit_price']) ?>;
                lastLine.querySelector('[name*="[tax_rate]"]').value = <?= json_encode($line['tax_rate']) ?>;
            <?php endforeach; ?>
            QI.calculateTotals();
        });
        <?php endif; ?>

        document.getElementById('creditNoteForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (document.querySelectorAll('.fw-qi__line-item').length === 0) {
                alert('Please add at least one line item');
                return;
            }

            const btn = document.getElementById('btnSaveCreditNote');
            btn.disabled = true;
            btn.querySelector('.fw-qi__btn-text').style.display = 'none';
            btn.querySelector('.fw-qi__spinner').style.display = 'inline-block';

            try {
                const formData = new FormData(this);
                const res = await fetch('/qi/ajax/save_credit_note.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await res.json();

                if (data.ok) {
                    document.getElementById('formMessage').className = 'fw-qi__form-message fw-qi__form-message--success';
                    document.getElementById('formMessage').textContent = 'Credit note created successfully!';
                    document.getElementById('formMessage').style.display = 'block';
                    setTimeout(() => {
                        window.location.href = '/qi/credit_note_view.php?id=' + data.credit_note_id;
                    }, 1000);
                } else {
                    throw new Error(data.error || 'Failed to create credit note');
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
</body>
</html>