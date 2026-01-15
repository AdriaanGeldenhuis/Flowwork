<?php
// /qi/ajax/send_invoice.php - COMPLETE WORKING VERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

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
    $stmt = $DB->prepare("\n        SELECT i.*,\n               ca.name AS customer_name,\n               ca.email AS customer_email,\n               c.name AS company_name,\n               c.email AS company_email\n        FROM invoices i\n        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id\n        LEFT JOIN companies c ON i.company_id = c.id\n        WHERE i.id = ? AND i.company_id = ?\n    ");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    // Ensure pdf_path exists; if not, generate a real PDF now using the simple generator (Section 7)
    $pdfPath = $invoice['pdf_path'] ?? null;
    if (empty($pdfPath)) {
        require_once __DIR__ . '/../../includes/pdf/qi_pdf.php';
        // Fetch invoice line items
        $stmtLines = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
        $stmtLines->execute([$invoiceId]);
        $lineRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        // Build content lines similar to generate_pdf.php
        $contentLines = [];
        $contentLines[] = 'Invoice: ' . $invoice['invoice_number'];
        $contentLines[] = 'Issue Date: ' . date('Y-m-d', strtotime($invoice['issue_date']));
        $contentLines[] = 'Due Date: ' . date('Y-m-d', strtotime($invoice['due_date']));
        $contentLines[] = 'Company: ' . $invoice['company_name'];
        $contentLines[] = 'Customer: ' . $invoice['customer_name'];
        $contentLines[] = ' ';
        $contentLines[] = 'Description | Qty | Unit Price | Line Total';
        foreach ($lineRows as $li) {
            $contentLines[] = $li['item_description'] . ' | ' . (float)$li['quantity'] . ' | ' . number_format((float)$li['unit_price'], 2) . ' | ' . number_format((float)$li['line_total'], 2);
        }
        $contentLines[] = ' ';
        $contentLines[] = 'Subtotal: ' . number_format((float)$invoice['subtotal'], 2);
        if ((float)$invoice['discount'] > 0) {
            $contentLines[] = 'Discount: ' . number_format((float)$invoice['discount'], 2);
        }
        $contentLines[] = 'VAT (15%): ' . number_format((float)$invoice['tax'], 2);
        $contentLines[] = 'Total: ' . number_format((float)$invoice['total'], 2);
        $contentLines[] = 'Balance Due: ' . number_format((float)$invoice['balance_due'], 2);
        // Determine file path
        $safeCode = preg_replace('~[^A-Za-z0-9_-]~', '_', $invoice['invoice_number']);
        $baseDir = __DIR__ . '/../../storage/qi/' . $companyId . '/invoice';
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        $absPath = $baseDir . '/' . $safeCode . '.pdf';
        $relPath = '/storage/qi/' . $companyId . '/invoice/' . $safeCode . '.pdf';
        // Generate PDF
        qi_generate_simple_pdf($contentLines, $absPath);
        // Update invoice record
        $upd = $DB->prepare("UPDATE invoices SET pdf_path = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
        $upd->execute([$relPath, $invoiceId, $companyId]);
        $invoice['pdf_path'] = $relPath;
    }

    // Update status to sent if currently draft
    $stmt = $DB->prepare("\n        UPDATE invoices \n        SET status = CASE\n            WHEN status = 'draft' THEN 'sent'\n            ELSE status\n        END,\n        updated_at = NOW() \n        WHERE id = ? AND company_id = ?\n    ");
    $stmt->execute([$invoiceId, $companyId]);

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

    echo json_encode([
        'ok' => true,
        'message' => 'Invoice sent successfully',
        'recipient' => $sendTo,
        'invoice_number' => $invoice['invoice_number'],
        'pdf_path' => $invoice['pdf_path']
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Send invoice error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}