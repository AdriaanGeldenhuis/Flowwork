<?php
// /qi/ajax/generate_pdf.php – Generate a print-ready invoice/quote/credit note page.
// Uses the SAME templates-pro.css and HTML structure as invoice_view.php / quote_view.php
// so the PDF always looks identical to the app.
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Branding.php';
require_once __DIR__ . '/../lib/Currencies.php';

$companyId = $_SESSION['company_id'];
$type      = $_GET['type'] ?? 'quote';
$id        = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}

define('ASSET_VERSION', '2026-06-10-QI-currency-v1');

// Formats amounts in the document's currency; the symbol is set after the
// document is fetched (defaults to ZAR's "R").
$GLOBALS['qiPdfSymbol'] = 'R';
function fmt($amount) {
    return $GLOBALS['qiPdfSymbol'] . ' ' . number_format((float)$amount, 2);
}

try {
    if ($type === 'quote') {
        $stmt = $DB->prepare(
            "SELECT q.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                    c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number, c.qi_show_reg_number,
                    c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                    c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                    c.qi_table_header_text, c.qi_bg_color, c.qi_border_radius, c.qi_logo_size,
                    c.qi_logo_position, c.qi_template, c.qi_font_family, c.qi_custom_css,
                    c.invoice_footer_text, c.quote_footer_text,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                    addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                    addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                    p.name AS project_name
             FROM quotes q
             LEFT JOIN companies c ON q.company_id = c.id
             LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
             LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
             LEFT JOIN projects p ON q.project_id = p.project_id
             WHERE q.id = ? AND q.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Quote not found'); }

        $docType = $doc['qi_quote_title'] ?? 'QUOTE';
        $docNumber = $doc['quote_number'];
        $docTitle = 'Quote #: ' . $docNumber;
        $dates = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Expires' => date('d M Y', strtotime($doc['expiry_date'])),
        ];

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $milestones = [];
        $payments = [];

    } elseif ($type === 'credit_note') {
        $stmt = $DB->prepare(
            "SELECT cn.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                    c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number, c.qi_show_reg_number,
                    c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                    c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                    c.qi_table_header_text, c.qi_bg_color, c.qi_border_radius, c.qi_logo_size,
                    c.qi_logo_position, c.qi_template, c.qi_font_family, c.qi_custom_css,
                    c.invoice_footer_text, c.quote_footer_text,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                    addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                    addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                    i.invoice_number AS linked_invoice_number
             FROM credit_notes cn
             LEFT JOIN companies c ON cn.company_id = c.id
             LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id
             LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
             LEFT JOIN invoices i ON cn.invoice_id = i.id
             WHERE cn.id = ? AND cn.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Credit note not found'); }

        $doc['project_name'] = null;
        $docType = 'CREDIT NOTE';
        $docNumber = $doc['credit_note_number'];
        $docTitle = 'Credit Note #: ' . $docNumber;
        $dates = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
        ];
        if (!empty($doc['linked_invoice_number'])) {
            $dates['Invoice'] = $doc['linked_invoice_number'];
        }

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $milestones = [];
        $payments = [];

    } else {
        // Invoice
        $stmt = $DB->prepare(
            "SELECT i.*,
                    c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                    c.website, c.phone AS company_phone, c.email AS company_email,
                    c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                    c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    c.bank_name, c.bank_account_number, c.bank_branch_code,
                    c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                    c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number, c.qi_show_reg_number,
                    c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                    c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                    c.qi_table_header_text, c.qi_bg_color, c.qi_border_radius, c.qi_logo_size,
                    c.qi_logo_position, c.qi_template, c.qi_font_family, c.qi_custom_css,
                    c.invoice_footer_text, c.quote_footer_text,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                    addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                    addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                    p.name AS project_name
             FROM invoices i
             LEFT JOIN companies c ON i.company_id = c.id
             LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
             LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
             LEFT JOIN projects p ON i.project_id = p.project_id
             WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Invoice not found'); }

        $docType = $doc['qi_invoice_title'] ?? 'INVOICE';
        $docNumber = $doc['invoice_number'];
        $docTitle = 'Invoice #: ' . $docNumber;
        $dates = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Due Date' => date('d M Y', strtotime($doc['due_date'])),
        ];

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch payment milestones
        $milestones = [];
        if (!empty($doc['has_milestones'])) {
            $stmt = $DB->prepare("SELECT * FROM payment_milestones WHERE entity_type = 'invoice' AND entity_id = ? AND company_id = ? ORDER BY sort_order");
            $stmt->execute([$id, $companyId]);
            $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch the individual payments allocated to this invoice — same query
        // as invoice_view.php so the printed payment history matches the app.
        $stmt = $DB->prepare(
            "SELECT p.payment_date, p.method, p.reference, pa.amount, p.created_at
             FROM payment_allocations pa
             JOIN payments p ON pa.payment_id = p.id
             WHERE pa.invoice_id = ? AND p.company_id = ?
             ORDER BY p.payment_date ASC, p.id ASC"
        );
        $stmt->execute([$id, $companyId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $paymentsTotal = 0.0;
    foreach ($payments as $pmt) {
        $paymentsTotal += (float)$pmt['amount'];
    }

    // Document currency: symbol used by fmt(), plus a Currency line for foreign docs
    $docCurrency = Currencies::isValid($doc['currency'] ?? null) ? strtoupper($doc['currency']) : Currencies::BASE;
    $docFxRate   = (float)($doc['exchange_rate'] ?? 1) ?: 1.0;
    $isForeign   = ($docCurrency !== Currencies::BASE);
    $GLOBALS['qiPdfSymbol'] = Currencies::symbol($docCurrency);
    if ($isForeign) {
        $dates['Currency'] = $docCurrency . ' (' . Currencies::name($docCurrency) . ')';
    }

    // Colour, text and font customisation — resolved centrally (qi/lib/Branding.php)
    // so this printable page matches the on-screen document exactly.
    $brand = Branding::resolve($doc, $type);
    $docType      = $brand['title'];
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

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($docNumber) ?></title>

<?= Branding::fontHeadLinks() ?>

<!-- Use the SAME stylesheets as the app (qi.css carries the global reset,
     status badges and milestone badges; templates-pro.css the document) -->
<link rel="stylesheet" href="/qi/assets/qi.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= ASSET_VERSION ?>">

<style>
    /* Branding variables — resolved centrally (qi/lib/Branding.php) */
<?= Branding::documentStyle($brand) ?>
    <?= $customCss ?>

    /* PDF-specific overrides for A4 print layout */
    body {
        margin: 0;
        background: #e5e7eb;
    }
    .fw-qi__main {
        padding: 0 !important;
        min-height: auto;
    }
    .fw-qi__document {
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        border-radius: 0;
        overflow: hidden;
    }
    /* Force header into equal columns so right side doesn't overflow */
    .fw-qi__doc-header {
        grid-template-columns: 1fr 1fr !important;
        gap: 24px !important;
    }
    /* Constrain tables to document width */
    .fw-qi__doc-table {
        table-layout: fixed;
        width: 100%;
    }
    .fw-qi__doc-table td,
    .fw-qi__doc-table th {
        overflow: hidden;
        text-overflow: ellipsis;
    }
    /* The milestones table has 7 columns — size them to content at A4 width
       so headers like "Outstanding" and status badges don't truncate */
    .fw-qi__milestones-table {
        table-layout: auto;
    }
    .fw-qi__milestone-badge {
        white-space: nowrap;
    }

    /* Print toolbar */
    .print-bar {
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #1f2937;
        color: #fff;
        padding: 10px 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        z-index: 100;
        font-size: 13px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .print-bar button {
        background: var(--accent-qi);
        color: #fff;
        border: none;
        padding: 8px 20px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 4px;
        cursor: pointer;
    }
    .print-bar button:hover { opacity: 0.85; }
    .print-bar a {
        color: #aaa;
        text-decoration: none;
        margin-left: auto;
    }
    .print-bar a:hover { color: #fff; }
    .page-spacer { height: 50px; }

    /* Force all colors to print exactly as shown on screen */
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }

    /* Print media */
    @media print {
        body { background: #fff; }
        .no-print { display: none !important; }
        .fw-qi__document {
            width: 100%;
            min-height: auto;
            margin: 0;
            box-shadow: none !important;
            border: none !important;
            border-radius: 0;
            page-break-after: always;
        }
        /* Force two-column header in print */
        .fw-qi__doc-header {
            grid-template-columns: 1fr 1fr !important;
            gap: 24px !important;
            padding: 40px 40px 16px !important;
        }
        .fw-qi__doc-meta {
            text-align: right !important;
        }
        .fw-qi__doc-logo {
            max-width: 480px !important;
            max-height: 160px !important;
        }
        .fw-qi__doc-header { page-break-inside: avoid; }
        .fw-qi__doc-details { page-break-inside: avoid; }
        .fw-qi__doc-totals { page-break-inside: avoid; }
        .fw-qi__doc-table thead { display: table-header-group; }
        .fw-qi__doc-table tbody tr { page-break-inside: avoid; }
    }
    @page {
        size: A4;
        margin: 10mm 0;
    }
</style>
</head>
<body>

<div class="print-bar no-print">
    <button onclick="window.print()">Save as PDF / Print</button>
    <span><?= htmlspecialchars($docNumber) ?></span>
    <a href="javascript:history.back()">&larr; Back</a>
</div>
<div class="page-spacer no-print"></div>

<main class="fw-qi__main">
    <div class="fw-qi__document" data-template="<?= $template ?>">
        <!-- Document Header — same HTML as invoice_view.php -->
        <div class="fw-qi__doc-header">
            <div class="fw-qi__doc-header-left<?= $logoPosition !== 'left' ? ' fw-qi__doc-header-left--' . $logoPosition : '' ?>">
                <?php if (!empty($doc['logo_url'])): ?>
                    <img src="<?= htmlspecialchars($doc['logo_url']) ?>" alt="Logo" class="fw-qi__doc-logo">
                <?php endif; ?>
                <div class="fw-qi__doc-company">
                    <h1><?= htmlspecialchars($doc['company_name'] ?? '') ?></h1>
                    <?php if ($showAddress): ?>
                        <?php if (!empty($doc['company_address1'])): ?><p><?= htmlspecialchars($doc['company_address1']) ?></p><?php endif; ?>
                        <?php if (!empty($doc['company_address2'])): ?><p><?= htmlspecialchars($doc['company_address2']) ?></p><?php endif; ?>
                        <?php if (!empty($doc['company_city'])): ?><p><?= htmlspecialchars($doc['company_city']) ?>, <?= htmlspecialchars($doc['company_postal'] ?? '') ?></p><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($showReg && !empty($doc['reg_number'])): ?><p><strong>Reg No:</strong> <?= htmlspecialchars($doc['reg_number']) ?></p><?php endif; ?>
                    <?php if ($showTax && !empty($doc['tax_number'])): ?><p><strong>Tax:</strong> <?= htmlspecialchars($doc['tax_number']) ?></p><?php endif; ?>
                    <?php if ($showVat && !empty($doc['vat_number'])): ?><p><strong>VAT No:</strong> <?= htmlspecialchars($doc['vat_number']) ?></p><?php endif; ?>
                    <?php if ($showPhone && !empty($doc['company_phone'])): ?><p><?= htmlspecialchars($doc['company_phone']) ?></p><?php endif; ?>
                    <?php if ($showEmail && !empty($doc['company_email'])): ?><p><?= htmlspecialchars($doc['company_email']) ?></p><?php endif; ?>
                    <?php if ($showWebsite && !empty($doc['website'])): ?><p><?= htmlspecialchars($doc['website']) ?></p><?php endif; ?>
                </div>
            </div>
            <div class="fw-qi__doc-header-right">
                <h2 class="fw-qi__doc-title"><?= htmlspecialchars($docType) ?></h2>
                <div class="fw-qi__doc-number"><?= htmlspecialchars($docNumber) ?></div>
                <table class="fw-qi__doc-info-table" style="margin-top:8px;">
                    <?php foreach ($dates as $label => $value): ?>
                        <tr><td><?= htmlspecialchars($label) ?>:</td><td><strong><?= htmlspecialchars($value) ?></strong></td></tr>
                    <?php endforeach; ?>
                </table>
                <span class="fw-qi__badge fw-qi__badge--<?= htmlspecialchars($doc['status']) ?>"><?= strtoupper(str_replace('_', ' ', $doc['status'])) ?></span>

                <div class="fw-qi__doc-bill-to" style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(0,0,0,0.08);">
                    <h3 style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--qi-heading-color);margin:0 0 8px 0;">Bill To</h3>
                    <p style="margin:4px 0;"><strong><?= htmlspecialchars($doc['customer_name'] ?? 'Customer') ?></strong></p>
                    <?php if (!empty($doc['customer_address1'])): ?>
                        <p style="margin:3px 0;"><?= htmlspecialchars($doc['customer_address1']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($doc['customer_address2'])): ?>
                        <p style="margin:3px 0;"><?= htmlspecialchars($doc['customer_address2']) ?></p>
                    <?php endif; ?>
                    <?php $custCity = trim(($doc['customer_city'] ?? '') . ', ' . ($doc['customer_region'] ?? '') . ' ' . ($doc['customer_postal'] ?? '')); ?>
                    <?php if ($custCity && $custCity !== ', '): ?>
                        <p style="margin:3px 0;"><?= htmlspecialchars($custCity) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($doc['customer_phone'])): ?>
                        <p style="margin:3px 0;">Tel: <?= htmlspecialchars($doc['customer_phone']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($doc['customer_email'])): ?>
                        <p style="margin:3px 0;">Email: <?= htmlspecialchars($doc['customer_email']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($doc['customer_vat'])): ?>
                        <p style="margin:3px 0;">VAT No: <?= htmlspecialchars($doc['customer_vat']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($doc['customer_reg'])): ?>
                        <p style="margin:3px 0;">Reg No: <?= htmlspecialchars($doc['customer_reg']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($doc['project_name'])): ?>
        <div class="fw-qi__classic-project">
            <h2><?= htmlspecialchars($doc['project_name']) ?></h2>
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
                <?php foreach ($lines as $li): ?>
                    <tr>
                        <td><?= htmlspecialchars($li['item_description']) ?></td>
                        <td style="text-align:right;"><?= number_format((float)$li['quantity'], 2) ?></td>
                        <td style="text-align:right;"><?= fmt($li['unit_price']) ?></td>
                        <td style="text-align:right;"><?= fmt($li['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Totals Summary -->
        <div class="fw-qi__doc-totals">
            <div class="fw-qi__doc-total-row">
                <span>Subtotal:</span>
                <span><?= fmt($doc['subtotal']) ?></span>
            </div>
            <?php if ((float)($doc['discount'] ?? 0) > 0): ?>
                <div class="fw-qi__doc-total-row">
                    <span>Discount:</span>
                    <span><?= fmt($doc['discount']) ?></span>
                </div>
            <?php endif; ?>
            <div class="fw-qi__doc-total-row">
                <span>VAT (15%):</span>
                <span><?= fmt($doc['tax']) ?></span>
            </div>
            <div class="fw-qi__doc-total-row fw-qi__doc-total-row--grand">
                <span>TOTAL:</span>
                <span><?= fmt($doc['total']) ?></span>
            </div>
            <?php if ($isForeign): ?>
                <div class="fw-qi__doc-total-row" style="font-size:12px;color:#6b7280;">
                    <span>ZAR equivalent (1 <?= htmlspecialchars($docCurrency) ?> = <?= number_format($docFxRate, 4) ?> ZAR):</span>
                    <span>R <?= number_format((float)$doc['total'] * $docFxRate, 2) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($type === 'invoice' && (float)($doc['balance_due'] ?? 0) < (float)$doc['total']): ?>
                <div class="fw-qi__doc-total-row" style="margin-top:8px;">
                    <span>Balance Due:</span>
                    <span><?= fmt($doc['balance_due']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment Milestones — same markup as invoice_view.php -->
        <?php if (!empty($milestones)): ?>
            <div class="fw-qi__doc-section fw-qi__milestones-view">
                <h3>Payment Schedule</h3>
                <?php
                    $schedPaid    = max(0, (float)$doc['total'] - (float)($doc['balance_due'] ?? $doc['total']));
                    $schedPaidPct = (float)$doc['total'] > 0 ? ($schedPaid / (float)$doc['total']) * 100 : 0;
                ?>
                <p style="margin:-4px 0 12px;font-size:13px;color:#6b7280;">
                    Paid <?= fmt($schedPaid) ?> of <?= fmt($doc['total']) ?>
                    (<?= number_format($schedPaidPct, 1) ?>%) — Outstanding <?= fmt($doc['balance_due'] ?? 0) ?>
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
                            $nowPayableId = null;
                            foreach ($milestones as $ms) {
                                if ($ms['status'] !== 'paid') { $nowPayableId = $ms['id']; break; }
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
                                <td style="text-align:right;"><?= fmt($ms['amount']) ?></td>
                                <td><?= $ms['due_date'] ? date('d M Y', strtotime($ms['due_date'])) : '—' ?></td>
                                <td style="text-align:right;"><?= fmt($ms['amount_paid']) ?></td>
                                <td style="text-align:right;"><?= fmt($msOutstanding) ?></td>
                                <td><span class="fw-qi__milestone-badge fw-qi__milestone-badge--<?= $msStatusClass ?>"><?= $msStatusLabel ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Payments Received (individual payment history) — same markup as invoice_view.php -->
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
                                <td style="text-align:right;"><?= fmt($pmt['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:right;font-weight:700;">Total received</td>
                            <td style="text-align:right;font-weight:700;"><?= fmt($paymentsTotal) ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" style="text-align:right;">Outstanding</td>
                            <td style="text-align:right;"><?= fmt($doc['balance_due'] ?? 0) ?></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Payment & Bank Details -->
        <?php if ($showPayment && (!empty($doc['bank_name']) || !empty($doc['bank_account_number']))): ?>
            <div class="fw-qi__doc-section">
                <h3>Payment Details</h3>
                <p>
                    <?php if (!empty($doc['bank_name'])): ?><strong>Bank:</strong> <?= htmlspecialchars($doc['bank_name']) ?><br><?php endif; ?>
                    <?php if (!empty($doc['bank_account_number'])): ?><strong>Account No:</strong> <?= htmlspecialchars($doc['bank_account_number']) ?><br><?php endif; ?>
                    <?php if (!empty($doc['bank_branch_code'])): ?><strong>Branch Code:</strong> <?= htmlspecialchars($doc['bank_branch_code']) ?><br><?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Terms -->
        <?php if (!empty($doc['terms'])): ?>
            <div class="fw-qi__doc-section">
                <h3>Terms & Conditions</h3>
                <p><?= nl2br(htmlspecialchars($doc['terms'])) ?></p>
            </div>
        <?php endif; ?>

        <!-- Notes -->
        <?php if (!empty($doc['notes'])): ?>
            <div class="fw-qi__doc-section">
                <h3>Internal Notes</h3>
                <p><?= nl2br(htmlspecialchars($doc['notes'])) ?></p>
            </div>
        <?php endif; ?>

        <!-- Footer Text (per document type) -->
        <?php if (!empty($brand['footer'])): ?>
            <div class="fw-qi__doc-footer">
                <p><?= nl2br(htmlspecialchars($brand['footer'])) ?></p>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
// Let the browser's print engine render the same HTML/CSS as the on-screen
// view — this guarantees the PDF matches the web view exactly. Mobile Chrome
// and Safari both expose "Save as PDF" in the native print dialog.
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
</body>
</html>
