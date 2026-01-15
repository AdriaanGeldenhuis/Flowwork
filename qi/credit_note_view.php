<?php
// /qi/credit_note_view.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$creditNoteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$creditNoteId) {
    header('Location: /qi/?tab=credit_notes');
    exit;
}

// Fetch credit note
$stmt = $DB->prepare("
    SELECT cn.*, ca.name AS customer_name,
           i.invoice_number, i.total AS invoice_total,
           u.first_name, u.last_name
    FROM credit_notes cn
    LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id
    LEFT JOIN invoices i ON cn.invoice_id = i.id
    LEFT JOIN users u ON cn.created_by = u.id
    WHERE cn.id = ? AND cn.company_id = ?
");
$stmt->execute([$creditNoteId, $companyId]);
$creditNote = $stmt->fetch();

if (!$creditNote) {
    header('Location: /qi/?tab=credit_notes');
    exit;
}

// Fetch lines
$stmt = $DB->prepare("SELECT * FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
$stmt->execute([$creditNoteId]);
$lines = $stmt->fetchAll();

// Fetch company/user info
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

$canApply = ($creditNote['status'] === 'approved' && $creditNote['invoice_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($creditNote['credit_note_number']) ?> – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-qi__app-name"><?= htmlspecialchars($creditNote['credit_note_number']) ?></div>
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
                <button class="fw-qi__theme-toggle" id="themeToggle"></button>
                <div class="fw-qi__menu-wrapper">
                    <button class="fw-qi__kebab-toggle" id="kebabToggle">⋮</button>
                    <nav class="fw-qi__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <?php if ($creditNote['status'] === 'draft'): ?>
                            <button onclick="CNView.approve()" class="fw-qi__kebab-item">Approve</button>
                        <?php endif; ?>
                        <?php if ($canApply): ?>
                            <button onclick="CNView.applyToInvoice()" class="fw-qi__kebab-item">Apply to Invoice</button>
                        <?php endif; ?>
                        <button onclick="CNView.downloadPDF()" class="fw-qi__kebab-item">Download PDF</button>
                    </nav>
                </div>
            </div>
        </header>

        <main class="fw-qi__main">
            <div class="fw-qi__view-header">
                <div class="fw-qi__view-header-left">
                    <h1 class="fw-qi__view-title"><?= htmlspecialchars($creditNote['credit_note_number']) ?></h1>
                    <div class="fw-qi__view-meta">
                        <span class="fw-qi__badge fw-qi__badge--<?= $creditNote['status'] ?>">
                            <?= ucfirst($creditNote['status']) ?>
                        </span>
                        <span>•</span>
                        <span>Issued: <?= date('d M Y', strtotime($creditNote['issue_date'])) ?></span>
                        <?php if ($creditNote['invoice_number']): ?>
                            <span>•</span>
                            <span>Invoice: <a href="/qi/invoice_view.php?id=<?= $creditNote['invoice_id'] ?>"><?= htmlspecialchars($creditNote['invoice_number']) ?></a></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="fw-qi__view-header-right">
                    <div class="fw-qi__view-total" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <div class="fw-qi__view-total-label">Credit Amount</div>
                        <div class="fw-qi__view-total-value">R <?= number_format($creditNote['total'], 2) ?></div>
                    </div>
                </div>
            </div>

            <div class="fw-qi__info-grid">
                <div class="fw-qi__info-card">
                    <h3 class="fw-qi__info-card-title">Customer</h3>
                    <dl class="fw-qi__info-list">
                        <div class="fw-qi__info-item">
                            <dt>Name:</dt>
                            <dd><strong><?= htmlspecialchars($creditNote['customer_name']) ?></strong></dd>
                        </div>
                    </dl>
                </div>

                <div class="fw-qi__info-card">
                    <h3 class="fw-qi__info-card-title">Reason</h3>
                    <p class="fw-qi__notes"><?= nl2br(htmlspecialchars($creditNote['reason'])) ?></p>
                </div>
            </div>

            <div class="fw-qi__section">
                <h2 class="fw-qi__section-title">Line Items</h2>
                <div class="fw-qi__table-container">
                    <table class="fw-qi__table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Tax Rate</th>
                                <th class="fw-qi__table-align-right">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $idx => $line): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><?= htmlspecialchars($line['item_description']) ?></td>
                                    <td><?= number_format($line['quantity'], 3) ?></td>
                                    <td>R <?= number_format($line['unit_price'], 2) ?></td>
                                    <td><?= number_format($line['tax_rate'], 2) ?>%</td>
                                    <td class="fw-qi__table-align-right"><strong>R <?= number_format($line['line_total'], 2) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="fw-qi__totals-summary">
                    <div class="fw-qi__total-row">
                        <span>Subtotal:</span>
                        <strong>R <?= number_format($creditNote['subtotal'], 2) ?></strong>
                    </div>
                    <div class="fw-qi__total-row">
                        <span>VAT (15%):</span>
                        <strong>R <?= number_format($creditNote['tax'], 2) ?></strong>
                    </div>
                    <div class="fw-qi__total-row fw-qi__total-row--grand">
                        <span>Total Credit:</span>
                        <strong>R <?= number_format($creditNote['total'], 2) ?></strong>
                    </div>
                </div>
            </div>
        </main>

        <footer class="fw-qi__footer">
            <span>Quotes & Invoices v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>

    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        window.CNView = {
            creditNoteId: <?= $creditNoteId ?>,

            async approve() {
                if (!confirm('Approve this credit note?')) return;

                try {
                    const res = await fetch('/qi/ajax/approve_credit_note.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({credit_note_id: this.creditNoteId})
                    });
                    const data = await res.json();

                    if (data.ok) {
                        alert('Credit note approved');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed'));
                    }
                } catch (err) {
                    alert('Network error');
                }
            },

            async applyToInvoice() {
                if (!confirm('Apply this credit note to the linked invoice?')) return;

                try {
                    const res = await fetch('/qi/ajax/apply_credit_note.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({credit_note_id: this.creditNoteId})
                    });
                    const data = await res.json();

                    if (data.ok) {
                        alert('Credit note applied successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Failed'));
                    }
                } catch (err) {
                    alert('Network error');
                }
            },

            downloadPDF() {
                window.open('/qi/ajax/generate_pdf.php?type=credit_note&id=' + this.creditNoteId, '_blank');
            }
        };
    </script>
</body>
</html>