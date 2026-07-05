<?php
// /qi/credit_note_view.php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/Branding.php';
require_once __DIR__ . '/lib/Currencies.php';

define('ASSET_VERSION', QI_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$creditNoteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$creditNoteId) {
    header('Location: /qi/?tab=credit_notes');
    exit;
}

try {
    // Fetch credit note with company branding, customer and linked invoice
    $stmt = $DB->prepare("
        SELECT cn.*,
               u.first_name AS creator_first_name,
               u.last_name AS creator_last_name,
               c.name AS company_name,
               c.logo_url,
               c.vat_number,
               c.tax_number,
               c.reg_number,
               c.website,
               c.phone AS company_phone,
               c.email AS company_email,
               c.address_line1 AS company_address1,
               c.address_line2 AS company_address2,
               c.city AS company_city,
               c.region AS company_region,
               c.postal AS company_postal,
               c.bank_name,
               c.bank_account_number,
               c.bank_branch_code,
               c.invoice_footer_text,
               c.primary_color,
               c.secondary_color,
               c.qi_font_family,
               c.qi_show_company_address,
               c.qi_show_company_phone,
               c.qi_show_company_email,
               c.qi_show_company_website,
               c.qi_show_vat_number,
               c.qi_show_tax_number,
               c.qi_show_reg_number,
               c.qi_show_payment_details,
               c.qi_heading_color,
               c.qi_text_color,
               c.qi_table_header_text,
               c.qi_bg_color,
               c.qi_border_radius,
               c.qi_logo_size,
               c.qi_logo_position,
               c.qi_template,
               c.qi_custom_css,
               ca.name AS customer_name,
               ca.email AS customer_email,
               ca.phone AS customer_phone,
               ca.vat_no AS customer_vat,
               ca.reg_no AS customer_reg,
               addr.line1 AS customer_address1,
               addr.line2 AS customer_address2,
               addr.city AS customer_city,
               addr.region AS customer_region,
               addr.postal_code AS customer_postal,
               i.invoice_number AS linked_invoice_number,
               i.total AS invoice_total
        FROM credit_notes cn
        LEFT JOIN users u ON cn.created_by = u.id
        LEFT JOIN companies c ON cn.company_id = c.id
        LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id
        LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
        LEFT JOIN invoices i ON cn.invoice_id = i.id
        WHERE cn.id = ? AND cn.company_id = ?
    ");
    $stmt->execute([$creditNoteId, $companyId]);
    $creditNote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$creditNote) {
        header('Location: /qi/?tab=credit_notes');
        exit;
    }

    // Fetch line items
    $stmt = $DB->prepare("SELECT * FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
    $stmt->execute([$creditNoteId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current user name
    $stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $firstName = $userRow['first_name'] ?? 'User';

} catch (Exception $e) {
    error_log('Credit note view error: ' . $e->getMessage());
    header('Location: /qi/?tab=credit_notes&error=1');
    exit;
}

$companyName = $creditNote['company_name'] ?? 'Company';
$canApply = ($creditNote['status'] === 'approved' && $creditNote['invoice_id']);

// Colour, text and font customisation — resolved centrally (qi/lib/Branding.php).
$brand = Branding::resolve($creditNote, 'credit_note');
$template     = $brand['template'];
$logoPosition = $brand['logo_position'];
$customCss    = Branding::customCss($brand);

$showAddress = $brand['show']['address'];
$showPhone   = $brand['show']['phone'];
$showEmail   = $brand['show']['email'];
$showWebsite = $brand['show']['website'];
$showVat     = $brand['show']['vat'];
$showTax     = $brand['show']['tax'];
$showReg     = $brand['show']['reg'];
$showPayment = $brand['show']['payment'];

// Document currency formatting (credit notes inherit the linked invoice's currency)
$docCurrency = Currencies::isValid($creditNote['currency'] ?? null) ? strtoupper($creditNote['currency']) : Currencies::BASE;
$docSymbol   = Currencies::symbol($docCurrency);
$docRate     = (float)($creditNote['exchange_rate'] ?? 1) ?: 1.0;
$isForeign   = ($docCurrency !== Currencies::BASE);

$GLOBALS['qiDocSymbol'] = $docSymbol;
function format_currency($amount) {
    return $GLOBALS['qiDocSymbol'] . ' ' . number_format((float)$amount, 2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars($creditNote['credit_note_number']) ?> – <?= htmlspecialchars($companyName) ?></title>

    <?= Branding::fontHeadLinks() ?>


    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= ASSET_VERSION ?>">

    <style>
<?= Branding::documentStyle($brand) ?>
        <?= $customCss ?>
    </style>
</head>
<body class="fw-qi">
    <div class="fw-qi__container">
        <header class="fw-qi__header">
            <div class="fw-qi__brand">
                <div class="fw-qi__logo-tile">
                    <?php if ($creditNote['logo_url']): ?>
                        <img src="<?= htmlspecialchars($creditNote['logo_url']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/></svg>
                    <?php endif; ?>
                </div>
                <div class="fw-qi__brand-text">
                    <div class="fw-qi__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-qi__app-name"><?= htmlspecialchars($creditNote['credit_note_number']) ?></div>
                </div>
            </div>
            <div class="fw-qi__greeting">Hello, <span class="fw-qi__greeting-name"><?= htmlspecialchars($firstName) ?></span></div>
            <div class="fw-qi__controls">
                <a href="/qi/?tab=credit_notes" class="fw-qi__home-btn" title="Back"><svg viewBox="0 0 24 24" fill="none"><path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></a>
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
                        <?php if ($creditNote['status'] === 'draft'): ?>
                            <button onclick="CNView.approve()" class="fw-qi__kebab-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                    <path d="M9 11l3 3L22 4"/>
                                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                                </svg>
                                Approve
                            </button>
                        <?php endif; ?>
                        <?php if ($canApply): ?>
                            <button onclick="CNView.applyToInvoice()" class="fw-qi__kebab-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h8"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <path d="M16 16l5 5m0 0l-5-5m5 5H14"/>
                                </svg>
                                Apply to Invoice
                            </button>
                        <?php endif; ?>

                        <hr style="margin:8px 0;border:none;border-top:1px solid var(--fw-border);">
                        <button onclick="CNView.printCreditNote()" class="fw-qi__kebab-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                <polyline points="6 9 6 2 18 2 18 9"/>
                                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                <rect x="6" y="14" width="12" height="8"/>
                            </svg>
                            Print
                        </button>
                        <button onclick="CNView.downloadPDF()" class="fw-qi__kebab-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:8px;">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Download PDF
                        </button>
                    </nav>
                </div>
            </div>
        </header>

        <main class="fw-qi__main">
            <div class="fw-qi__document" data-template="<?= $template ?>">
                <!-- Document Header -->
                <div class="fw-qi__doc-header">
                    <div class="fw-qi__doc-header-left<?= $logoPosition !== 'left' ? ' fw-qi__doc-header-left--' . $logoPosition : '' ?>">
                        <?php if ($creditNote['logo_url']): ?>
                            <img src="<?= htmlspecialchars($creditNote['logo_url']) ?>" alt="Logo" class="fw-qi__doc-logo">
                        <?php endif; ?>
                        <div class="fw-qi__doc-company">
                            <h1><?= htmlspecialchars($companyName) ?></h1>
                            <?php if ($showAddress): ?>
                                <?php if (!empty($creditNote['company_address1'])): ?><p><?= htmlspecialchars($creditNote['company_address1']) ?></p><?php endif; ?>
                                <?php if (!empty($creditNote['company_address2'])): ?><p><?= htmlspecialchars($creditNote['company_address2']) ?></p><?php endif; ?>
                                <?php if (!empty($creditNote['company_city'])): ?><p><?= htmlspecialchars($creditNote['company_city']) ?>, <?= htmlspecialchars($creditNote['company_postal'] ?? '') ?></p><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($showReg && !empty($creditNote['reg_number'])): ?><p><strong>Reg No:</strong> <?= htmlspecialchars($creditNote['reg_number']) ?></p><?php endif; ?>
                            <?php if ($showTax && !empty($creditNote['tax_number'])): ?><p><strong>Tax:</strong> <?= htmlspecialchars($creditNote['tax_number']) ?></p><?php endif; ?>
                            <?php if ($showVat && !empty($creditNote['vat_number'])): ?><p><strong>VAT No:</strong> <?= htmlspecialchars($creditNote['vat_number']) ?></p><?php endif; ?>
                            <?php if ($showPhone && !empty($creditNote['company_phone'])): ?><p><?= htmlspecialchars($creditNote['company_phone']) ?></p><?php endif; ?>
                            <?php if ($showEmail && !empty($creditNote['company_email'])): ?><p><?= htmlspecialchars($creditNote['company_email']) ?></p><?php endif; ?>
                            <?php if ($showWebsite && !empty($creditNote['website'])): ?><p><?= htmlspecialchars($creditNote['website']) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <div class="fw-qi__doc-header-right">
                        <h2 class="fw-qi__doc-title">CREDIT NOTE</h2>
                        <div class="fw-qi__doc-number"><?= htmlspecialchars($creditNote['credit_note_number']) ?></div>
                        <table class="fw-qi__doc-info-table" style="margin-top:8px;">
                            <tr><td>Issue Date:</td><td><strong><?= date('d M Y', strtotime($creditNote['issue_date'])) ?></strong></td></tr>
                            <?php if (!empty($creditNote['linked_invoice_number'])): ?>
                                <tr><td>Invoice:</td><td><strong><?= htmlspecialchars($creditNote['linked_invoice_number']) ?></strong></td></tr>
                            <?php endif; ?>
                        </table>
                        <span class="fw-qi__badge fw-qi__badge--<?= htmlspecialchars($creditNote['status']) ?>"><?= strtoupper(str_replace('_', ' ', $creditNote['status'])) ?></span>

                        <div class="fw-qi__doc-bill-to" style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(0,0,0,0.08);">
                            <h3 style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--qi-heading-color);margin:0 0 8px 0;">Credit To</h3>
                            <p style="margin:4px 0;"><strong><?= htmlspecialchars($creditNote['customer_name'] ?? 'Customer') ?></strong></p>
                            <?php if (!empty($creditNote['customer_address1'])): ?>
                                <p style="margin:3px 0;"><?= htmlspecialchars($creditNote['customer_address1']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($creditNote['customer_address2'])): ?>
                                <p style="margin:3px 0;"><?= htmlspecialchars($creditNote['customer_address2']) ?></p>
                            <?php endif; ?>
                            <?php $custCity = implode(', ', array_filter([trim($creditNote['customer_city'] ?? ''), trim(($creditNote['customer_region'] ?? '') . ' ' . ($creditNote['customer_postal'] ?? ''))], fn($p) => $p !== '')); ?>
                            <?php if ($custCity !== ''): ?>
                                <p style="margin:3px 0;"><?= htmlspecialchars($custCity) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($creditNote['customer_phone'])): ?>
                                <p style="margin:3px 0;">Tel: <?= htmlspecialchars($creditNote['customer_phone']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($creditNote['customer_email'])): ?>
                                <p style="margin:3px 0;">Email: <?= htmlspecialchars($creditNote['customer_email']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($creditNote['customer_vat'])): ?>
                                <p style="margin:3px 0;">VAT No: <?= htmlspecialchars($creditNote['customer_vat']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($creditNote['customer_reg'])): ?>
                                <p style="margin:3px 0;">Reg No: <?= htmlspecialchars($creditNote['customer_reg']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <?php if (!empty($creditNote['reason'])): ?>
                <div class="fw-qi__doc-details">
                    <div class="fw-qi__doc-detail-box">
                        <h3>Reason</h3>
                        <p><?= nl2br(htmlspecialchars($creditNote['reason'])) ?></p>
                    </div>
                </div>
                <?php endif; ?>

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
                        <span><?= format_currency($creditNote['subtotal']) ?></span>
                    </div>
                    <?php if ((float)($creditNote['discount'] ?? 0) > 0): ?>
                        <div class="fw-qi__doc-total-row">
                            <span>Discount:</span>
                            <span><?= format_currency($creditNote['discount']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="fw-qi__doc-total-row">
                        <span>VAT (15%):</span>
                        <span><?= format_currency($creditNote['tax']) ?></span>
                    </div>
                    <div class="fw-qi__doc-total-row fw-qi__doc-total-row--grand">
                        <span>TOTAL CREDIT:</span>
                        <span><?= format_currency($creditNote['total']) ?></span>
                    </div>
                    <?php if ($isForeign): ?>
                        <div class="fw-qi__doc-total-row" style="font-size:12px;color:#6b7280;">
                            <span>ZAR equivalent (1 <?= htmlspecialchars($docCurrency) ?> = <?= number_format($docRate, 4) ?> ZAR):</span>
                            <span>R <?= number_format((float)$creditNote['total'] * $docRate, 2) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Reason (if not shown above) -->
                <?php if (!empty($creditNote['reason']) && empty($creditNote['customer_email']) && empty($creditNote['customer_phone'])): ?>
                <?php endif; ?>

                <!-- Terms -->
                <?php if (!empty($creditNote['terms'])): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Terms & Conditions</h3>
                        <p><?= nl2br(htmlspecialchars($creditNote['terms'])) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Notes -->
                <?php if (!empty($creditNote['notes'])): ?>
                    <div class="fw-qi__doc-section">
                        <h3>Notes</h3>
                        <p><?= nl2br(htmlspecialchars($creditNote['notes'])) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Footer Text -->
                <?php if (!empty($creditNote['invoice_footer_text'])): ?>
                    <div class="fw-qi__doc-footer">
                        <p><?= nl2br(htmlspecialchars($creditNote['invoice_footer_text'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="fw-qi__footer">
            <span>Q&I v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>

    <script src="/qi/assets/qi.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/qi/assets/qi.ui.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
        window.CNView = {
            creditNoteId: <?= (int)$creditNoteId ?>,

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
                // Stream the real PDF (download_pdf.php sends Content-Disposition:
                // attachment) — works in desktop browsers, mobile browsers, and
                // the Android WebView. Avoids window.open which is dropped by
                // the default Android WebChromeClient.
                UI.downloadFile('/qi/ajax/download_pdf.php?type=credit_note&id=' + this.creditNoteId);
            },

            printCreditNote() {
                const w = window.open('/qi/ajax/generate_pdf.php?type=credit_note&id=' + this.creditNoteId, '_blank');
                if (!w) {
                    UI.downloadFile('/qi/ajax/download_pdf.php?type=credit_note&id=' + this.creditNoteId);
                }
            }
        };
    </script>
</body>
</html>
