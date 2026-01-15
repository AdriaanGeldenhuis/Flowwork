<?php
// /qi/recurring.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch company/user info
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch recurring invoices
$stmt = $DB->prepare("
    SELECT ri.*, ca.name AS customer_name,
           (SELECT COUNT(*) FROM invoices WHERE quote_id IS NULL AND created_at >= ri.created_at) as generated_count
    FROM recurring_invoices ri
    LEFT JOIN crm_accounts ca ON ri.customer_id = ca.id
    WHERE ri.company_id = ?
    ORDER BY ri.active DESC, ri.next_run_date ASC
");
$stmt->execute([$companyId]);
$recurring = $stmt->fetchAll();

// Fetch customers for modal
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'customer' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Invoices – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M23 6l-9.5 9.5-5-5L1 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="17 6 23 6 23 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="12" cy="12" r="2" fill="currentColor"/>
                    </svg>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-qi__app-name">Recurring Invoices</div>
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
                    </svg>
                    <svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        </header>

        <main class="fw-qi__main">

            <div class="fw-qi__form-header">
                <h1 class="fw-qi__form-title">Recurring Invoices</h1>
                <button class="fw-qi__btn fw-qi__btn--primary" onclick="RecurringView.openNewModal()">
                    + New Recurring Invoice
                </button>
            </div>

            <div class="fw-qi__alert fw-qi__alert--info">
                <strong>How it works:</strong> Recurring invoices are automatically generated on their scheduled date. Each invoice is sent to the customer and tracked separately.
            </div>

            <?php if (empty($recurring)): ?>
                <div class="fw-qi__empty-state">
                    <div class="fw-qi__empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="64" height="64">
                            <path d="M23 6l-9.5 9.5-5-5L1 18"/>
                            <polyline points="17 6 23 6 23 12"/>
                        </svg>
                    </div>
                    <h3>No Recurring Invoices</h3>
                    <p>Set up automatic billing for your regular customers</p>
                    <button class="fw-qi__btn fw-qi__btn--primary" onclick="RecurringView.openNewModal()">
                        Create First Recurring Invoice
                    </button>
                </div>
            <?php else: ?>
                <div class="fw-qi__recurring-grid">
                    <?php foreach ($recurring as $rec): ?>
                        <div class="fw-qi__recurring-card <?= $rec['active'] ? '' : 'fw-qi__recurring-card--inactive' ?>">
                            <div class="fw-qi__recurring-header">
                                <div>
                                    <h3 class="fw-qi__recurring-title"><?= htmlspecialchars($rec['template_name']) ?></h3>
                                    <div class="fw-qi__recurring-customer"><?= htmlspecialchars($rec['customer_name']) ?></div>
                                </div>
                                <div class="fw-qi__recurring-status">
                                    <?php if ($rec['active']): ?>
                                        <span class="fw-qi__badge fw-qi__badge--accepted">Active</span>
                                    <?php else: ?>
                                        <span class="fw-qi__badge fw-qi__badge--inactive">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="fw-qi__recurring-body">
                                <div class="fw-qi__recurring-detail">
                                    <span class="fw-qi__recurring-label">Frequency:</span>
                                    <span class="fw-qi__recurring-value">
                                        <?= $rec['interval_count'] ?> <?= ucfirst($rec['frequency']) ?><?= $rec['interval_count'] > 1 ? 's' : '' ?>
                                    </span>
                                </div>

                                <div class="fw-qi__recurring-detail">
                                    <span class="fw-qi__recurring-label">Next Invoice:</span>
                                    <span class="fw-qi__recurring-value">
                                        <?= $rec['active'] ? date('d M Y', strtotime($rec['next_run_date'])) : 'N/A' ?>
                                    </span>
                                </div>

                                <?php if ($rec['end_date']): ?>
                                    <div class="fw-qi__recurring-detail">
                                        <span class="fw-qi__recurring-label">Ends:</span>
                                        <span class="fw-qi__recurring-value"><?= date('d M Y', strtotime($rec['end_date'])) ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="fw-qi__recurring-detail">
                                    <span class="fw-qi__recurring-label">Generated:</span>
                                    <span class="fw-qi__recurring-value"><?= $rec['generated_count'] ?? 0 ?> invoices</span>
                                </div>
                            </div>

                            <div class="fw-qi__recurring-actions">
                                <?php if ($rec['active']): ?>
                                    <button class="fw-qi__btn fw-qi__btn--small fw-qi__btn--secondary" onclick="RecurringView.pause(<?= $rec['id'] ?>)">
                                        Pause
                                    </button>
                                    <button class="fw-qi__btn fw-qi__btn--small fw-qi__btn--primary" onclick="RecurringView.runNow(<?= $rec['id'] ?>)">
                                        Generate Now
                                    </button>
                                <?php else: ?>
                                    <button class="fw-qi__btn fw-qi__btn--small fw-qi__btn--primary" onclick="RecurringView.resume(<?= $rec['id'] ?>)">
                                        Resume
                                    </button>
                                <?php endif; ?>
                                <button class="fw-qi__btn fw-qi__btn--small fw-qi__btn--secondary" onclick="RecurringView.edit(<?= $rec['id'] ?>)">
                                    Edit
                                </button>
                                <button class="fw-qi__btn fw-qi__btn--small fw-qi__btn--danger" onclick="RecurringView.deleteRecurring(<?= $rec['id'] ?>)">
                                    Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </main>

        <footer class="fw-qi__footer">
            <span>Quotes & Invoices v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>

    <!-- New Recurring Modal -->
    <div class="fw-qi__modal-overlay" id="modalNewRecurring">
        <div class="fw-qi__modal">
            <div class="fw-qi__modal-header">
                <h2 class="fw-qi__modal-title">New Recurring Invoice</h2>
                <button class="fw-qi__modal-close" onclick="RecurringView.closeModal('modalNewRecurring')">×</button>
            </div>
            <div class="fw-qi__modal-body">
                <form id="formNewRecurring">
                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Template Name <span class="fw-qi__required">*</span></label>
                        <input type="text" name="template_name" class="fw-qi__input" placeholder="e.g., Monthly Subscription" required>
                    </div>

                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Customer <span class="fw-qi__required">*</span></label>
                        <select name="customer_id" class="fw-qi__input" required>
                            <option value="">Select Customer...</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Frequency <span class="fw-qi__required">*</span></label>
                            <select name="frequency" class="fw-qi__input" required>
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Every <span class="fw-qi__required">*</span></label>
                            <input type="number" name="interval_count" class="fw-qi__input" value="1" min="1" max="12" required>
                        </div>
                    </div>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Start Date <span class="fw-qi__required">*</span></label>
                            <input type="date" name="next_run_date" class="fw-qi__input" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">End Date (Optional)</label>
                            <input type="date" name="end_date" class="fw-qi__input">
                        </div>
                    </div>

                    <div class="fw-qi__form-section">
                        <h3 class="fw-qi__form-section-title">Line Items</h3>
                        <div id="recurringLinesContainer"></div>
                        <button type="button" class="fw-qi__btn fw-qi__btn--secondary" onclick="RecurringView.addLineItem()">
                            + Add Line Item
                        </button>
                    </div>

                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Payment Terms</label>
                        <textarea name="terms" class="fw-qi__textarea" rows="3" placeholder="Payment terms..."></textarea>
                    </div>

                    <div class="fw-qi__form-group">
                        <label class="fw-qi__label">Notes</label>
                        <textarea name="notes" class="fw-qi__textarea" rows="2" placeholder="Internal notes..."></textarea>
                    </div>
                </form>
            </div>
            <div class="fw-qi__modal-footer">
                <button class="fw-qi__btn fw-qi__btn--secondary" onclick="RecurringView.closeModal('modalNewRecurring')">Cancel</button>
                <button class="fw-qi__btn fw-qi__btn--primary" onclick="RecurringView.saveRecurring()">
                    <span class="fw-qi__btn-text">Create Recurring Invoice</span>
                    <span class="fw-qi__spinner" style="display:none;"></span>
                </button>
            </div>
        </div>
    </div>

    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        window.RecurringView = {
            lineCounter: 0,

            openNewModal() {
                this.lineCounter = 0;
                document.getElementById('recurringLinesContainer').innerHTML = '';
                document.getElementById('formNewRecurring').reset();
                this.addLineItem();
                document.getElementById('modalNewRecurring').classList.add('fw-qi__modal-overlay--active');
            },

            addLineItem() {
                this.lineCounter++;
                const container = document.getElementById('recurringLinesContainer');
                
                const lineDiv = document.createElement('div');
                lineDiv.className = 'fw-qi__line-item';
                lineDiv.dataset.lineId = this.lineCounter;
                
                lineDiv.innerHTML = `
                    <div class="fw-qi__line-item-header">
                        <span class="fw-qi__line-item-number">#${this.lineCounter}</span>
                        <button type="button" class="fw-qi__btn fw-qi__btn--small fw-qi__btn--danger" onclick="RecurringView.removeLineItem(${this.lineCounter})">
                            Remove
                        </button>
                    </div>
                    
                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group" style="grid-column: span 2;">
                            <label class="fw-qi__label">Description</label>
                            <input type="text" name="lines[${this.lineCounter}][description]" class="fw-qi__input" placeholder="Item description" required>
                        </div>
                    </div>

                    <div class="fw-qi__form-row">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Quantity</label>
                            <input type="number" name="lines[${this.lineCounter}][quantity]" class="fw-qi__input" step="0.001" value="1.000" min="0" required>
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Unit</label>
                            <input type="text" name="lines[${this.lineCounter}][unit]" class="fw-qi__input" value="unit">
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Unit Price (R)</label>
                            <input type="number" name="lines[${this.lineCounter}][unit_price]" class="fw-qi__input" step="0.01" value="0.00" min="0" required>
                        </div>

                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Tax Rate (%)</label>
                            <select name="lines[${this.lineCounter}][tax_rate]" class="fw-qi__input">
                                <option value="15.00">15% (Standard)</option>
                                <option value="0.00">0% (Zero-rated)</option>
                            </select>
                        </div>
                    </div>
                `;

                container.appendChild(lineDiv);
            },

            removeLineItem(lineId) {
                const lineDiv = document.querySelector(`[data-line-id="${lineId}"]`);
                if (lineDiv) lineDiv.remove();
            },

            async saveRecurring() {
                const form = document.getElementById('formNewRecurring');
                
                if (document.querySelectorAll('.fw-qi__line-item').length === 0) {
                    alert('Please add at least one line item');
                    return;
                }

                const btn = event.target;
                btn.disabled = true;
                btn.querySelector('.fw-qi__btn-text').style.display = 'none';
                btn.querySelector('.fw-qi__spinner').style.display = 'inline-block';

                try {
                    const formData = new FormData(form);
                    const res = await fetch('/qi/ajax/save_recurring.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if (data.ok) {
                        alert('Recurring invoice created successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed to save'));
                        btn.disabled = false;
                        btn.querySelector('.fw-qi__btn-text').style.display = 'inline';
                        btn.querySelector('.fw-qi__spinner').style.display = 'none';
                    }
                } catch (err) {
                    alert('Network error');
                    btn.disabled = false;
                    btn.querySelector('.fw-qi__btn-text').style.display = 'inline';
                    btn.querySelector('.fw-qi__spinner').style.display = 'none';
                }
            },

            async pause(id) {
                if (!confirm('Pause this recurring invoice?')) return;

                try {
                    const res = await fetch('/qi/ajax/toggle_recurring.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id: id, active: 0})
                    });
                    const data = await res.json();

                    if (data.ok) {
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed'));
                    }
                } catch (err) {
                    alert('Network error');
                }
            },

            async resume(id) {
                if (!confirm('Resume this recurring invoice?')) return;

                try {
                    const res = await fetch('/qi/ajax/toggle_recurring.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id: id, active: 1})
                    });
                    const data = await res.json();

                    if (data.ok) {
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed'));
                    }
                } catch (err) {
                    alert('Network error');
                }
            },

            async runNow(id) {
                if (!confirm('Generate invoice now from this template?')) return;

                try {
                    const res = await fetch('/qi/ajax/run_recurring.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id: id})
                    });
                    const data = await res.json();

                    if (data.ok) {
                        alert('Invoice generated successfully!');
                        window.location.href = '/qi/invoice_view.php?id=' + data.invoice_id;
                    } else {
                        alert('Error: ' + (data.error || 'Failed'));
                    }
                } catch (err) {
                    alert('Network error');
                }
            },

            async deleteRecurring(id) {
                if (!confirm('Delete this recurring invoice? This will not affect already generated invoices.')) return;

                try {
                    const res = await fetch('/qi/ajax/delete_recurring.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id: id})
                    });
                    const data = await res.json();

                    if (data.ok) {
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed'));
                    }
                } catch (err) {
                    alert('Network error');
                }
            },

            edit(id) {
                alert('Edit functionality coming soon!');
            },

            closeModal(modalId) {
                document.getElementById(modalId).classList.remove('fw-qi__modal-overlay--active');
            }
        };
    </script>
</body>
</html>