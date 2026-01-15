<?php
// /qi/ajax/convert_to_invoice.php - COMPLETE WORKING VERSION
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$quoteId = filter_var($input['quote_id'] ?? 0, FILTER_VALIDATE_INT);

if (!$quoteId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid quote ID']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // 1. Get quote details
    $stmt = $DB->prepare("
        SELECT * FROM quotes 
        WHERE id = ? AND company_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$quoteId, $companyId]);
    $quote = $stmt->fetch();
    
    if (!$quote) {
        throw new Exception('Quote not found or not accepted');
    }
    
    // 2. Generate invoice number using qi_sequences for race-safe allocation
    $year = date('Y');
    // Lock the sequence row for invoices for this company and year
    $seqStmt = $DB->prepare("SELECT next_number FROM qi_sequences WHERE company_id = ? AND type = 'invoice' AND year = ? FOR UPDATE");
    $seqStmt->execute([$companyId, $year]);
    $seq = $seqStmt->fetch();
    
    if ($seq === false) {
        // No sequence row exists yet: create one starting at 0
        $insertSeq = $DB->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, 'invoice', ?, 0)");
        $insertSeq->execute([$companyId, $year]);
        $nextNum = 1;
    } else {
        $nextNum = intval($seq['next_number']) + 1;
    }
    // Update the sequence with new number
    $updateSeq = $DB->prepare("UPDATE qi_sequences SET next_number = ? WHERE company_id = ? AND type = 'invoice' AND year = ?");
    $updateSeq->execute([$nextNum, $companyId, $year]);
    // Build invoice number with year prefix and zero-padded sequence
    $invoiceNumber = sprintf('INV%d-%04d', $year, $nextNum);

    // 3. Determine due date (company terms). Default: 30 days from today
    $dueDate = date('Y-m-d', strtotime('+30 days'));

    // 4. Create invoice record and link to quote
    $stmt = $DB->prepare("INSERT INTO invoices (
            company_id,
            invoice_number,
            quote_id,
            customer_id,
            contact_id,
            project_id,
            issue_date,
            due_date,
            status,
            subtotal,
            discount,
            tax,
            total,
            balance_due,
            currency,
            terms,
            notes,
            created_by,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    $stmt->execute([
        $companyId,
        $invoiceNumber,
        $quoteId,
        $quote['customer_id'],
        $quote['contact_id'],
        $quote['project_id'],
        date('Y-m-d'),
        $dueDate,
        $quote['subtotal'],
        $quote['discount'],
        $quote['tax'],
        $quote['total'],
        // balance_due initially equals total
        $quote['total'],
        $quote['currency'],
        $quote['terms'],
        $quote['notes'],
        $userId
    ]);

    $invoiceId = $DB->lastInsertId();

    // 5. Copy line items
    $stmt = $DB->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
    $stmt->execute([$quoteId]);
    $quoteLines = $stmt->fetchAll();
    
    $insertLine = $DB->prepare("
        INSERT INTO invoice_lines (
            invoice_id,
            item_description,
            quantity,
            unit_price,
            line_total,
            sort_order
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($quoteLines as $line) {
        $insertLine->execute([
            $invoiceId,
            $line['item_description'],
            $line['quantity'],
            $line['unit_price'],
            $line['line_total'],
            $line['sort_order']
        ]);
    }
    
    // 6. Update quote status to converted
    $stmt = $DB->prepare("UPDATE quotes SET status = 'converted', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$quoteId]);
    
    $DB->commit();

    // After creating the invoice, create/update the calendar due event
    try {
        require_once __DIR__ . '/../../services/CalendarHook.php';
        $calendarHook = new CalendarHook($DB);
        $calendarHook->handleInvoiceEvent($companyId, (int)$invoiceId, $invoiceNumber, $dueDate, $userId);
    } catch (Exception $chEx) {
        error_log('Calendar hook (convert) for invoice failed: ' . $chEx->getMessage());
    }

    // Post journal entry for newly created invoice (Section 11)
    try {
        require_once __DIR__ . '/../../services/JournalPoster.php';
        $poster = new JournalPoster($DB, $companyId, $userId);
        $poster->postInvoice((int)$invoiceId);
    } catch (Exception $e) {
        error_log('Invoice journal posting (convert) failed: ' . $e->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'message' => 'Quote converted to invoice successfully!'
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Quote conversion error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}