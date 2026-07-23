<?php
// /qi/ajax/send_invoice.php - COMPLETE WORKING VERSION
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/require_writer.php';
require_once __DIR__ . '/../lib/Branding.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input format']);
    exit;
}

$invoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;
$sendTo = isset($input['send_to']) ? trim($input['send_to']) : '';

if (!$invoiceId) {
    echo json_encode(['ok' => false, 'error' => 'Invoice ID is required']);
    exit;
}

if (!$sendTo || !filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Valid email address is required']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // Fetch invoice along with customer and company details
    $stmt = $DB->prepare(
        "SELECT i.*,
                c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                c.website, c.phone AS company_phone, c.email AS company_email,
                c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                c.bank_name, c.bank_account_number, c.bank_branch_code,
                c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                c.qi_table_header_text, c.qi_bg_color, c.qi_font_family, c.qi_logo_size,
                c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number,
                c.qi_show_reg_number, c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                c.invoice_footer_text, c.quote_footer_text,
                ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                p.name AS project_name
         FROM invoices i
         LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
         LEFT JOIN companies c ON i.company_id = c.id
         LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
         LEFT JOIN projects p ON i.project_id = p.project_id
         WHERE i.id = ? AND i.company_id = ?"
    );
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    // Issue the invoice: draft -> sent AND post to the general ledger (a sent
    // invoice is a tax invoice and must be in the GL/VAT201). Throws — and
    // rolls the whole send back — if posting fails, so an invoice can never
    // be sent to a customer without its ledger entry.
    require_once __DIR__ . '/../lib/InvoiceLifecycle.php';
    InvoiceLifecycle::issueInvoice($DB, $companyId, $userId, $invoiceId);

    // Always re-render the branded PDF AFTER issuing so the emailed document
    // (and the copy in FlowWork Drive) carries the issued status — a PDF
    // rendered at save time still says "Draft".
    require_once __DIR__ . '/../services/DocumentPdfService.php';
    $pdfInfo = DocumentPdfService::render($DB, $companyId, 'invoice', (int)$invoiceId);
    if ($pdfInfo === null) {
        throw new Exception('Could not generate the invoice PDF');
    }
    $invoice['pdf_path'] = $pdfInfo['rel_path'];

    // Compose the email subject and body using company and invoice details
    $subject = 'Invoice ' . $invoice['invoice_number'] . ' from ' . ($invoice['company_name'] ?? '');
    // Build a simple HTML body. You can customize this template as needed.
    $customerName = $invoice['customer_name'] ?: '';
    $companyName  = $invoice['company_name'] ?: '';
    $htmlBody = '<p>Dear ' . htmlspecialchars($customerName) . ',</p>';
    $htmlBody .= '<p>Please find attached your invoice <strong>' . htmlspecialchars($invoice['invoice_number']) . '</strong> from ' . htmlspecialchars($companyName) . '.</p>';
    $htmlBody .= '<p>Thank you for your business.</p>';
    $textBody = 'Dear ' . $customerName . ",\n\n";
    $textBody .= 'Please find attached your invoice ' . $invoice['invoice_number'] . ' from ' . $companyName . ".\n\n";
    $textBody .= 'Thank you for your business.';

    // Use the Mailer service to send the invoice and record logs
    require_once __DIR__ . '/../services/Mailer.php';
    $mailer = new Mailer($DB);
    // The sendDocument method will handle inserting into qi_email_log and email_links as well
    $mailer->sendDocument($companyId, $userId, 'invoice', $invoiceId, $sendTo, $subject, $htmlBody, $textBody, $invoice['pdf_path']);

    $DB->commit();

    // Publish the issued PDF to FlowWork Drive under the customer's folder.
    DocumentPdfService::fileToDrive($DB, $companyId, 'invoice', $pdfInfo, $userId);

    echo json_encode([
        'ok' => true,
        'message' => 'Invoice sent successfully',
        'recipient' => $sendTo,
        'invoice_number' => $invoice['invoice_number'],
        'pdf_path' => $invoice['pdf_path']
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    // The PDF file on disk is written non-transactionally during the send; a
    // rollback (mail/commit failure) would leave a file claiming the invoice
    // was issued while the row is back to draft. Re-render best-effort so the
    // stored file always matches the actual (rolled-back) state.
    if (!empty($invoiceId)) {
        try {
            require_once __DIR__ . '/../services/DocumentPdfService.php';
            DocumentPdfService::render($DB, (int)$companyId, 'invoice', (int)$invoiceId);
        } catch (Throwable $reErr) {
            error_log('Send invoice rollback re-render failed: ' . $reErr->getMessage());
        }
    }
    error_log("Send invoice error: " . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Failed to send invoice' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
}