<?php
// /qi/invoice_view.php – Display a single invoice with actions
// This page closely mirrors quote_view.php but adapted for invoices.
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/Branding.php';
require_once __DIR__ . '/lib/Currencies.php';

define('ASSET_VERSION', QI_ASSET_VERSION);

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
        "SELECT i.*,\n               u.first_name AS creator_first_name,\n               u.last_name AS creator_last_name,\n               c.name AS company_name,\n               c.logo_url,\n               c.vat_number,\n               c.tax_number,\n               c.reg_number,\n               c.website,\n               c.phone AS company_phone,\n               c.email AS company_email,\n               c.address_line1 AS company_address1,\n               c.address_line2 AS company_address2,\n               c.city AS company_city,\n               c.region AS company_region,\n               c.postal AS company_postal,\n               c.bank_name,\n               c.bank_account_number,\n               c.bank_branch_code,\n               c.invoice_footer_text,\n               c.primary_color,\n               c.secondary_color,\n               c.qi_font_family,\n               c.qi_show_company_address,\n               c.qi_show_company_phone,\n               c.qi_show_company_email,\n               c.qi_show_company_website,\n               c.qi_show_vat_number,\n               c.qi_show_tax_number,\n               c.qi_show_reg_number,\n               c.qi_show_payment_details,\n               c.qi_heading_color,\n               c.qi_text_color,\n               c.qi_table_header_text,\n               c.qi_bg_color,\n               c.qi_border_radius,\n               c.qi_logo_size,\n               c.qi_logo_position,\n               c.qi_template,\n               c.qi_custom_css,\n               c.qi_invoice_title,\n               ca.name AS customer_name,\n               ca.email AS customer_email,\n               ca.phone AS customer_phone,\n               ca.vat_no AS customer_vat,\n               ca.reg_no AS customer_reg,\n               addr.line1 AS customer_address1,\n               addr.line2 AS customer_address2,\n               addr.city AS customer_city,\n               addr.region AS customer_region,\n               addr.postal_code AS customer_postal,\n               p.name AS project_name\n        FROM invoices i\n        LEFT JOIN users u ON i.created_by = u.id\n        LEFT JOIN companies c ON i.company_id = c.id\n        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id\n        LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)\n        LEFT JOIN projects p ON i.project_id = p.project_id\n        WHERE i.id = ? AND i.company_id = ?"
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

    // Fetch payment milestones
    $milestones = [];
    if (!empty($invoice['has_milestones'])) {
        $stmt = $DB->prepare("SELECT * FROM payment_milestones WHERE entity_type = 'invoice' AND entity_id = ? AND company_id = ? ORDER BY sort_order");
        $stmt->execute([$invoiceId, $companyId]);
        $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch the individual payments allocated to this invoice (payment history).
    // Each "Record Payment" / Yoco / refund entry shows here as its own line, so
    // multiple payments are never silently rolled into a single figure.
    $stmt = $DB->prepare(
        "SELECT p.payment_date, p.method, p.reference, pa.amount, p.created_at
         FROM payment_allocations pa
         JOIN payments p ON pa.payment_id = p.id
         WHERE pa.invoice_id = ? AND p.company_id = ?
         ORDER BY p.payment_date ASC, p.id ASC"
    );
    $stmt->execute([$invoiceId, $companyId]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $paymentsTotal = 0.0;
    foreach ($payments as $pmt) {
        $paymentsTotal += (float)$pmt['amount'];
    }

    // Current user name for greeting
    $stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $firstName = $userRow['first_name'] ?? 'User';

} catch (Exception $e) {
    error_log('Invoice view error: ' . $e->getMessage());
    header('Location: /qi/?tab=invoices&error=1');
    exit;
}

// Permissions
$canEdit    = ($invoice['status'] === 'draft');
$canSend    = in_array($invoice['status'], ['draft', 'sent']);
$canDelete  = ($invoice['status'] === 'draft');

$status     = $invoice['status'];
$isSoftDeleted = !empty($invoice['deleted_at']);
$isArchived    = !empty($invoice['archived_at']);
$isAdmin       = !empty($_SESSION['is_admin']) || (($_SESSION['role'] ?? '') === 'admin');

$canVoid              = in_array($status, ['sent','viewed','overdue','part-paid'], true);
$canRevertToDraft     = in_array($status, ['sent','viewed','overdue','cancelled'], true);
$canWriteOff          = in_array($status, ['sent','viewed','overdue','part-paid'], true);
$canMarkUncollectible = in_array($status, ['sent','viewed','overdue','part-paid'], true);
$canRefund            = in_array($status, ['paid','part-paid'], true);
$canIssueCredit       = !in_array($status, ['draft','cancelled','written_off'], true);
$canUnapplyPayments   = in_array($status, ['paid','part-paid','refunded'], true);
$canDuplicate         = true;
$canSoftDelete        = !$isSoftDeleted;
$canRestore           = $isSoftDeleted;
$canForceDelete       = $isAdmin;
$canArchive           = !$isArchived;
$canUnarchive         = $isArchived;

// Colour, text and font customisation — resolved centrally (qi/lib/Branding.php)
// so the on-screen invoice, the printable page and the downloaded PDF match.
$brand = Branding::resolve($invoice, 'invoice');
$docTitle     = htmlspecialchars($brand['title']);
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

// Document currency formatting
$docCurrency = Currencies::isValid($invoice['currency'] ?? null) ? strtoupper($invoice['currency']) : Currencies::BASE;
$docSymbol   = Currencies::symbol($docCurrency);
$docRate     = (float)($invoice['exchange_rate'] ?? 1) ?: 1.0;
$isForeign   = ($docCurrency !== Currencies::BASE);

// Helper to format amounts in the invoice's currency
$GLOBALS['qiDocSymbol'] = $docSymbol;
function format_currency($amount) {
    // Non-breaking space so the currency symbol never wraps onto its own line
    // when a column gets squeezed (e.g. long descriptions on mobile).
    return $GLOBALS['qiDocSymbol'] . "\u{00A0}" . number_format((float)$amount, 2);
}

// VAT summary label reflects the invoice's effective tax rate (which may be 0%
// or non-15% depending on the line items), instead of a hardcoded "15%".
function qi_vat_label($invoice) {
    $base = (float)($invoice['subtotal'] ?? 0) - (float)($invoice['discount'] ?? 0);
    $rate = $base > 0 ? ((float)($invoice['tax'] ?? 0) / $base) * 100 : 0.0;
    $rateStr = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    if ($rateStr === '' || $rateStr === '-0') { $rateStr = '0'; }
    return 'VAT (' . $rateStr . '%)';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars($invoice['invoice_number']) ?> – <?= htmlspecialchars($invoice['company_name']) ?></title>

    <?= Branding::fontHeadLinks() ?>


    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= ASSET_VERSION ?>">

    <style>
<?= Branding::documentStyle($brand) ?>

        /* Live "what's paid / left / %" breakdown inside the Record Payment modal */
        .fw-qi__payment-calc {
            margin-top: 4px;
            padding: 12px 14px;
            border: 1px solid var(--fw-border, #e5e7eb);
            border-radius: 10px;
            background: var(--fw-surface-2, #f9fafb);
        }
        .fw-qi[data-theme="dark"] .fw-qi__payment-calc {
            background: rgba(255,255,255,0.04);
            border-color: rgba(255,255,255,0.12);
        }
        .fw-qi__payment-calc-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            padding: 3px 0;
            color: var(--fw-text, #374151);
        }
        .fw-qi__payment-calc-row--strong {
            font-weight: 700;
            border-top: 1px solid var(--fw-border, #e5e7eb);
            margin-top: 4px;
            padding-top: 7px;
        }
        .fw-qi__payment-progress {
            height: 8px;
            border-radius: 999px;
            background: var(--fw-border, #e5e7eb);
            overflow: hidden;
            margin-top: 10px;
        }
        .fw-qi__payment-progress-bar {
            height: 100%;
            background: var(--accent-qi, #fbbf24);
            border-radius: 999px;
            transition: width .2s ease;
        }
        .fw-qi__payment-calc-pct {
            text-align: right;
            font-size: 12px;
            margin-top: 5px;
            font-weight: 600;
            color: var(--fw-text-muted, #6b7280);
        }

        /* The invoice document always renders the desktop layout at a fixed
           940px wide. On phones it sits inside .fw-qi__doc-viewport which
           CSS-zooms it down to fit the screen and lets the user pinch to
           zoom in/out (handled by the pinch script at the bottom of this
           page). The app shell (header, footer, modal) keeps the normal
           device-width viewport, so it stays usable. */
        .fw-qi__doc-viewport {
            width: 100%;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y pinch-zoom;
            background: #e5e7eb;
        }
        .fw-qi[data-theme="dark"] .fw-qi__doc-viewport { background: #111827; }
        .fw-qi__doc-viewport .fw-qi__document {
            width: 940px;
            max-width: none;
            margin: 0 auto;
            zoom: var(--qi-zoom, 1);
        }
        <?= $customCss ?>
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

                        <?php /* --- Status-change alternatives --- */ ?>
                        <?php if ($canVoid): ?>
                            <button onclick="InvoiceView.voidInvoice()" class="fw-qi__kebab-item" style="color:#b45309;">Void / Cancel Invoice</button>
                        <?php endif; ?>
                        <?php if ($canRevertToDraft): ?>
                            <button onclick="InvoiceView.revertToDraft()" class="fw-qi__kebab-item">Revert to Draft</button>
                        <?php endif; ?>
                        <?php if ($canWriteOff): ?>
                            <button onclick="InvoiceView.writeOffInvoice()" class="fw-qi__kebab-item">Write Off (Bad Debt)</button>
                        <?php endif; ?>
                        <?php if ($canMarkUncollectible): ?>
                            <button onclick="InvoiceView.markUncollectible()" class="fw-qi__kebab-item">Mark Uncollectible</button>
                        <?php endif; ?>

                        <?php /* --- Credit / refund --- */ ?>
                        <?php if ($canIssueCredit): ?>
                            <button onclick="InvoiceView.issueFullCredit()" class="fw-qi__kebab-item">Issue Full Credit Note</button>
                        <?php endif; ?>
                        <?php if ($canRefund): ?>
                            <button onclick="InvoiceView.refundInvoice()" class="fw-qi__kebab-item">Refund</button>
                        <?php endif; ?>
                        <?php if ($canUnapplyPayments): ?>
                            <button onclick="InvoiceView.unapplyPayments()" class="fw-qi__kebab-item">Unapply Payments</button>
                        <?php endif; ?>

                        <?php /* --- Archival / utility --- */ ?>
                        <?php if ($canDuplicate): ?>
                            <button onclick="InvoiceView.duplicateInvoice()" class="fw-qi__kebab-item">Duplicate as Draft</button>
                        <?php endif; ?>
                        <?php if ($canArchive): ?>
                            <button onclick="InvoiceView.archiveInvoice()" class="fw-qi__kebab-item">Archive Invoice</button>
                        <?php endif; ?>
                        <?php if ($canUnarchive): ?>
                            <button onclick="InvoiceView.unarchiveInvoice()" class="fw-qi__kebab-item">Unarchive Invoice</button>
                        <?php endif; ?>
                        <button onclick="InvoiceView.exportBeforeDelete()" class="fw-qi__kebab-item">Export PDF (backup)</button>
                        <?php if ($canSoftDelete): ?>
                            <button onclick="InvoiceView.softDeleteInvoice()" class="fw-qi__kebab-item" style="color:#ef4444;">Move to Trash (soft delete)</button>
                        <?php endif; ?>
                        <?php if ($canRestore): ?>
                            <button onclick="InvoiceView.restoreInvoice()" class="fw-qi__kebab-item">Restore from Trash</button>
                        <?php endif; ?>
                        <?php if ($canForceDelete): ?>
                            <button onclick="InvoiceView.forceDeleteInvoice()" class="fw-qi__kebab-item" style="color:#ef4444;font-weight:600;">Force Delete (Admin)</button>
                        <?php endif; ?>

                        <hr style="margin:8px 0;border:none;border-top:1px solid var(--fw-border);">
                        <button onclick="InvoiceView.printInvoice()" class="fw-qi__kebab-item">
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
            <div class="fw-qi__doc-viewport" id="docViewport">
            <div class="fw-qi__document" data-template="<?= $template ?>">
                <!-- Document Header -->
                <div class="fw-qi__doc-header">
                    <div class="fw-qi__doc-header-left<?= $logoPosition !== 'left' ? ' fw-qi__doc-header-left--' . $logoPosition : '' ?>">
                        <?php if ($invoice['logo_url']): ?>
                            <img src="<?= htmlspecialchars($invoice['logo_url']) ?>" alt="Logo" class="fw-qi__doc-logo">
                        <?php endif; ?>
                        <div class="fw-qi__doc-company">
                            <h1><?= htmlspecialchars($invoice['company_name']) ?></h1>
                            <?php if ($showAddress): ?>
                                <?php if ($invoice['company_address1']): ?><p><?= htmlspecialchars($invoice['company_address1']) ?></p><?php endif; ?>
                                <?php if ($invoice['company_address2']): ?><p><?= htmlspecialchars($invoice['company_address2']) ?></p><?php endif; ?>
                                <?php if ($invoice['company_city']): ?><p><?= htmlspecialchars($invoice['company_city']) ?>, <?= htmlspecialchars($invoice['company_postal']) ?></p><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($showReg && $invoice['reg_number']): ?><p><strong>Reg No:</strong> <?= htmlspecialchars($invoice['reg_number']) ?></p><?php endif; ?>
                            <?php if ($showTax && $invoice['tax_number']): ?><p><strong>Tax:</strong> <?= htmlspecialchars($invoice['tax_number']) ?></p><?php endif; ?>
                            <?php if ($showVat && $invoice['vat_number']): ?><p><strong>VAT No:</strong> <?= htmlspecialchars($invoice['vat_number']) ?></p><?php endif; ?>
                            <?php if ($showPhone && $invoice['company_phone']): ?><p><?= htmlspecialchars($invoice['company_phone']) ?></p><?php endif; ?>
                            <?php if ($showEmail && $invoice['company_email']): ?><p><?= htmlspecialchars($invoice['company_email']) ?></p><?php endif; ?>
                            <?php if ($showWebsite && $invoice['website']): ?><p><?= htmlspecialchars($invoice['website']) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <div class="fw-qi__doc-header-right">
                        <h2 class="fw-qi__doc-title"><?= $docTitle ?></h2>
                        <div class="fw-qi__doc-number"><?= htmlspecialchars($invoice['invoice_number']) ?></div>
                        <table class="fw-qi__doc-info-table" style="margin-top:8px;">
                            <tr><td>Issue Date:</td><td><strong><?= date('d M Y', strtotime($invoice['issue_date'])) ?></strong></td></tr>
                            <tr><td>Due Date:</td><td><strong><?= date('d M Y', strtotime($invoice['due_date'])) ?></strong></td></tr>
                            <?php if ($isForeign): ?>
                                <tr><td>Currency:</td><td><strong><?= htmlspecialchars($docCurrency) ?> (<?= htmlspecialchars(Currencies::name($docCurrency)) ?>)</strong></td></tr>
                            <?php endif; ?>
                        </table>
                        <span class="fw-qi__badge fw-qi__badge--<?= htmlspecialchars($invoice['status']) ?>"><?= strtoupper(str_replace('_', ' ', $invoice['status'])) ?></span>

                        <div class="fw-qi__doc-bill-to" style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(0,0,0,0.08);">
                            <h3 style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--qi-heading-color);margin:0 0 8px 0;">Bill To</h3>
                            <p style="margin:4px 0;"><strong><?= htmlspecialchars($invoice['customer_name'] ?? 'Customer') ?></strong></p>
                            <?php if (!empty($invoice['customer_address1'])): ?>
                                <p style="margin:3px 0;"><?= htmlspecialchars($invoice['customer_address1']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($invoice['customer_address2'])): ?>
                                <p style="margin:3px 0;"><?= htmlspecialchars($invoice['customer_address2']) ?></p>
                            <?php endif; ?>
                            <?php
                                $custCityParts = array_filter([
                                    trim($invoice['customer_city'] ?? ''),
                                    trim(($invoice['customer_region'] ?? '') . ' ' . ($invoice['customer_postal'] ?? '')),
                                ], fn($p) => $p !== '');
                                $custCity = implode(', ', $custCityParts);
                            ?>
                            <?php if ($custCity !== ''): ?>
                                <p style="margin:3px 0;"><?= htmlspecialchars($custCity) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($invoice['customer_phone'])): ?>
                                <p style="margin:3px 0;">Tel: <?= htmlspecialchars($invoice['customer_phone']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($invoice['customer_email'])): ?>
                                <p style="margin:3px 0;">Email: <?= htmlspecialchars($invoice['customer_email']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($invoice['customer_vat'])): ?>
                                <p style="margin:3px 0;">VAT No: <?= htmlspecialchars($invoice['customer_vat']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($invoice['customer_reg'])): ?>
                                <p style="margin:3px 0;">Reg No: <?= htmlspecialchars($invoice['customer_reg']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($invoice['project_name']): ?>
                <div class="fw-qi__classic-project">
                    <h2><?= htmlspecialchars($invoice['project_name']) ?></h2>
                </div>
                <?php endif; ?>

                <!-- Line Items Table -->
                <div class="fw-qi__doc-table-wrap">
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
                </div>

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
                        <span><?= qi_vat_label($invoice) ?>:</span>
                        <span><?= format_currency($invoice['tax']) ?></span>
                    </div>
                    <div class="fw-qi__doc-total-row fw-qi__doc-total-row--grand">
                        <span>TOTAL:</span>
                        <span><?= format_currency($invoice['total']) ?></span>
                    </div>
                    <?php if ($isForeign): ?>
                        <div class="fw-qi__doc-total-row" style="font-size:12px;color:#6b7280;">
                            <span>ZAR equivalent (1 <?= htmlspecialchars($docCurrency) ?> = <?= number_format($docRate, 4) ?> ZAR):</span>
                            <span>R&nbsp;<?= number_format((float)$invoice['total'] * $docRate, 2) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ((float)$invoice['balance_due'] < (float)$invoice['total']): ?>
                        <div class="fw-qi__doc-total-row" style="margin-top:8px;">
                            <span>Balance Due:</span>
                            <span><?= format_currency($invoice['balance_due']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Milestones -->
                <?php if (!empty($milestones)): ?>
                    <div class="fw-qi__doc-section fw-qi__milestones-view">
                        <h3>Payment Schedule</h3>
                        <?php
                            $schedPaid    = max(0, (float)$invoice['total'] - (float)$invoice['balance_due']);
                            $schedPaidPct = (float)$invoice['total'] > 0 ? ($schedPaid / (float)$invoice['total']) * 100 : 0;
                        ?>
                        <p style="margin:-4px 0 12px;font-size:13px;color:#6b7280;">
                            Paid <?= format_currency($schedPaid) ?> of <?= format_currency($invoice['total']) ?>
                            (<?= number_format($schedPaidPct, 1) ?>%) — Outstanding <?= format_currency($invoice['balance_due']) ?>
                        </p>
                        <div class="fw-qi__doc-table-wrap">
                        <table class="fw-qi__doc-table fw-qi__milestones-table">
                            <thead>
                                <tr>
                                    <th>Phase</th>
                                    <th style="text-align:right;">%</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Due Date</th>
                                    <th style="text-align:right;">Paid</th>
                                    <th style="text-align:right;">Outstanding</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    // Determine which milestone is "now payable" (first unpaid)
                                    $nowPayableId = null;
                                    foreach ($milestones as $ms) {
                                        if ($ms['status'] !== 'paid') {
                                            $nowPayableId = $ms['id'];
                                            break;
                                        }
                                    }
                                ?>
                                <?php foreach ($milestones as $ms): ?>
                                    <?php
                                        $isNowPayable = ($nowPayableId !== null && $ms['id'] == $nowPayableId);
                                        if ($ms['status'] === 'paid') {
                                            $msStatusClass = 'paid';
                                            $msStatusLabel = 'Paid';
                                        } elseif ($ms['status'] === 'overdue') {
                                            $msStatusClass = 'overdue';
                                            $msStatusLabel = 'Overdue';
                                        } elseif ($isNowPayable) {
                                            $msStatusClass = 'now-payable';
                                            $msStatusLabel = 'Now Payable';
                                        } else {
                                            $msStatusClass = 'upcoming';
                                            $msStatusLabel = 'Upcoming';
                                        }
                                    ?>
                                    <?php $msOutstanding = max(0, (float)$ms['amount'] - (float)$ms['amount_paid']); ?>
                                    <tr class="<?= $isNowPayable ? 'fw-qi__milestone-row--active' : '' ?>">
                                        <td><?= htmlspecialchars($ms['label']) ?></td>
                                        <td style="text-align:right;"><?= number_format($ms['percentage'], 1) ?>%</td>
                                        <td style="text-align:right;"><?= format_currency($ms['amount']) ?></td>
                                        <td><?= $ms['due_date'] ? date('d M Y', strtotime($ms['due_date'])) : '—' ?></td>
                                        <td style="text-align:right;"><?= format_currency($ms['amount_paid']) ?></td>
                                        <td style="text-align:right;"><?= format_currency($msOutstanding) ?></td>
                                        <td><span class="fw-qi__milestone-badge fw-qi__milestone-badge--<?= $msStatusClass ?>"><?= $msStatusLabel ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Payments Received (individual payment history) -->
                <?php if (!empty($payments)): ?>
                    <div class="fw-qi__doc-section fw-qi__payments-view">
                        <h3>Payments Received</h3>
                        <div class="fw-qi__doc-table-wrap">
                        <table class="fw-qi__doc-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th style="text-align:right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $pmt): ?>
                                    <tr>
                                        <td><?= $pmt['payment_date'] ? date('d M Y', strtotime($pmt['payment_date'])) : '—' ?></td>
                                        <td><?= htmlspecialchars(ucfirst($pmt['method'])) ?></td>
                                        <td><?= htmlspecialchars($pmt['reference'] !== '' && $pmt['reference'] !== null ? $pmt['reference'] : '—') ?></td>
                                        <td style="text-align:right;"><?= format_currency($pmt['amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="text-align:right;font-weight:700;">Total received</td>
                                    <td style="text-align:right;font-weight:700;"><?= format_currency($paymentsTotal) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="3" style="text-align:right;">Outstanding</td>
                                    <td style="text-align:right;"><?= format_currency($invoice['balance_due']) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Payment & Bank Details -->
                <?php if ($showPayment && ($invoice['bank_name'] || $invoice['bank_account_number'])): ?>
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
            </div><!-- /.fw-qi__doc-viewport -->
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
                        <?php if (!empty($milestones)): ?>
                        <p class="fw-qi__text-muted" style="margin:-4px 0 14px;font-size:12px;">
                            This payment is applied to your payment schedule automatically — the oldest unpaid phase first.
                        </p>
                        <?php endif; ?>
                        <div class="fw-qi__form-group">
                            <label class="fw-qi__label">Amount (<?= htmlspecialchars($docSymbol) ?>) <span class="fw-qi__required">*</span></label>
                            <input type="number" name="amount" class="fw-qi__input" min="0.01" step="0.01" placeholder="0.00" required oninput="InvoiceView.updatePaymentCalc()">
                            <?php if ($isForeign): ?>
                                <p class="fw-qi__text-muted" style="margin:6px 0 0;font-size:12px;">Enter the amount in <?= htmlspecialchars($docCurrency) ?> — the invoice currency.</p>
                            <?php endif; ?>
                        </div>
                        <?php
                            $invTotal   = (float)$invoice['total'];
                            $invPaid    = max(0, $invTotal - (float)$invoice['balance_due']);
                            $invPaidPct = $invTotal > 0 ? ($invPaid / $invTotal) * 100 : 0;
                        ?>
                        <div class="fw-qi__payment-calc" id="paymentCalc">
                            <div class="fw-qi__payment-calc-row"><span>Invoice total</span><span><?= format_currency($invTotal) ?></span></div>
                            <div class="fw-qi__payment-calc-row"><span>Already paid</span><span id="calcPaid"><?= format_currency($invPaid) ?></span></div>
                            <div class="fw-qi__payment-calc-row"><span>This payment</span><span id="calcThis"><?= htmlspecialchars($docSymbol) ?> 0.00</span></div>
                            <div class="fw-qi__payment-calc-row fw-qi__payment-calc-row--strong"><span>Outstanding after</span><span id="calcRemaining"><?= format_currency($invoice['balance_due']) ?></span></div>
                            <div class="fw-qi__payment-progress"><div class="fw-qi__payment-progress-bar" id="calcBar" style="width:<?= round(min(100, max(0, $invPaidPct)), 1) ?>%;"></div></div>
                            <div class="fw-qi__payment-calc-pct" id="calcPct"><?= number_format($invPaidPct, 1) ?>% paid</div>
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
            customerEmail: <?= json_encode($invoice['customer_email'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            customerName: <?= json_encode($invoice['customer_name'] ?? 'Customer', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            balanceDue: parseFloat('<?= (float)$invoice['balance_due'] ?>'),
            invoiceTotal: parseFloat('<?= (float)$invoice['total'] ?>'),
            currencySymbol: <?= json_encode($docSymbol) ?>,
            hasMilestones: <?= !empty($milestones) ? 'true' : 'false' ?>
        });
    </script>
    <script>
        // Pinch-to-zoom for the invoice document only (app shell unaffected).
        // The document is fixed at 940px wide and CSS-zoomed via --qi-zoom.
        // Initial zoom fits it to the viewport on phones; two-finger pinch
        // updates the zoom variable live.
        (function () {
            const viewport = document.getElementById('docViewport');
            if (!viewport) return;
            const doc = viewport.querySelector('.fw-qi__document');
            if (!doc) return;

            const DOC_WIDTH = 940;
            const MIN_ZOOM = 0.25;
            const MAX_ZOOM = 3;

            function clamp(z) { return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, z)); }
            function getZoom() {
                return parseFloat(doc.style.getPropertyValue('--qi-zoom')) || 1;
            }
            function setZoom(z) {
                doc.style.setProperty('--qi-zoom', clamp(z));
            }

            // Fit-to-screen on initial load (and only when the viewport is
            // narrower than the document — desktop stays at 1:1).
            function fitToScreen() {
                const w = viewport.clientWidth;
                if (w > 0 && w < DOC_WIDTH) {
                    setZoom(w / DOC_WIDTH);
                } else {
                    setZoom(1);
                }
            }
            fitToScreen();
            window.addEventListener('orientationchange', fitToScreen);

            // Two-finger pinch handling
            let pinchStartDist = 0;
            let pinchStartZoom = 1;

            function distance(t) {
                const dx = t[0].clientX - t[1].clientX;
                const dy = t[0].clientY - t[1].clientY;
                return Math.hypot(dx, dy);
            }

            viewport.addEventListener('touchstart', function (e) {
                if (e.touches.length === 2) {
                    pinchStartDist = distance(e.touches);
                    pinchStartZoom = getZoom();
                    e.preventDefault();
                }
            }, { passive: false });

            viewport.addEventListener('touchmove', function (e) {
                if (e.touches.length === 2 && pinchStartDist > 0) {
                    const d = distance(e.touches);
                    setZoom(pinchStartZoom * (d / pinchStartDist));
                    e.preventDefault();
                }
            }, { passive: false });

            viewport.addEventListener('touchend', function (e) {
                if (e.touches.length < 2) {
                    pinchStartDist = 0;
                }
            });
        })();
    </script>
</body>
</html>