<?php
// /qi/ajax/duplicate_quote.php - COMPLETE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$quoteId = filter_var($input['quote_id'] ?? 0, FILTER_VALIDATE_INT);

if (!$quoteId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid quote ID']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // Get original quote
    $stmt = $DB->prepare("SELECT * FROM quotes WHERE id = ? AND company_id = ?");
    $stmt->execute([$quoteId, $companyId]);
    $original = $stmt->fetch();
    
    if (!$original) {
        throw new Exception('Quote not found');
    }
    
    // Generate new quote number using qi_sequences table (race-proof)
    $year = date('Y');
    // Lock sequence row for quotes for this company and year
    $seqStmt = $DB->prepare("SELECT next_number FROM qi_sequences WHERE company_id = ? AND type = 'quote' AND year = ? FOR UPDATE");
    $seqStmt->execute([$companyId, $year]);
    $seq = $seqStmt->fetch();
    if ($seq === false) {
        // No sequence row yet; insert row starting at 0
        $insertSeq = $DB->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, 'quote', ?, 0)");
        $insertSeq->execute([$companyId, $year]);
        $nextNum = 1;
    } else {
        $nextNum = intval($seq['next_number']) + 1;
    }
    // Update sequence row
    $updateSeq = $DB->prepare("UPDATE qi_sequences SET next_number = ? WHERE company_id = ? AND type = 'quote' AND year = ?");
    $updateSeq->execute([$nextNum, $companyId, $year]);
    // Build new quote number with year prefix and padded sequence
    $newQuoteNumber = sprintf('Q%d-%04d', $year, $nextNum);
    
    // Create duplicate quote header including new public token
    $insertHdr = $DB->prepare("INSERT INTO quotes (
            company_id, quote_number, public_token, customer_id, contact_id, project_id,
            issue_date, expiry_date, status, subtotal, discount, tax, total,
            currency, terms, notes, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    // Generate a new public token for the duplicate
    $publicToken = bin2hex(random_bytes(16));
    // Set new issue date and expiry date
    $newIssueDate = date('Y-m-d');
    $newExpiryDate = date('Y-m-d', strtotime('+14 days'));
    $insertHdr->execute([
        $companyId,
        $newQuoteNumber,
        $publicToken,
        $original['customer_id'],
        $original['contact_id'],
        $original['project_id'],
        $newIssueDate,
        $newExpiryDate,
        $original['subtotal'],
        $original['discount'],
        $original['tax'],
        $original['total'],
        $original['currency'],
        $original['terms'],
        $original['notes'],
        $userId
    ]);
    $newQuoteId = $DB->lastInsertId();
    
    // Copy line items
    $stmt = $DB->prepare("SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort_order");
    $stmt->execute([$quoteId]);
    $lines = $stmt->fetchAll();
    
    $insertLine = $DB->prepare("
        INSERT INTO quote_lines (
            quote_id, item_description, quantity, unit_price, line_total, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($lines as $line) {
        $insertLine->execute([
            $newQuoteId,
            $line['item_description'],
            $line['quantity'],
            $line['unit_price'],
            $line['line_total'],
            $line['sort_order']
        ]);
    }
    
    $DB->commit();

    // Create calendar event for the new duplicate quote's expiry
    try {
        require_once __DIR__ . '/../../services/CalendarHook.php';
        $calendarHook = new CalendarHook($DB);
        $calendarHook->handleQuoteEvent($companyId, (int)$newQuoteId, $newQuoteNumber, $newExpiryDate, $userId);
    } catch (Exception $chEx) {
        error_log('Calendar hook (duplicate quote) failed: ' . $chEx->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'quote_id' => $newQuoteId,
        'quote_number' => $newQuoteNumber,
        'message' => 'Quote duplicated successfully!'
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Duplicate quote error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}