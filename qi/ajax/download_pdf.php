<?php
/**
 * /qi/ajax/download_pdf.php — Generate and stream a styled PDF for download.
 *
 * Uses Dompdf to render the same styled HTML template as generate_pdf.php,
 * producing a PDF that looks identical to the app view.
 *
 * Usage: GET ?type=invoice|quote|credit_note&id=123
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$companyId = $_SESSION['company_id'];
$type      = $_GET['type'] ?? 'invoice';
$id        = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}

function fmtAmount($amount) {
    return 'R ' . number_format((float)$amount, 2);
}

function sanitize_css_color(string $color, string $fallback = '#fbbf24'): string {
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : $fallback;
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
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

        $docType   = $doc['qi_quote_title'] ?? 'QUOTE';
        $docNumber = $doc['quote_number'];
        $docTitle  = 'Quote #: ' . $docNumber;
        $dates = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Expires'    => date('d M Y', strtotime($doc['expiry_date'])),
            'Status'     => ucfirst($doc['status']),
        ];
        $filename = $doc['quote_number'] . '.pdf';

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $milestones = [];
        $footerText = $doc['quote_footer_text'] ?? '';

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
        $docType   = 'CREDIT NOTE';
        $docNumber = $doc['credit_note_number'];
        $docTitle  = 'Credit Note #: ' . $docNumber;
        $dates = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Status'     => ucfirst($doc['status']),
        ];
        if (!empty($doc['linked_invoice_number'])) {
            $dates['Invoice'] = $doc['linked_invoice_number'];
        }
        $filename = $doc['credit_note_number'] . '.pdf';

        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $milestones = [];
        $footerText = $doc['invoice_footer_text'] ?? '';

    } else {
        // Invoice (default)
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

        $docType   = $doc['qi_invoice_title'] ?? 'INVOICE';
        $docNumber = $doc['invoice_number'];
        $docTitle  = 'Invoice #: ' . $docNumber;
        $dates = [
            'Issue Date' => date('d M Y', strtotime($doc['issue_date'])),
            'Due Date'   => date('d M Y', strtotime($doc['due_date'])),
            'Status'     => ucfirst($doc['status']),
        ];
        $filename = $doc['invoice_number'] . '.pdf';

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
        $footerText = $doc['invoice_footer_text'] ?? '';
    }

    // ── Resolve branding values ──
    $showAddress = (int)($doc['qi_show_company_address'] ?? 1);
    $showPhone   = (int)($doc['qi_show_company_phone']   ?? 1);
    $showEmail   = (int)($doc['qi_show_company_email']   ?? 1);
    $showWebsite = (int)($doc['qi_show_company_website'] ?? 1);
    $showVat     = (int)($doc['qi_show_vat_number']      ?? 1);
    $showTax     = (int)($doc['qi_show_tax_number']      ?? 1);
    $showReg     = (int)($doc['qi_show_reg_number']      ?? 1);

    $primaryColor    = sanitize_css_color($doc['primary_color'] ?? '#fbbf24');
    $secondaryColor  = sanitize_css_color($doc['secondary_color'] ?? '#f59e0b', '#f59e0b');
    $headingColor    = sanitize_css_color($doc['qi_heading_color'] ?? '', $primaryColor);
    $textColor       = sanitize_css_color($doc['qi_text_color'] ?? '', '#374151');
    $tableHeaderText = $doc['qi_table_header_text'] ?: '#ffffff';
    $bgColor         = $doc['qi_bg_color'] ?? '#ffffff';
    $logoSize        = max(80, min(400, (int)($doc['qi_logo_size'] ?? 200)));

    // ── Build HTML for Dompdf ──
    // Uses tables instead of CSS Grid/Flex (Dompdf doesn't support them)
    // All CSS variables resolved to actual values

    // Company info lines
    $companyLines = [];
    if ($showAddress) {
        $addr = e($doc['company_address1'] ?? '');
        if (!empty($doc['company_address2'])) $addr .= '<br>' . e($doc['company_address2']);
        $addr .= '<br>' . e($doc['company_city'] ?? '') . ', ' . e($doc['company_region'] ?? '') . ' ' . e($doc['company_postal'] ?? '');
        $companyLines[] = $addr;
    }
    if ($showPhone && !empty($doc['company_phone'])) $companyLines[] = 'Tel: ' . e($doc['company_phone']);
    if ($showEmail && !empty($doc['company_email'])) $companyLines[] = 'Email: ' . e($doc['company_email']);
    if ($showWebsite && !empty($doc['website']))     $companyLines[] = 'Website: ' . e($doc['website']);
    if ($showVat && !empty($doc['vat_number']))      $companyLines[] = 'VAT No: ' . e($doc['vat_number']);
    if ($showTax && !empty($doc['tax_number']))      $companyLines[] = 'Tax No: ' . e($doc['tax_number']);
    if ($showReg && !empty($doc['reg_number']))      $companyLines[] = 'Reg No: ' . e($doc['reg_number']);

    // Customer info lines
    $customerLines = [];
    $customerLines[] = '<strong>' . e($doc['customer_name'] ?? 'Customer') . '</strong>';
    if (!empty($doc['customer_address1'])) $customerLines[] = e($doc['customer_address1']);
    if (!empty($doc['customer_address2'])) $customerLines[] = e($doc['customer_address2']);
    $custCity = trim(($doc['customer_city'] ?? '') . ', ' . ($doc['customer_region'] ?? '') . ' ' . ($doc['customer_postal'] ?? ''));
    if ($custCity && $custCity !== ', ') $customerLines[] = e($custCity);
    if (!empty($doc['customer_phone'])) $customerLines[] = 'Tel: ' . e($doc['customer_phone']);
    if (!empty($doc['customer_email'])) $customerLines[] = 'Email: ' . e($doc['customer_email']);
    if (!empty($doc['customer_vat']))   $customerLines[] = 'VAT No: ' . e($doc['customer_vat']);
    if (!empty($doc['customer_reg']))   $customerLines[] = 'Reg No: ' . e($doc['customer_reg']);

    // Logo HTML
    $logoHtml = '';
    if (!empty($doc['logo_url'])) {
        $logoUrl = $doc['logo_url'];
        // Ensure absolute URL for Dompdf
        if (strpos($logoUrl, '//') === false) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $logoUrl = $scheme . '://' . $host . $logoUrl;
        }
        $logoHtml = '<img src="' . e($logoUrl) . '" style="max-width:' . $logoSize . 'px;max-height:160px;margin-bottom:12px;">';
    }

    // Dates HTML
    $datesHtml = '';
    foreach ($dates as $label => $value) {
        $datesHtml .= '<p style="margin:1px 0;font-size:13px;color:#374151;">' . e($label) . ': ' . e($value) . '</p>';
    }

    // Line items rows
    $lineRowsHtml = '';
    foreach ($lines as $li) {
        $lineRowsHtml .= '<tr>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">' . e($li['item_description']) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;text-align:right;">' . number_format((float)$li['quantity'], 2) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;text-align:right;">' . fmtAmount($li['unit_price']) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;text-align:right;">' . fmtAmount($li['line_total']) . '</td>'
            . '</tr>';
    }

    // Totals rows
    $totalsHtml = '';
    $totalsHtml .= '<tr><td style="padding:4px 0;font-size:13px;font-weight:700;color:#1f2937;">Subtotal:</td><td style="padding:4px 0;font-size:13px;text-align:right;color:#374151;">' . fmtAmount($doc['subtotal']) . '</td></tr>';
    if ((float)($doc['discount'] ?? 0) > 0) {
        $totalsHtml .= '<tr><td style="padding:4px 0;font-size:13px;font-weight:700;color:#1f2937;">Discount:</td><td style="padding:4px 0;font-size:13px;text-align:right;color:#374151;">' . fmtAmount($doc['discount']) . '</td></tr>';
    }
    $totalsHtml .= '<tr><td style="padding:4px 0;font-size:13px;font-weight:700;color:#1f2937;">VAT (15%):</td><td style="padding:4px 0;font-size:13px;text-align:right;color:#374151;">' . fmtAmount($doc['tax']) . '</td></tr>';
    $totalsHtml .= '<tr><td style="padding:8px 0 4px;font-size:16px;font-weight:900;color:#1f2937;border-top:1px solid #1f2937;">TOTAL:</td><td style="padding:8px 0 4px;font-size:16px;font-weight:900;text-align:right;color:#1f2937;border-top:1px solid #1f2937;">' . fmtAmount($doc['total']) . '</td></tr>';
    if ($type === 'invoice' && isset($doc['balance_due'])) {
        $totalsHtml .= '<tr><td style="padding:4px 0;font-size:13px;font-weight:700;color:#1f2937;">Balance Due:</td><td style="padding:4px 0;font-size:13px;text-align:right;font-weight:700;color:#ef4444;">' . fmtAmount($doc['balance_due']) . '</td></tr>';
    }

    // Milestones HTML
    $milestonesHtml = '';
    if (!empty($milestones)) {
        $nowPayableId = null;
        foreach ($milestones as $ms) {
            if ($ms['status'] !== 'paid') { $nowPayableId = $ms['id']; break; }
        }
        $msRows = '';
        foreach ($milestones as $ms) {
            $isNowPayable = ($nowPayableId !== null && $ms['id'] == $nowPayableId);
            if ($ms['status'] === 'paid') { $statusLabel = 'Paid'; $statusColor = '#10b981'; $statusBg = '#d1fae5'; }
            elseif ($ms['status'] === 'overdue') { $statusLabel = 'Overdue'; $statusColor = '#ef4444'; $statusBg = '#fee2e2'; }
            elseif ($isNowPayable) { $statusLabel = 'Now Payable'; $statusColor = '#f59e0b'; $statusBg = '#fef3c7'; }
            else { $statusLabel = 'Upcoming'; $statusColor = '#6b7280'; $statusBg = '#f1f5f9'; }

            $msRows .= '<tr>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;">' . e($ms['label']) . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;text-align:right;">' . number_format($ms['percentage'], 1) . '%</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;text-align:right;">' . fmtAmount($ms['amount']) . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;">' . ($ms['due_date'] ? date('d M Y', strtotime($ms['due_date'])) : '—') . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;text-align:right;">' . fmtAmount($ms['amount_paid']) . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;">'
                . '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:10px;font-weight:700;background:' . $statusBg . ';color:' . $statusColor . ';">' . $statusLabel . '</span>'
                . '</td></tr>';
        }
        $milestonesHtml = '
        <div style="padding:16px 40px;">
            <h3 style="font-size:11px;font-weight:800;color:#1f2937;margin:0 0 10px;padding-bottom:8px;border-bottom:1px solid #d1d5db;text-transform:uppercase;letter-spacing:0.5px;">Payment Schedule</h3>
            <table style="width:100%;border-collapse:collapse;border-top:1px solid #9ca3af;">
                <thead><tr>
                    <th style="padding:8px 12px;text-align:left;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;background:#f0f0f0;">Phase</th>
                    <th style="padding:8px 12px;text-align:right;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;background:#f0f0f0;">%</th>
                    <th style="padding:8px 12px;text-align:right;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;background:#f0f0f0;">Amount</th>
                    <th style="padding:8px 12px;text-align:left;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;background:#f0f0f0;">Due Date</th>
                    <th style="padding:8px 12px;text-align:right;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;background:#f0f0f0;">Paid</th>
                    <th style="padding:8px 12px;text-align:left;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;background:#f0f0f0;">Status</th>
                </tr></thead>
                <tbody>' . $msRows . '</tbody>
            </table>
        </div>';
    }

    // Bank details
    $bankHtml = '';
    if (!empty($doc['bank_name']) || !empty($doc['bank_account_number'])) {
        $bankLines = '';
        if (!empty($doc['bank_name']))           $bankLines .= '<strong>Bank:</strong> ' . e($doc['bank_name']) . '<br>';
        if (!empty($doc['bank_account_number'])) $bankLines .= '<strong>Account No:</strong> ' . e($doc['bank_account_number']) . '<br>';
        if (!empty($doc['bank_branch_code']))    $bankLines .= '<strong>Branch Code:</strong> ' . e($doc['bank_branch_code']) . '<br>';
        $bankHtml = '
        <div style="padding:16px 40px;">
            <h3 style="font-size:11px;font-weight:800;color:#1f2937;margin:0 0 10px;padding-bottom:8px;border-bottom:1px solid #d1d5db;text-transform:uppercase;letter-spacing:0.5px;">Payment Details</h3>
            <p style="font-size:13px;color:#374151;">' . $bankLines . '</p>
        </div>';
    }

    // Terms
    $termsHtml = '';
    if (!empty($doc['terms'])) {
        $termsHtml = '
        <div style="padding:16px 40px;">
            <h3 style="font-size:11px;font-weight:800;color:#1f2937;margin:0 0 10px;padding-bottom:8px;border-bottom:1px solid #d1d5db;text-transform:uppercase;letter-spacing:0.5px;">Terms &amp; Conditions</h3>
            <p style="font-size:13px;color:#374151;">' . nl2br(e($doc['terms'])) . '</p>
        </div>';
    }

    // Notes
    $notesHtml = '';
    if (!empty($doc['notes'])) {
        $notesHtml = '
        <div style="padding:16px 40px;">
            <h3 style="font-size:11px;font-weight:800;color:#1f2937;margin:0 0 10px;padding-bottom:8px;border-bottom:1px solid #d1d5db;text-transform:uppercase;letter-spacing:0.5px;">Notes</h3>
            <p style="font-size:13px;color:#374151;">' . nl2br(e($doc['notes'])) . '</p>
        </div>';
    }

    // Footer
    $footerHtml = '';
    if (!empty($footerText)) {
        $footerHtml = '
        <div style="padding:20px 40px 40px;font-size:13px;color:#374151;line-height:1.7;text-align:center;">
            <p>' . nl2br(e($footerText)) . '</p>
        </div>';
    }

    // ── Assemble full HTML ──
    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { size: A4; margin: 10mm 0; }
    body { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; color: #374151; background: #ffffff; }
    * { box-sizing: border-box; }
</style>
</head>
<body>
<div style="background:#ffffff;max-width:210mm;margin:0 auto;border:1px solid #d1d5db;overflow:hidden;">

    <!-- Header: two-column table layout -->
    <table style="width:100%;border-collapse:collapse;padding:0;">
        <tr>
            <td style="vertical-align:top;padding:40px 20px 16px 40px;width:55%;">
                ' . $logoHtml . '
                <div>
                    ' . implode('<br>', array_map(function($line) {
                        return '<span style="font-size:13px;color:#374151;line-height:1.6;">' . $line . '</span>';
                    }, $companyLines)) . '
                </div>
            </td>
            <td style="vertical-align:top;padding:40px 40px 16px 20px;text-align:right;width:45%;">
                <h2 style="font-size:15px;font-weight:700;color:' . e($headingColor) . ';margin:0 0 4px;">' . e($docTitle) . '</h2>
                ' . $datesHtml . '

                <div style="margin-top:18px;padding-top:14px;border-top:1px solid rgba(0,0,0,0.08);">
                    <h3 style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:' . e($headingColor) . ';margin:0 0 8px;">Bill To</h3>
                    ' . implode('<br>', array_map(function($line) {
                        return '<span style="font-size:13px;color:#374151;">' . $line . '</span>';
                    }, $customerLines)) . '
                </div>
            </td>
        </tr>
    </table>';

    // Project name
    if (!empty($doc['project_name'])) {
        $html .= '<div style="padding:14px 40px 10px;"><h2 style="font-size:15px;font-weight:700;color:#1f2937;margin:0;">Project: ' . e($doc['project_name']) . '</h2></div>';
    }

    // Line items table
    $html .= '
    <table style="width:100%;border-collapse:collapse;border-top:1px solid #9ca3af;">
        <thead>
            <tr style="background:#f0f0f0;">
                <th style="padding:8px 12px;text-align:left;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;">Description</th>
                <th style="padding:8px 12px;text-align:right;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;">Qty</th>
                <th style="padding:8px 12px;text-align:right;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;">Unit Price</th>
                <th style="padding:8px 12px;text-align:right;font-size:12px;font-weight:700;color:#1f2937;border-bottom:1px solid #9ca3af;">Line Total</th>
            </tr>
        </thead>
        <tbody>' . $lineRowsHtml . '</tbody>
    </table>';

    // Totals (right-aligned table)
    $html .= '
    <table style="width:340px;margin-left:auto;margin-top:8px;padding-right:12px;border-collapse:collapse;">
        ' . $totalsHtml . '
    </table>';

    // Milestones, bank, terms, notes, footer
    $html .= $milestonesHtml . $bankHtml . $termsHtml . $notesHtml . $footerHtml;

    $html .= '
</div>
</body>
</html>';

    // ── Render PDF with Dompdf ──
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    $options->set('isPhpEnabled', false);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfContent = $dompdf->output();

    // Stream as download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $pdfContent;

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
