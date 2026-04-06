<?php
// /qi/ajax/convert_to_invoice.php - COMPLETE WORKING VERSION
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SequenceAllocator.php';

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
    
    // 2. Generate invoice number using SequenceAllocator (race-safe)
    $alloc = new SequenceAllocator($DB);
    [$invoiceNumber, $seqNum] = $alloc->allocate($companyId, 'invoice', null, true);

    // 3. Determine due date from qi_settings payment terms
    $stmtTerms = $DB->prepare("SELECT default_payment_terms FROM qi_settings WHERE company_id = ?");
    $stmtTerms->execute([$companyId]);
    $termsRow = $stmtTerms->fetch();
    $paymentDays = ($termsRow && $termsRow['default_payment_terms']) ? (int)$termsRow['default_payment_terms'] : 30;
    $dueDate = date('Y-m-d', strtotime("+{$paymentDays} days"));

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
    
    // 6. Copy payment milestones if present
    if (!empty($quote['has_milestones'])) {
        $stmt = $DB->prepare("SELECT * FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ? ORDER BY sort_order");
        $stmt->execute([$quoteId, $companyId]);
        $quoteMilestones = $stmt->fetchAll();

        if (!empty($quoteMilestones)) {
            $msInsert = $DB->prepare("INSERT INTO payment_milestones (entity_type, entity_id, company_id, label, percentage, amount, due_date, status, sort_order) VALUES ('invoice', ?, ?, ?, ?, ?, ?, 'pending', ?)");
            foreach ($quoteMilestones as $ms) {
                // Recalculate amount based on invoice total (same as quote total)
                $msAmount = round($quote['total'] * ($ms['percentage'] / 100), 2);
                $msInsert->execute([
                    $invoiceId,
                    $companyId,
                    $ms['label'],
                    $ms['percentage'],
                    $msAmount,
                    $ms['due_date'],
                    $ms['sort_order']
                ]);
            }
            // Set has_milestones flag on the invoice
            $stmt = $DB->prepare("UPDATE invoices SET has_milestones = 1 WHERE id = ?");
            $stmt->execute([$invoiceId]);
        }
    }

    // 7. Update quote status to converted
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
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}