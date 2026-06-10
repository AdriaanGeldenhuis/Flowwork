<?php
// /qi/ajax/duplicate_quote.php - COMPLETE
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SequenceAllocator.php';

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
    
    // Generate new quote number using SequenceAllocator (race-safe)
    $alloc = new SequenceAllocator($DB);
    [$newQuoteNumber, $seqNum] = $alloc->allocate($companyId, 'quote', null, true);
    
    // Create duplicate quote header including new public token
    $insertHdr = $DB->prepare("INSERT INTO quotes (
            company_id, quote_number, public_token, customer_id, contact_id, project_id,
            issue_date, expiry_date, status, subtotal, discount, tax, total,
            currency, exchange_rate, terms, notes, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
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
        $original['exchange_rate'] ?? 1,
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
        $calendarHookPath = __DIR__ . '/../services/CalendarHook.php';
        if (file_exists($calendarHookPath)) {
            require_once $calendarHookPath;
            $calendarHook = new CalendarHook($DB);
            $calendarHook->handleQuoteEvent($companyId, (int)$newQuoteId, $newQuoteNumber, $newExpiryDate, $userId);
        }
    } catch (Throwable $chEx) {
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
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}