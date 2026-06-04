<?php
// /qi/ajax/send_quote.php - COMPLETE WORKING VERSION
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Branding.php';

header('Content-Type: application/json');


$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Get JSON input

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input format']);
    exit;
}

$quoteId = isset($input['quote_id']) ? (int)$input['quote_id'] : 0;
$sendTo = isset($input['send_to']) ? trim($input['send_to']) : '';

if (!$quoteId) {
    echo json_encode(['ok' => false, 'error' => 'Quote ID is required']);
    exit;
}

if (!$sendTo || !filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Valid email address is required']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // Get quote details along with customer and company info
    $stmt = $DB->prepare(
        "SELECT q.*,
                c.name AS company_name, c.logo_url, c.vat_number, c.tax_number, c.reg_number,
                c.website, c.phone AS company_phone, c.email AS company_email,
                c.address_line1 AS company_address1, c.address_line2 AS company_address2,
                c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                c.bank_name, c.bank_account_number, c.bank_branch_code,
                c.primary_color, c.secondary_color, c.qi_heading_color, c.qi_text_color,
                c.qi_table_header_text, c.qi_bg_color, c.qi_font_family,
                c.qi_show_company_address, c.qi_show_company_phone, c.qi_show_company_email,
                c.qi_show_company_website, c.qi_show_vat_number, c.qi_show_tax_number,
                c.qi_show_reg_number, c.qi_show_payment_details, c.qi_quote_title, c.qi_invoice_title,
                c.invoice_footer_text, c.quote_footer_text,
                ca.name AS customer_name, ca.email AS customer_email, ca.phone AS customer_phone,
                ca.vat_no AS customer_vat, ca.reg_no AS customer_reg,
                addr.line1 AS customer_address1, addr.line2 AS customer_address2,
                addr.city AS customer_city, addr.region AS customer_region, addr.postal_code AS customer_postal,
                p.name AS project_name
         FROM quotes q
         LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
         LEFT JOIN companies c ON q.company_id = c.id
         LEFT JOIN crm_addresses addr ON addr.account_id = ca.id AND addr.id = (SELECT a2.id FROM crm_addresses a2 WHERE a2.account_id = ca.id ORDER BY FIELD(a2.type, 'billing', 'head_office', 'shipping', 'site') LIMIT 1)
         LEFT JOIN projects p ON q.project_id = p.project_id
         WHERE q.id = ? AND q.company_id = ?"
    );
    $stmt->execute([$quoteId, $companyId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        throw new Exception('Quote not found');
    }

    // Ensure pdf_path exists; if not, generate the branded PDF now so the
    // emailed document matches the on-screen and downloaded versions.
    $pdfPath = $quote['pdf_path'] ?? null;
    if (empty($pdfPath)) {
        require_once __DIR__ . '/../../includes/pdf/qi_styled_pdf.php';

        // Fetch quote line items
        $stmtLines = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmtLines->execute([$quoteId]);
        $lineRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        // Branding + document meta (same shape the download endpoint uses)
        $brand = Branding::resolve($quote, 'quote');
        $quote['_doc_type']    = $brand['title'];
        $quote['_doc_title']   = 'Quote #: ' . $quote['quote_number'];
        $quote['_footer_text'] = $brand['footer'];
        $quote['_dates'] = [
            'Issue Date' => date('d M Y', strtotime($quote['issue_date'])),
            'Expires'    => date('d M Y', strtotime($quote['expiry_date'])),
            'Status'     => ucfirst($quote['status']),
        ];

        // Determine file paths
        $safeCode = preg_replace('~[^A-Za-z0-9_-]~', '_', $quote['quote_number']);
        $dir      = __DIR__ . '/../../storage/qi/' . $companyId . '/quote';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $absPath = $dir . '/' . $safeCode . '.pdf';
        $relPath = '/storage/qi/' . $companyId . '/quote/' . $safeCode . '.pdf';

        // Generate branded PDF
        $pdf = new QiStyledPdfWriter($quote, $lineRows);
        file_put_contents($absPath, $pdf->render());

        // Update record
        $upd = $DB->prepare("UPDATE quotes SET pdf_path = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
        $upd->execute([$relPath, $quoteId, $companyId]);
        $quote['pdf_path'] = $relPath;
    }

    // Update status to sent if currently draft
    $stmt = $DB->prepare(
        "UPDATE quotes 
         SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END,
             updated_at = NOW() 
         WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$quoteId, $companyId]);

    // Compose the email subject and body for the quote
    $subject = 'Quote ' . $quote['quote_number'] . ' from ' . ($quote['company_name'] ?? '');
    $customerName = $quote['customer_name'] ?: '';
    $companyName  = $quote['company_name'] ?: '';
    $htmlBody  = '<p>Dear ' . htmlspecialchars($customerName) . ',</p>';
    $htmlBody .= '<p>Please find attached your quote <strong>' . htmlspecialchars($quote['quote_number']) . '</strong> from ' . htmlspecialchars($companyName) . '.</p>';
    $htmlBody .= '<p>We look forward to working with you.</p>';
    $textBody  = 'Dear ' . $customerName . ",\n\n";
    $textBody .= 'Please find attached your quote ' . $quote['quote_number'] . ' from ' . $companyName . ".\n\n";
    $textBody .= 'We look forward to working with you.';

    // Use the Mailer service to send the quote and record logs
    require_once __DIR__ . '/../services/Mailer.php';
    $mailer = new Mailer($DB);
    $mailer->sendDocument($companyId, $userId, 'quote', $quoteId, $sendTo, $subject, $htmlBody, $textBody, $quote['pdf_path']);

    $DB->commit();

    echo json_encode([
        'ok'           => true,
        'message'      => 'Quote sent successfully',
        'recipient'    => $sendTo,
        'quote_number' => $quote['quote_number'],
        'pdf_path'     => $quote['pdf_path']
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log('Send quote error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}