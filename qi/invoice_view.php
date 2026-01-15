<?php
// /qi/invoice_view.php – Display a single invoice with actions
// This page closely mirrors quote_view.php but adapted for invoices.
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-QI-FINAL');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$invoiceId) {
    header('Location: /qi/?tab=invoices');
    exit;
}

try {
    // Fetch invoice details with company, customer and creator
    $stmt = $DB->prepare(
        "SELECT i.*,\n               u.first_name AS creator_first_name,\n               u.last_name AS creator_last_name,\n               c.name AS company_name,\n               c.logo_url,\n               c.vat_number,\n               c.tax_number,\n               c.reg_number,\n               c.website,\n               c.phone AS company_phone,\n               c.email AS company_email,\n               c.address_line1 AS company_address1,\n               c.address_line2 AS company_address2,\n               c.city AS company_city,\n               c.region AS company_region,\n               c.postal AS company_postal,\n               c.bank_name,\n               c.bank_account_number,\n               c.bank_branch_code,\n               c.invoice_footer_text,\n               c.primary_color,\n               c.secondary_color,\n               c.qi_font_family,\n               c.qi_show_company_address,\n               c.qi_show_company_phone,\n               c.qi_show_company_email,\n               c.qi_show_company_website,\n               c.qi_show_vat_number,\n               c.qi_show_tax_number,\n               c.qi_show_reg_number,\n               ca.name AS customer_name,\n               ca.email AS customer_email,\n               ca.phone AS customer_phone,\n               p.name AS project_name\n        FROM invoices i\n        LEFT JOIN users u ON i.created_by = u.id\n        LEFT JOIN companies c ON i.company_id = c.id\n        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id\n        LEFT JOIN projects p ON i.project_id = p.project_id\n        WHERE i.id = ? AND i.company_id = ?"
    );
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        header('Location: /qi/?tab=invoices');
        exit;
    }

    // Fetch line items
    $stmt = $DB->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
    $stmt->execute([$invoiceId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current user name for greeting
    $stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $firstName = $userRow['first_name'] ?? 'User';

} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Permissions
$canEdit = ($invoice['status'] === 'draft');
$canSend = in_array($invoice['status'], ['draft', 'sent']);
$canDelete = ($invoice['status'] === 'draft');

// Colour and font customisation
$primaryColor   = $invoice['primary_color'] ?? '#fbbf24';
$secondaryColor = $invoice['secondary_color'] ?? '#f59e0b';
$fontFamily     = $invoice['qi_font_family'] ?? 'system-ui';
$fontMap = [
    'system-ui'  => 'system-ui, -apple-system, sans-serif',
    'montserrat' => "'Montserrat', sans-serif",
    'helvetica'  => "'Helvetica Neue', Helvetica, Arial, sans-serif",
    'georgia'    => 'Georgia, serif',
    'inter'      => "'Inter', sans-serif"
];
$fontStack = $fontMap[$fontFamily] ?? $fontMap['system-ui'];

$showAddress = (int)($invoice['qi_show_company_address'] ?? 1);
$showPhone   = (int)($invoice['qi_show_company_phone']   ?? 1);
$showEmail   = (int)($invoice['qi_show_company_email']   ?? 1);
$showWebsite = (int)($invoice['qi_show_company_website'] ?? 1);
$showVat     = (int)($invoice['qi_show_vat_number']      ?? 1);
$showTax     = (int)($invoice['qi_show_tax_number']      ?? 1);
$showReg     = (int)($invoice['qi_show_reg_number']      ?? 1);

// Helper to format amounts
function format_currency($amount) {
    return 'R ' . number_format((float)$amount, 2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($invoice['invoice_number']) ?> – <?= htmlspecialchars($invoice['company_name']) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= ASSET_VERSION ?>">

    <style>
        :root {
            --accent-qi: <?= $primaryColor ?>;
            --accent-qi-secondary: <?= $secondaryColor ?>;
            --doc-font: <?= $fontStack ?>;
        }
        .fw-qi__document, .fw-qi__document * { font-family: <?= $fontStack ?> !important; }
        .fw-qi__doc-title { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-company h1 { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-company strong { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-table thead { background: <?= $primaryColor ?> !important; }
        .fw-qi__doc-header { border-bottom-color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-section h3 { border-bottom-color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-detail-box { border-left-color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-detail-box h3 { color: <?= $primaryColor ?> !important; }
        .fw-qi__doc-total-row--grand { color: <?= $primaryColor ?> !important; border-top-color: <?= $primaryColor ?> !important; }
        @media print {
            .fw-qi__doc-table thead { background: <?= $primaryColor ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .fw-qi__doc-title { color: <?= $primaryColor ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .fw-qi__doc-company h1 { color: <?= $primaryColor ?> !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <?php if ($invoice['logo_url']): ?>
                        <img src="<?= htmlspecialchars($invoice['logo_url']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/></svg>
                    <?php endif; ?>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($invoice['company_name']) ?></div>
                    <div class="fw-qi__app-name"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
                </div>
            </div>
            <div class="fw-qi__greeting">Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span></div>
            <div class="fw-qi__controls">
                <a href="/qi/?tab=invoices" class="fw-qi__home-btn" title="Back"><svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></a>
                <button class="fw-qi__theme-toggle" id="themeToggle"><svg class="fw-qi__theme-icon fw-qi__theme-icon--light" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/></svg><svg class="fw-qi__theme-icon fw-qi__theme-icon--dark" viewBox="0 0 24 24" fill="none"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/></svg></button>
                <div class="fw-qi__menu-wrapper">
                    <button class="fw-qi__kebab-toggle" id="kebabToggle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px;">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-qi__kebab-menu" id="kebabMenu">
                        <?php if ($canEdit): ?>
                            <a href="/qi/invoice_edit.php?id=<?= $invoiceId ?>" class="fw-qi__kebab-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                </svg>
                                Edit Invoice
                            </a>
                        <?php endif; ?>

                        <?php if ($canSend): ?>
                            <button onclick="InvoiceView.sendInvoice()" class="fw-qi__kebab-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                    <line x1="22" y1="2" x2="11" y2="13" />
                                    <polygon points="22 2 15 22 11 13 2 9 22 2" />
                                </svg>
                                Send to Customer
                            </button>
                        <?php endif; ?>

                        <?php if ($canDelete): ?>
                            <hr style="margin:8px 0;border:none;border-top:1px solid var(--fw-border);">
                            <button onclick="InvoiceView.deleteInvoice()" class="fw-qi__kebab-item" style="color:#ef4444;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                    <polyline points="3 6 5 6 21 6" />
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                </svg>
                                Delete Invoice
                            </button>
                        <?php endif; ?>

                        <hr style="margin:8px 0;border:none;border-top:1px solid var(--fw-border);">
                        <button onclick="window.print()" class="fw-qi__kebab-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                <polyline points="6 9 6 2 18 2 18 9" />
                                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                                <rect x="6" y="14" width="12" height="8" />
                            </svg>
                            Print
                        </button>

                        <?php if ($invoice['status'] !== 'paid'): ?>
                            <button onclick="InvoiceView.openPaymentModal()" class="fw-qi__kebab-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                    <path d="M12 20h9" />
                                    <path d="M12 4h9" />
                                    <path d="M3 8h6a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H3z" />
                                    <path d="M21 8h-6a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h6z" />
                                </svg>
                                Record Payment
                            </button>
                        <?php endif; ?>

                        <?php // Apply Credit Note: allow user to apply an approved credit note to this invoice ?>
                        <?php if ($invoice['status'] !== 'paid'): ?>
                            <button onclick="InvoiceView.applyCreditNote()" class="fw-qi__kebab-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h8" />
                                    <polyline points="14 2 14 8 20 8" />
                                    <path d="M16 16l5 5m0 0l-5-5m5 5H14" />
                                </svg>
                                Apply Credit Note
                            </button>
                        <?php endif; ?>

                        <?php // Email Log: show email history for this invoice ?>
                        <button onclick="InvoiceView.viewEmailLog()" class="fw-qi__kebab-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                <path d="M4 4h16v16H4z" />
                                <polyline points="22,6 12,13 2,6" />
                            </svg>
                            Email Log
                        </button>
                        <?php // Yoco payment link actions: allow creation or open existing link if invoice not paid ?>
                        <?php if ($invoice['status'] !== 'paid'): ?>
                            <?php if (empty($invoice['yoco_payment_link'])): ?>
                                <button onclick="InvoiceView.createPaymentLink()" class="fw-qi__kebab-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                        <path d="M5 12h14M12 5l7 7-7 7" />
                                    </svg>
                                    Create Payment Link
                                </button>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($invoice['yoco_payment_link']) ?>" target="_blank" class="fw-qi__kebab-item">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                        <path d="M10 6h4v4" />
                                        <path d="M6 18V10h4" />
                                        <path d="M14 14h4v4" />
                                        <path d="M10 10h4v4" />
                                    </svg>
                                    Open Payment Link
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button onclick="InvoiceView.downloadPDF()" class="fw-qi__kebab-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                <polyline points="7 10 12 15 17 10" />
                                <line x1="12" y1="15" x2="12" y2="3" />
                            </svg>
                            Download PDF
                        </button>
                    </nav>
                </div>
            </div>
        </header>

        <main class="fw-qi__main">
            <div class="fw-qi__document">
                <!-- Document Header -->
                <div class="fw-qi__doc-header">
                    <div class="fw-qi__doc-company">
                        <h1 class="fw-qi__doc-title">Invoice</h1>
                        <?php if ($showAddress): ?>
                            <p><?= htmlspecialchars($invoice['company_address1']) ?><br><?= htmlspecialchars($invoice['company_address2']) ?><br><?= htmlspecialchars($invoice['company_city']) ?>, <?= htmlspecialchars($invoice['company_region']) ?> <?= htmlspecialchars($invoice['company_postal']) ?></p>
                        <?php endif; ?>
                        <?php if ($showPhone && $invoice['company_phone']): ?>
                            <p>Tel: <?= htmlspecialchars($invoice['company_phone']) ?></p>
                        <?php endif; ?>
                        <?php if ($showEmail && $invoice['company_email']): ?>
                            <p>Email: <?= htmlspecialchars($invoice['company_email']) ?></p>
                        <?php endif; ?>
                        <?php if ($showWebsite && $invoice['website']): ?>
                            <p>Website: <?= htmlspecialchars($invoice['website']) ?></p>
                        <?php endif; ?>
                        <?php if ($showVat && $invoice['vat_number']): ?>
                            <p>VAT No: <?= htmlspecialchars($invoice['vat_number']) ?></p>
                        <?php endif; ?>
                        <?php if ($showTax && $invoice['tax_number']): ?>
                            <p>Tax No: <?= htmlspecialchars($invoice['tax_number']) ?></p>
                        <?php endif; ?>
                        <?php if ($showReg && $invoice['reg_number']): ?>
                            <p>Reg No: <?= htmlspecialchars($invoice['reg_number']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="fw-qi__doc-meta">
                        <h2>Invoice #: <?= htmlspecialchars($invoice['invoice_number']) ?></h2>
                        <p>Issue Date: <?= htmlspecialchars(date('d M Y', strtotime($invoice['issue_date']))) ?></p>
                        <p>Due Date: <?= htmlspecialchars(date('d M Y', strtotime($invoice['due_date']))) ?></p>
                        <p>Status: <?= htmlspecialchars(ucfirst($invoice['status'])) ?></p>
                    </div>
                </div>

                <!-- Customer & Project Details -->
                <div class="fw-qi__doc-details">
                    <div class="fw-qi__doc-detail-box">
                        <h3>Bill To</h3>
                        <p><strong><?= htmlspecialchars($invoice['customer_name'] ?? 'Customer') ?></strong></p>
                        <?php if (!empty($invoice['customer_phone'])): ?>
                            <p>Phone: <?= htmlspecialchars($invoice['customer_phone']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($invoice['customer_email'])): ?>
                            <p>Email: <?= htmlspecialchars($invoice['customer_email']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($invoice['project_name']): ?>
                        <div class="fw-qi__doc-detail-box">
                            <h3>Project</h3>
                            <p><?= htmlspecialchars($invoice['project_name']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Line Items Table -->
                <table class="fw-qi__doc-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="text-align:right;">Qty</th>
                            <th style="text-align:right;">Unit Price</th>
                            <th style="text-align:right;">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                            <tr>
                                <td><?= htmlspecialchars($line['item_description']) ?></td>
                                <td style="text-align:right;"><?= number_format((float)$line['quantity'], 2) ?></td>
                                <td style="text-align:right;"><?= format_currency($line['unit_price']) ?></td>
                                <td style="text-align:right;"><?= format_currency($line['line_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Totals Summary -->
                <div class="fw-qi__doc-totals">
                    <div class="fw-qi__doc-total-row">
                        <span>Subtotal:</span>
                        <span><?= format_currency($invoice['subtotal']) ?></span>
                    </div>
                    <?php if ((float)$invoice['discount'] > 0): ?>
                        <div class="fw-qi__doc-total-row">
                            <span>Discount:</span>
                            <span><?= format_currency($invoice['discount']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="fw-qi__doc-total-row">
                        <span>VAT (15%):</span>
                        <span><?= format_currency($invoice['tax']) ?></span>
                    </div>
                    <div class="fw-qi__doc-total-row fw-qi__doc-total-row--grand">
                        <span>TOTAL:</span>
                        <span><?= format_currency($invoice['total']) ?></span>
                    </div>
                    <?php if ((float)$invoice['balance_due'] < (float)$invoice['total']): ?>
                        <div class="fw-qi__doc-total-row" style="margin-top:8px;">
                            <span>Balance Due:</span>
                            <span><?= format_currency($invoice['balance_due']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment & Bank Details -->
                <?php if ($invoice['bank_name'] || $invoice['bank_account_number']): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Payment Details</h3>
                        <p>
                            <?php if ($invoice['bank_name']): ?>
                                <strong>Bank:</strong> <?= htmlspecialchars($invoice['bank_name']) ?><br>
                            <?php endif; ?>
                            <?php if ($invoice['bank_account_number']): ?>
                                <strong>Account No:</strong> <?= htmlspecialchars($invoice['bank_account_number']) ?><br>
                            <?php endif; ?>
                            <?php if ($invoice['bank_branch_code']): ?>
                                <strong>Branch Code:</strong> <?= htmlspecialchars($invoice['bank_branch_code']) ?><br>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Terms & Notes -->
                <?php if ($invoice['terms']): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Terms & Conditions</h3>
                        <p><?= nl2br(htmlspecialchars($invoice['terms'])) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($invoice['notes']): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Internal Notes</h3>
                        <p><?= nl2br(htmlspecialchars($invoice['notes'])) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Footer Text -->
                <?php if (!empty($invoice['invoice_footer_text'])): ?>
                    <div class="fw-qi__doc-footer">
                        <p><?= nl2br(htmlspecialchars($invoice['invoice_footer_text'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="fw-qi__footer">
            <span>Q&I v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

        <!-- Payment Modal -->
        <div class="fw-qi__modal-overlay" id="paymentModalOverlay">
            <div class="fw-qi__modal">
                <div class="fw-qi__modal-header">
                    <h2 class="fw-qi__modal-title">Record Payment</h2>
                    <button class="fw-qi__modal-close" type="button" onclick="InvoiceView.closePaymentModal()">×</button>
                </div>
                <div class="fw-qi__modal-body">
                    <form id="paymentForm">
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Payment Date <span class="fw-qi__required">*</span></label>
                            <input type="date" name="payment_date" class="fw-qi__input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Amount (R) <span class="fw-qi__required">*</span></label>
                            <input type="number" name="amount" class="fw-qi__input" min="0.01" step="0.01" placeholder="0.00" required>
                            <small class="fw-qi__text-muted">Outstanding balance: R <?= number_format($invoice['balance_due'], 2) ?></small>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Method <span class="fw-qi__required">*</span></label>
                            <select name="method" class="fw-qi__input" required>
                                <option value="card">Card</option>
                                <option value="eft" selected>EFT</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="yoco">Yoco</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Reference</label>
                            <input type="text" name="reference" class="fw-qi__input" placeholder="Payment reference (optional)">
                        </div>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Notes</label>
                            <textarea name="notes" class="fw-qi__input" rows="3" placeholder="Additional notes (optional)"></textarea>
                        </div>
                    </form>
                </div>
                <div class="fw-qi__modal-footer">
                    <button type="button" class="fw-qi__btn fw-qi__btn--secondary" onclick="InvoiceView.closePaymentModal()">Cancel</button>
                    <button type="button" class="fw-qi__btn fw-qi__btn--primary" onclick="InvoiceView.recordPayment()">Record Payment</button>
                </div>
            </div>
        </div>
    </div>

    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <!-- Shared UI utilities -->
    <script src="/qi/assets/qi.ui.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/qi/assets/qi.invoice.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        // Initialize InvoiceView with necessary data
        InvoiceView.init({
            invoiceId: <?= (int)$invoiceId ?>,
            customerEmail: '<?= addslashes($invoice['customer_email'] ?? '') ?>',
            customerName: '<?= addslashes($invoice['customer_name'] ?? 'Customer') ?>',
            balanceDue: parseFloat('<?= (float)$invoice['balance_due'] ?>')
        });
    </script>
</body>
</html>