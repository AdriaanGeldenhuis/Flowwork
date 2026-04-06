<?php
// /qi/ajax/generate_pdf.php – Generate a print-ready invoice/quote/credit note page
// Renders styled HTML matching the invoice_view.php layout, optimized for
// browser Print → Save as PDF.
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

function fmt($amount) {
    return 'R ' . number_format((float)$amount, 2);
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
                    c.qi_table_header_text, c.qi_bg_color,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    p.name AS project_name
             FROM quotes q
             LEFT JOIN companies c ON q.company_id = c.id
             LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
             LEFT JOIN projects p ON q.project_id = p.project_id
             WHERE q.id = ? AND q.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Quote not found'); }

        $docType = 'QUOTE';
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
                    c.qi_table_header_text, c.qi_bg_color,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    i.invoice_number AS linked_invoice_number
             FROM credit_notes cn
             LEFT JOIN companies c ON cn.company_id = c.id
             LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id
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
                    c.qi_table_header_text, c.qi_bg_color,
                    ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                    p.name AS project_name
             FROM invoices i
             LEFT JOIN companies c ON i.company_id = c.id
             LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
             LEFT JOIN projects p ON i.project_id = p.project_id
             WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) { throw new Exception('Invoice not found'); }

        $docType = 'INVOICE';
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

    // Branding colours from company settings
    $primaryColor     = $doc['primary_color'] ?: '#fbbf24';
    $secondaryColor   = $doc['secondary_color'] ?: '#f59e0b';
    $headingColor     = $doc['qi_heading_color'] ?: $primaryColor;
    $textColor        = $doc['qi_text_color'] ?: '#374151';
    $tableHeaderText  = $doc['qi_table_header_text'] ?: '#ffffff';
    $bgColor          = $doc['qi_bg_color'] ?: '#ffffff';

    // Compute rgba versions of primary colour for backgrounds/borders
    $pHex = ltrim($primaryColor, '#');
    $pR = hexdec(substr($pHex, 0, 2));
    $pG = hexdec(substr($pHex, 2, 2));
    $pB = hexdec(substr($pHex, 4, 2));
    $primaryRgba = function($alpha) use ($pR, $pG, $pB) {
        return "rgba({$pR},{$pG},{$pB},{$alpha})";
    };

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
<style>
    :root {
        --pdf-primary: <?= htmlspecialchars($primaryColor) ?>;
        --pdf-heading: <?= htmlspecialchars($headingColor) ?>;
        --pdf-text: <?= htmlspecialchars($textColor) ?>;
        --pdf-th-text: <?= htmlspecialchars($tableHeaderText) ?>;
        --pdf-bg: <?= htmlspecialchars($bgColor) ?>;
        --pdf-primary-005: <?= $primaryRgba(0.05) ?>;
        --pdf-primary-004: <?= $primaryRgba(0.04) ?>;
        --pdf-primary-008: <?= $primaryRgba(0.08) ?>;
        --pdf-primary-02: <?= $primaryRgba(0.2) ?>;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
        font-size: 14px;
        color: var(--pdf-text);
        background: #e5e7eb;
    }
    .page {
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        background: var(--pdf-bg);
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }

    /* Header — matches invoice_view grid layout */
    .header {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 40px;
        padding: 36px 40px;
        border-bottom: 4px solid var(--pdf-primary);
        background: linear-gradient(135deg, var(--pdf-primary-005) 0%, transparent 100%);
    }
    .header-left {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .company-logo {
        max-width: 200px;
        max-height: 70px;
        object-fit: contain;
        margin-bottom: 8px;
    }
    .doc-type {
        font-size: 24px;
        font-weight: 800;
        color: var(--pdf-heading);
        margin: 0 0 6px;
    }
    .company-name {
        font-size: 13px;
        font-weight: 700;
        color: #1f2937;
        margin: 0 0 4px;
    }
    .company-info p {
        font-size: 12px;
        color: #374151;
        line-height: 1.6;
        margin: 2px 0;
    }
    .header-right {
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }
    .header-right h2 {
        font-size: 20px;
        font-weight: 800;
        color: #1f2937;
        margin-bottom: 8px;
    }
    .header-right p {
        font-size: 12px;
        color: #374151;
        line-height: 1.8;
        margin: 0;
    }
    .header-right .reg-info {
        margin-top: 8px;
        padding-top: 6px;
        border-top: 1px solid #e5e7eb;
    }
    .header-right .reg-info p {
        font-size: 11px;
        color: #6b7280;
    }

    /* Bill To / Project boxes */
    .details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        padding: 28px 40px;
        background: rgba(0,0,0,0.02);
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .detail-box {
        padding: 18px 20px;
        background: #fff;
        border-radius: 6px;
        border-left: 3px solid var(--pdf-primary);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .detail-box h3 {
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--pdf-heading);
        margin-bottom: 10px;
    }
    .detail-box p {
        font-size: 13px;
        color: #1f2937;
        margin: 4px 0;
        line-height: 1.5;
    }

    /* Line items table */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
        page-break-inside: auto;
    }
    .items-table thead {
        display: table-header-group;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .items-table thead th {
        background: var(--pdf-primary);
        color: var(--pdf-th-text);
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 14px 16px;
        text-align: left;
    }
    .items-table thead th.right { text-align: right; }
    .items-table tbody td {
        padding: 13px 16px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        font-size: 13px;
        color: #1f2937;
        vertical-align: top;
    }
    .items-table tbody tr {
        page-break-inside: avoid;
    }
    .items-table tbody td.right { text-align: right; }
    .items-table tbody tr:hover { background: var(--pdf-primary-004); }

    /* Totals */
    .totals {
        max-width: 380px;
        margin: 24px 40px 24px auto;
        padding: 20px;
        background: var(--pdf-primary-008);
        border-radius: 8px;
        border: 2px solid var(--pdf-primary-02);
    }
    .total-row {
        display: flex;
        justify-content: space-between;
        padding: 9px 0;
        font-size: 14px;
        color: #1f2937;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .total-row:last-child { border-bottom: none; }
    .total-row span:first-child { color: #6b7280; font-weight: 600; }
    .total-row span:last-child { font-weight: 700; }
    .total-row.grand {
        font-size: 20px;
        font-weight: 900;
        color: var(--pdf-primary);
        border-top: 2px solid var(--pdf-primary);
        border-bottom: none;
        padding-top: 14px;
        margin-top: 8px;
    }
    .total-row.grand span:first-child { color: var(--pdf-primary); }
    .total-row.grand span:last-child { color: var(--pdf-primary); }

    /* Sections (payment details, terms, notes) */
    .section {
        padding: 0 40px;
        margin-bottom: 20px;
        page-break-inside: avoid;
    }
    .section h3 {
        font-size: 14px;
        font-weight: 800;
        color: var(--pdf-heading);
        margin-bottom: 12px;
        padding-bottom: 6px;
        border-bottom: 2px solid var(--pdf-primary);
        text-transform: uppercase;
    }
    .section p {
        font-size: 13px;
        color: #374151;
        line-height: 1.7;
    }

    /* Print styles */
    @media print {
        body { background: #fff; }
        .page {
            margin: 0;
            width: 100%;
            min-height: auto;
            box-shadow: none;
            border-radius: 0;
        }
        .no-print { display: none !important; }
        .header { page-break-inside: avoid; }
        .details { page-break-inside: avoid; }
        .totals { page-break-inside: avoid; }
        .items-table thead {
            display: table-header-group;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .items-table tbody tr { page-break-inside: avoid; }
        .items-table thead th {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
    @page {
        size: A4;
        margin: 10mm 0;
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
        background: var(--pdf-primary);
        color: #000;
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
</style>
</head>
<body>

<div class="print-bar no-print">
    <button onclick="window.print()">Save as PDF / Print</button>
    <span><?= htmlspecialchars($docNumber) ?></span>
    <a href="javascript:history.back()">← Back</a>
</div>
<div class="page-spacer no-print"></div>

<div class="page">
    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <?php if (!empty($doc['logo_url'])): ?>
                <img src="<?= htmlspecialchars($doc['logo_url']) ?>" alt="Logo" class="company-logo">
            <?php endif; ?>
            <div class="doc-type"><?= $docType ?></div>
            <?php if (!empty($doc['company_name'])): ?>
                <div class="company-name"><?= htmlspecialchars($doc['company_name']) ?></div>
            <?php endif; ?>
            <div class="company-info">
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
            </div>
        </div>
        <div class="header-right">
            <h2><?= htmlspecialchars($docTitle) ?></h2>
            <?php foreach ($dates as $label => $value): ?>
                <p><?= htmlspecialchars($label) ?>: <?= htmlspecialchars($value) ?></p>
            <?php endforeach; ?>
            <?php if (($showVat && !empty($doc['vat_number'])) || ($showTax && !empty($doc['tax_number'])) || ($showReg && !empty($doc['reg_number']))): ?>
                <div class="reg-info">
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
            <?php endif; ?>
        </div>
    </div>

    <!-- Bill To / Project -->
    <div class="details">
        <div class="detail-box">
            <h3>Bill To</h3>
            <p><strong><?= htmlspecialchars($doc['customer_name'] ?? 'Customer') ?></strong></p>
            <?php if (!empty($doc['customer_phone'])): ?>
                <p>Phone: <?= htmlspecialchars($doc['customer_phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($doc['customer_email'])): ?>
                <p>Email: <?= htmlspecialchars($doc['customer_email']) ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($doc['project_name'])): ?>
            <div class="detail-box">
                <h3>Project</h3>
                <p><?= htmlspecialchars($doc['project_name']) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Line Items -->
    <table class="items-table" style="margin-top:0;">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Qty</th>
                <th class="right">Unit Price</th>
                <th class="right">Line Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $li): ?>
                <tr>
                    <td><?= htmlspecialchars($li['item_description']) ?></td>
                    <td class="right"><?= number_format((float)$li['quantity'], 2) ?></td>
                    <td class="right"><?= fmt($li['unit_price']) ?></td>
                    <td class="right"><?= fmt($li['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals">
        <div class="total-row">
            <span>Subtotal:</span>
            <span><?= fmt($doc['subtotal']) ?></span>
        </div>
        <?php if ((float)($doc['discount'] ?? 0) > 0): ?>
            <div class="total-row">
                <span>Discount:</span>
                <span><?= fmt($doc['discount']) ?></span>
            </div>
        <?php endif; ?>
        <div class="total-row">
            <span>VAT (15%):</span>
            <span><?= fmt($doc['tax']) ?></span>
        </div>
        <div class="total-row grand">
            <span>TOTAL:</span>
            <span><?= fmt($doc['total']) ?></span>
        </div>
        <?php if ($type === 'invoice' && (float)($doc['balance_due'] ?? 0) < (float)$doc['total']): ?>
            <div class="total-row" style="margin-top:6px;">
                <span>Balance Due:</span>
                <span><?= fmt($doc['balance_due']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bank Details -->
    <?php if (!empty($doc['bank_name']) || !empty($doc['bank_account_number'])): ?>
        <div class="section">
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
        <div class="section">
            <h3>Terms & Conditions</h3>
            <p><?= nl2br(htmlspecialchars($doc['terms'])) ?></p>
        </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php if (!empty($doc['notes'])): ?>
        <div class="section">
            <h3>Notes</h3>
            <p><?= nl2br(htmlspecialchars($doc['notes'])) ?></p>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <?php if (!empty($doc['invoice_footer_text'])): ?>
        <div class="section" style="margin-top: 30px; text-align: center; color: #888; font-size: 9px;">
            <p><?= nl2br(htmlspecialchars($doc['invoice_footer_text'])) ?></p>
        </div>
    <?php endif; ?>
</div>

<script>
// Auto-trigger print dialog
window.addEventListener('load', function() {
    // Small delay to ensure styles and images are loaded
    setTimeout(function() { window.print(); }, 500);
});
</script>
</body>
</html>
