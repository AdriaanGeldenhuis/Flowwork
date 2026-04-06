<?php
// /qi/ajax/generate_pdf.php – Generate a print-ready invoice/quote/credit note page.
// Uses the SAME templates-pro.css and HTML structure as invoice_view.php / quote_view.php
// so the PDF always looks identical to the app.
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$type      = $_GET['type'] ?? 'quote';
$id        = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}

define('ASSET_VERSION', '2026-04-06-QI-v2');

function fmt($amount) {
    return 'R ' . number_format((float)$amount, 2);
}

function sanitize_css_color(string $color, string $fallback = '#fbbf24'): string {
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : $fallback;
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
            'Status' => ucfirst($doc['status']),
        ];

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            'Status' => ucfirst($doc['status']),
        ];
        if (!empty($doc['linked_invoice_number'])) {
            $dates['Invoice'] = $doc['linked_invoice_number'];
        }

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            'Status' => ucfirst($doc['status']),
        ];

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Display toggles
    $showAddress = (int)($doc['qi_show_company_address'] ?? 1);
    $showPhone   = (int)($doc['qi_show_company_phone']   ?? 1);
    $showEmail   = (int)($doc['qi_show_company_email']   ?? 1);
    $showWebsite = (int)($doc['qi_show_company_website'] ?? 1);
    $showVat     = (int)($doc['qi_show_vat_number']      ?? 1);
    $showTax     = (int)($doc['qi_show_tax_number']      ?? 1);
    $showReg     = (int)($doc['qi_show_reg_number']      ?? 1);

    // Branding — identical to invoice_view.php
    $primaryColor    = sanitize_css_color($doc['primary_color'] ?? '#fbbf24');
    $secondaryColor  = sanitize_css_color($doc['secondary_color'] ?? '#f59e0b', '#f59e0b');
    $headingColor    = sanitize_css_color($doc['qi_heading_color'] ?? '', $primaryColor);
    $textColor       = $doc['qi_text_color'] ?? '';
    $tableHeaderText = $doc['qi_table_header_text'] ?: '#ffffff';
    $bgColor         = $doc['qi_bg_color'] ?? '';
    $borderRadius    = max(0, min(24, (int)($doc['qi_border_radius'] ?? 8)));
    $logoSize        = max(80, min(400, (int)($doc['qi_logo_size'] ?? 200)));
    $logoPosition    = in_array($doc['qi_logo_position'] ?? 'left', ['left','center','right']) ? $doc['qi_logo_position'] : 'left';
    $template        = in_array($doc['qi_template'] ?? 'modern', ['modern','classic','minimal','bold','corporate']) ? $doc['qi_template'] : 'modern';
    $fontFamily      = $doc['qi_font_family'] ?? 'system-ui';
    $customCss       = $doc['qi_custom_css'] ?? '';
    $fontMap = [
        'system-ui'  => 'system-ui, -apple-system, sans-serif',
        'montserrat' => "'Montserrat', sans-serif",
        'helvetica'  => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        'georgia'    => 'Georgia, serif',
        'inter'      => "'Inter', sans-serif"
    ];
    $fontStack = $fontMap[$fontFamily] ?? $fontMap['system-ui'];

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

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<!-- Use the SAME template CSS as the app -->
<link rel="stylesheet" href="/qi/assets/templates-pro.css?v=<?= ASSET_VERSION ?>">

<style>
    /* Branding variables — identical to invoice_view.php */
    :root {
        --accent-qi: <?= $primaryColor ?>;
        --accent-qi-secondary: <?= $secondaryColor ?>;
        --doc-font: <?= $fontStack ?>;
        --qi-heading: <?= $headingColor ?>;
        --qi-border-radius: <?= $borderRadius ?>px;
        --qi-logo-max-w: <?= $logoSize ?>px;
        --qi-th-text: <?= $tableHeaderText ?>;
        <?php if ($textColor): ?>--qi-text: <?= $textColor ?>;<?php endif; ?>
        <?php if ($bgColor): ?>--qi-doc-bg: <?= $bgColor ?>;<?php endif; ?>
    }
    .fw-qi__document, .fw-qi__document * { font-family: <?= $fontStack ?> !important; }
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
        /* Force two-column header in print (override mobile breakpoint) */
        .fw-qi__doc-header {
            grid-template-columns: 1fr auto !important;
            gap: 40px !important;
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
                    <h1 class="fw-qi__doc-title"><?= htmlspecialchars($docType) ?></h1>
                    <?php if ($showAddress): ?>
                        <p><?= htmlspecialchars($doc['company_address1'] ?? '') ?>
                        <?php if (!empty($doc['company_address2'])): ?><br><?= htmlspecialchars($doc['company_address2']) ?><?php endif; ?>
                        <br><?= htmlspecialchars($doc['company_city'] ?? '') ?>, <?= htmlspecialchars($doc['company_region'] ?? '') ?> <?= htmlspecialchars($doc['company_postal'] ?? '') ?></p>
                    <?php endif; ?>
                    <?php if ($showPhone && !empty($doc['company_phone'])): ?>
                        <p>Tel: <?= htmlspecialchars($doc['company_phone']) ?></p>
                    <?php endif; ?>
                    <?php if ($showEmail && !empty($doc['company_email'])): ?>
                        <p>Email: <?= htmlspecialchars($doc['company_email']) ?></p>
                    <?php endif; ?>
                    <?php if ($showWebsite && !empty($doc['website'])): ?>
                        <p>Website: <?= htmlspecialchars($doc['website']) ?></p>
                    <?php endif; ?>
                    <?php if ($showVat && !empty($doc['vat_number'])): ?>
                        <p>VAT No: <?= htmlspecialchars($doc['vat_number']) ?></p>
                    <?php endif; ?>
                    <?php if ($showTax && !empty($doc['tax_number'])): ?>
                        <p>Tax No: <?= htmlspecialchars($doc['tax_number']) ?></p>
                    <?php endif; ?>
                    <?php if ($showReg && !empty($doc['reg_number'])): ?>
                        <p>Reg No: <?= htmlspecialchars($doc['reg_number']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="fw-qi__doc-meta">
                <h2><?= htmlspecialchars($docTitle) ?></h2>
                <?php foreach ($dates as $label => $value): ?>
                    <p><?= htmlspecialchars($label) ?>: <?= htmlspecialchars($value) ?></p>
                <?php endforeach; ?>

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
            <?php if ($type === 'invoice' && (float)($doc['balance_due'] ?? 0) < (float)$doc['total']): ?>
                <div class="fw-qi__doc-total-row" style="margin-top:8px;">
                    <span>Balance Due:</span>
                    <span><?= fmt($doc['balance_due']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment & Bank Details -->
        <?php if (!empty($doc['bank_name']) || !empty($doc['bank_account_number'])): ?>
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
                <h3>Notes</h3>
                <p><?= nl2br(htmlspecialchars($doc['notes'])) ?></p>
            </div>
        <?php endif; ?>

        <!-- Footer Text -->
        <?php if (!empty($doc['invoice_footer_text'])): ?>
            <div class="fw-qi__doc-footer">
                <p><?= nl2br(htmlspecialchars($doc['invoice_footer_text'])) ?></p>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
</body>
</html>
