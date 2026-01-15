<?php
// /qi/ajax/generate_pdf.php – Generate a real PDF for quotes or invoices
// Uses a simple PDF generator to create a text-based PDF. The PDF is stored
// on disk under /storage/qi/{company_id}/{type}/{code}.pdf and served to the user.
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/pdf/qi_pdf.php';

$companyId = $_SESSION['company_id'];
$type      = $_GET['type'] ?? 'quote';
$id        = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}

try {
if ($type === 'quote') {
        // Load quote, company, customer and lines
        $stmt = $DB->prepare("\n            SELECT q.*, c.name AS company_name, c.address_line1, c.address_line2, c.city AS company_city, c.region AS company_region, c.postal AS company_postal,\n                   ca.name AS customer_name, ca.email AS customer_email\n            FROM quotes q\n            LEFT JOIN companies c ON q.company_id = c.id\n            LEFT JOIN crm_accounts ca ON q.customer_id = ca.id\n            WHERE q.id = ? AND q.company_id = ?\n        ");
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new Exception('Quote not found');
        }
        $docNumber = $doc['quote_number'];
        // Fetch line items
        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Prepare content lines for PDF
        $contentLines = [];
        $contentLines[] = 'Quote: ' . $doc['quote_number'];
        $contentLines[] = 'Date Issued: ' . date('Y-m-d', strtotime($doc['issue_date']));
        $contentLines[] = 'Expires: ' . date('Y-m-d', strtotime($doc['expiry_date']));
        $contentLines[] = 'Company: ' . $doc['company_name'];
        $contentLines[] = 'Customer: ' . $doc['customer_name'];
        $contentLines[] = ' '; // blank line
        $contentLines[] = 'Description | Qty | Unit Price | Line Total';
        foreach ($lines as $li) {
            $contentLines[] = $li['item_description'] . ' | ' . (float)$li['quantity'] . ' | ' . number_format((float)$li['unit_price'], 2) . ' | ' . number_format((float)$li['line_total'], 2);
        }
        $contentLines[] = ' '; // blank line
        $contentLines[] = 'Subtotal: ' . number_format((float)$doc['subtotal'], 2);
        if ((float)$doc['discount'] > 0) {
            $contentLines[] = 'Discount: ' . number_format((float)$doc['discount'], 2);
        }
        $contentLines[] = 'VAT (15%): ' . number_format((float)$doc['tax'], 2);
        $contentLines[] = 'Total: ' . number_format((float)$doc['total'], 2);
        // Determine file paths
        $safeCode = preg_replace('~[^A-Za-z0-9_-]~', '_', $docNumber);
        $dir = __DIR__ . '/../../storage/qi/' . $companyId . '/quote';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $absPath = $dir . '/' . $safeCode . '.pdf';
        $relPath = '/storage/qi/' . $companyId . '/quote/' . $safeCode . '.pdf';
        // Generate PDF if file does not exist
        if (!file_exists($absPath)) {
            qi_generate_simple_pdf($contentLines, $absPath);
            // Update pdf_path on quote record
            $upd = $DB->prepare("UPDATE quotes SET pdf_path = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $upd->execute([$relPath, $id, $companyId]);
        }
    } elseif ($type === 'credit_note') {
        // Credit note
        $stmt = $DB->prepare(
            "SELECT cn.*, c.name AS company_name, c.address_line1, c.address_line2, c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    ca.name AS customer_name, ca.email AS customer_email, i.invoice_number AS linked_invoice_number
             FROM credit_notes cn
             LEFT JOIN companies c ON cn.company_id = c.id
             LEFT JOIN crm_accounts ca ON cn.customer_id = ca.id
             LEFT JOIN invoices i ON cn.invoice_id = i.id
             WHERE cn.id = ? AND cn.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new Exception('Credit note not found');
        }
        $docNumber = $doc['credit_note_number'];
        // Fetch credit note lines
        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, discount, tax_rate, line_total FROM credit_note_lines WHERE credit_note_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Prepare content lines
        $contentLines = [];
        $contentLines[] = 'Credit Note: ' . $doc['credit_note_number'];
        $contentLines[] = 'Issue Date: ' . date('Y-m-d', strtotime($doc['issue_date']));
        if (!empty($doc['linked_invoice_number'])) {
            $contentLines[] = 'Invoice: ' . $doc['linked_invoice_number'];
        }
        $contentLines[] = 'Company: ' . $doc['company_name'];
        $contentLines[] = 'Customer: ' . $doc['customer_name'];
        $contentLines[] = ' ';
        $contentLines[] = 'Description | Qty | Unit Price | Discount | Tax Rate | Line Total';
        foreach ($lines as $li) {
            $contentLines[] =
                ($li['item_description'] ?? '') . ' | ' .
                (float)$li['quantity'] . ' | ' .
                number_format((float)$li['unit_price'], 2) . ' | ' .
                number_format((float)$li['discount'], 2) . ' | ' .
                number_format((float)$li['tax_rate'], 2) . '% | ' .
                number_format((float)$li['line_total'], 2);
        }
        $contentLines[] = ' ';
        $contentLines[] = 'Subtotal: ' . number_format((float)$doc['subtotal'], 2);
        $contentLines[] = 'VAT (15%): ' . number_format((float)$doc['tax'], 2);
        $contentLines[] = 'Total Credit: ' . number_format((float)$doc['total'], 2);
        // Determine file paths for credit note
        $safeCode = preg_replace('~[^A-Za-z0-9_-]~', '_', $docNumber);
        $dir = __DIR__ . '/../../storage/qi/' . $companyId . '/credit_note';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $absPath = $dir . '/' . $safeCode . '.pdf';
        $relPath = '/storage/qi/' . $companyId . '/credit_note/' . $safeCode . '.pdf';
        if (!file_exists($absPath)) {
            qi_generate_simple_pdf($contentLines, $absPath);
            // If pdf_path column exists in credit_notes, update it. Safe guard with try/catch
            try {
                $upd = $DB->prepare("UPDATE credit_notes SET pdf_path = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
                $upd->execute([$relPath, $id, $companyId]);
            } catch (Exception $e) {
                // Column may not exist; ignore
            }
        }
    } else {
        // Invoice
        $stmt = $DB->prepare(
            "SELECT i.*, c.name AS company_name, c.address_line1, c.address_line2, c.city AS company_city, c.region AS company_region, c.postal AS company_postal,
                    ca.name AS customer_name, ca.email AS customer_email
             FROM invoices i
             LEFT JOIN companies c ON i.company_id = c.id
             LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
             WHERE i.id = ? AND i.company_id = ?"
        );
        $stmt->execute([$id, $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new Exception('Invoice not found');
        }
        $docNumber = $doc['invoice_number'];
        // Fetch line items
        $stmt = $DB->prepare("SELECT item_description, quantity, unit_price, line_total FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Prepare content lines
        $contentLines = [];
        $contentLines[] = 'Invoice: ' . $doc['invoice_number'];
        $contentLines[] = 'Issue Date: ' . date('Y-m-d', strtotime($doc['issue_date']));
        $contentLines[] = 'Due Date: ' . date('Y-m-d', strtotime($doc['due_date']));
        $contentLines[] = 'Company: ' . $doc['company_name'];
        $contentLines[] = 'Customer: ' . $doc['customer_name'];
        $contentLines[] = ' ';
        $contentLines[] = 'Description | Qty | Unit Price | Line Total';
        foreach ($lines as $li) {
            $contentLines[] = $li['item_description'] . ' | ' . (float)$li['quantity'] . ' | ' . number_format((float)$li['unit_price'], 2) . ' | ' . number_format((float)$li['line_total'], 2);
        }
        $contentLines[] = ' ';
        $contentLines[] = 'Subtotal: ' . number_format((float)$doc['subtotal'], 2);
        if ((float)$doc['discount'] > 0) {
            $contentLines[] = 'Discount: ' . number_format((float)$doc['discount'], 2);
        }
        $contentLines[] = 'VAT (15%): ' . number_format((float)$doc['tax'], 2);
        $contentLines[] = 'Total: ' . number_format((float)$doc['total'], 2);
        $contentLines[] = 'Balance Due: ' . number_format((float)$doc['balance_due'], 2);
        // Determine file paths
        $safeCode = preg_replace('~[^A-Za-z0-9_-]~', '_', $docNumber);
        $dir = __DIR__ . '/../../storage/qi/' . $companyId . '/invoice';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $absPath = $dir . '/' . $safeCode . '.pdf';
        $relPath = '/storage/qi/' . $companyId . '/invoice/' . $safeCode . '.pdf';
        if (!file_exists($absPath)) {
            qi_generate_simple_pdf($contentLines, $absPath);
            $upd = $DB->prepare("UPDATE invoices SET pdf_path = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
            $upd->execute([$relPath, $id, $companyId]);
        }
    }
    // Serve the PDF file
    if (!isset($absPath) || !file_exists($absPath)) {
        throw new Exception('PDF generation failed');
    }
    header('Content-Type: application/pdf');
    // Force inline display but allow download
    $filename = basename($absPath);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
    exit;
}