<?php
// /qi/ajax/send_quote.php - COMPLETE WORKING VERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

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
               ca.name  AS customer_name,
               ca.email AS customer_email,
               c.name   AS company_name,
               c.email  AS company_email
        FROM quotes q
        LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
        LEFT JOIN companies c     ON q.company_id = c.id
        WHERE q.id = ? AND q.company_id = ?"
    );
    $stmt->execute([$quoteId, $companyId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        throw new Exception('Quote not found');
    }

    // Ensure pdf_path exists; if not, generate a simple PDF now (Section 7)
    $pdfPath = $quote['pdf_path'] ?? null;
    if (empty($pdfPath)) {
        require_once __DIR__ . '/../../includes/pdf/qi_pdf.php';
        // Fetch quote line items
        $stmtLines = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmtLines->execute([$quoteId]);
        $lineRows = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        // Build content lines similar to generate_pdf.php
        $contentLines = [];
        $contentLines[] = 'Quote: ' . $quote['quote_number'];
        $contentLines[] = 'Date Issued: ' . date('Y-m-d', strtotime($quote['issue_date']));
        $contentLines[] = 'Expires: ' . date('Y-m-d', strtotime($quote['expiry_date']));
        $contentLines[] = 'Company: ' . $quote['company_name'];
        $contentLines[] = 'Customer: ' . $quote['customer_name'];
        $contentLines[] = ' ';
        $contentLines[] = 'Description | Qty | Unit Price | Line Total';
        foreach ($lineRows as $li) {
            $contentLines[] = $li['item_description'] . ' | ' . (float)$li['quantity'] . ' | ' . number_format((float)$li['unit_price'], 2) . ' | ' . number_format((float)$li['line_total'], 2);
        }
        $contentLines[] = ' ';
        $contentLines[] = 'Subtotal: ' . number_format((float)$quote['subtotal'], 2);
        if ((float)$quote['discount'] > 0) {
            $contentLines[] = 'Discount: ' . number_format((float)$quote['discount'], 2);
        }
        $contentLines[] = 'VAT (15%): ' . number_format((float)$quote['tax'], 2);
        $contentLines[] = 'Total: ' . number_format((float)$quote['total'], 2);
        // Determine file paths
        $safeCode = preg_replace('~[^A-Za-z0-9_-]~', '_', $quote['quote_number']);
        $dir      = __DIR__ . '/../../storage/qi/' . $companyId . '/quote';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $absPath = $dir . '/' . $safeCode . '.pdf';
        $relPath = '/storage/qi/' . $companyId . '/quote/' . $safeCode . '.pdf';
        // Generate PDF
        qi_generate_simple_pdf($contentLines, $absPath);
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