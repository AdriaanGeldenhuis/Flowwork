<?php
// /qi/ajax/save_quote.php - COMPLETE WITH UPDATE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

// Basic validation
if (empty($input['customer_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Customer is required']);
    exit;
}

// Validate line items exist and compute totals
$lineItems = $input['line_items'] ?? [];
if (empty($lineItems) || !is_array($lineItems)) {
    echo json_encode(['ok' => false, 'error' => 'At least one line item is required']);
    exit;
}

// Recalculate subtotal, tax and total to prevent tampering
$subtotalCalc = 0;
foreach ($lineItems as $item) {
    $qty = isset($item['quantity']) ? floatval($item['quantity']) : 0;
    $price = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
    $lineTotal = $qty * $price;
    $subtotalCalc += $lineTotal;
}
$taxCalc = $subtotalCalc * 0.15;
$totalCalc = $subtotalCalc + $taxCalc;

// Override incoming totals with calculated values
$input['subtotal'] = $subtotalCalc;
$input['tax'] = $taxCalc;
$input['total'] = $totalCalc;

try {
    $DB->beginTransaction();
    
    $editMode = !empty($input['edit_mode']) && !empty($input['quote_id']);
    
    if ($editMode) {
        // UPDATE
        $quoteId = (int)$input['quote_id'];
        
        $stmt = $DB->prepare("SELECT status FROM quotes WHERE id = ? AND company_id = ?");
        $stmt->execute([$quoteId, $companyId]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            throw new Exception('Quote not found');
        }
        
        if ($existing['status'] !== 'draft') {
            throw new Exception('Only draft quotes can be edited');
        }
        
        $stmt = $DB->prepare("
            UPDATE quotes 
            SET customer_id = ?,
                contact_id = ?,
                project_id = ?,
                issue_date = ?,
                expiry_date = ?,
                subtotal = ?,
                discount = ?,
                tax = ?,
                total = ?,
                terms = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        
        $stmt->execute([
            $input['customer_id'],
            $input['contact_id'] ?? null,
            $input['project_id'] ?? null,
            $input['issue_date'],
            $input['expiry_date'],
            $input['subtotal'],
            $input['discount'] ?? 0,
            $input['tax'],
            $input['total'],
            $input['terms'] ?? '',
            $input['notes'] ?? '',
            $quoteId,
            $companyId
        ]);
        
        $stmt = $DB->prepare("DELETE FROM quote_lines WHERE quote_id = ?");
        $stmt->execute([$quoteId]);
        
        $message = 'Quote updated successfully';
        
    } else {
        // CREATE
        // Allocate a unique quote number in a race-safe way using qi_sequences
        $year = date('Y');
        // Lock the sequence row for this company/year/type
        $stmt = $DB->prepare("SELECT next_number FROM qi_sequences WHERE company_id = ? AND type = 'quote' AND year = ? FOR UPDATE");
        $stmt->execute([$companyId, $year]);
        $seq = $stmt->fetch();

        if ($seq === false) {
            // If no sequence row exists, create it starting at 0
            $stmtInsert = $DB->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, 'quote', ?, 0)");
            $stmtInsert->execute([$companyId, $year]);
            $nextNum = 1;
        } else {
            $nextNum = intval($seq['next_number']) + 1;
        }

        // Update the sequence with the new number
        $stmtUpdate = $DB->prepare("UPDATE qi_sequences SET next_number = ? WHERE company_id = ? AND type = 'quote' AND year = ?");
        $stmtUpdate->execute([$nextNum, $companyId, $year]);

        // Build the quote number with year prefix and padded sequence
        $quoteNumber = sprintf('Q%d-%04d', $year, $nextNum);

        // Generate public token for this quote
        $publicToken = bin2hex(random_bytes(16));
        // Insert the quote header including public token
        $stmt = $DB->prepare("
            INSERT INTO quotes (
                company_id, quote_number, public_token, customer_id, contact_id, project_id,
                issue_date, expiry_date, status, subtotal, discount, tax, total,
                currency, terms, notes, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, 'ZAR', ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $companyId,
            $quoteNumber,
            $publicToken,
            $input['customer_id'],
            $input['contact_id'] ?? null,
            $input['project_id'] ?? null,
            $input['issue_date'],
            $input['expiry_date'],
            $input['subtotal'],
            $input['discount'] ?? 0,
            $input['tax'],
            $input['total'],
            $input['terms'] ?? '',
            $input['notes'] ?? '',
            $userId
        ]);

        $quoteId = $DB->lastInsertId();
        $message = 'Quote created successfully';
    }
    
    // Insert line items
    if (!empty($input['line_items'])) {
        $stmt = $DB->prepare("
            INSERT INTO quote_lines (
                quote_id, item_description, quantity, unit_price, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($input['line_items'] as $index => $item) {
            $stmt->execute([
                $quoteId,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['line_total'],
                $index
            ]);
        }
    }
    
    $DB->commit();
    
    echo json_encode([
        'ok' => true,
        'message' => $message,
        'quote_id' => $quoteId
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Save quote error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// After the quote has been saved (either created or updated) and committed, hook into the calendar to create/update expiry events.
if (isset($quoteId) && isset($companyId) && isset($userId) && $quoteId) {
    try {
        require_once __DIR__ . '/../../services/CalendarHook.php';
        $calendarHook = new CalendarHook($DB);
        // Fetch quote number and expiry date
        $stmtInfo = $DB->prepare("SELECT quote_number, expiry_date FROM quotes WHERE id = ? AND company_id = ?");
        $stmtInfo->execute([$quoteId, $companyId]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
        if ($info) {
            $calendarHook->handleQuoteEvent($companyId, $quoteId, $info['quote_number'], $info['expiry_date'], $userId);
        }
    } catch (Exception $chEx) {
        // Log errors silently; do not interrupt response
        error_log('Calendar hook for quote failed: ' . $chEx->getMessage());
    }
}