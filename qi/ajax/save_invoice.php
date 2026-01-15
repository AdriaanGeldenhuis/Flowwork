<?php
// /qi/ajax/save_invoice.php - COMPLETE WITH UPDATE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
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
    
    $editMode = !empty($input['edit_mode']) && !empty($input['invoice_id']);
    
    if ($editMode) {
        // UPDATE
        $invoiceId = (int)$input['invoice_id'];
        
        $stmt = $DB->prepare("SELECT status FROM invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([$invoiceId, $companyId]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            throw new Exception('Invoice not found');
        }
        
        if ($existing['status'] !== 'draft') {
            throw new Exception('Only draft invoices can be edited');
        }
        
        $stmt = $DB->prepare("
            UPDATE invoices 
            SET customer_id = ?,
                contact_id = ?,
                project_id = ?,
                issue_date = ?,
                due_date = ?,
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
            $input['due_date'],
            $input['subtotal'],
            $input['discount'] ?? 0,
            $input['tax'],
            $input['total'],
            $input['terms'] ?? '',
            $input['notes'] ?? '',
            $invoiceId,
            $companyId
        ]);
        
        $stmt = $DB->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?");
        $stmt->execute([$invoiceId]);
        
        $message = 'Invoice updated successfully';
        
    } else {
        // CREATE
        // Allocate a unique invoice number in a race-safe way using qi_sequences
        $year = date('Y');
        // Lock the sequence row for this company/year/type
        $stmt = $DB->prepare("SELECT next_number FROM qi_sequences WHERE company_id = ? AND type = 'invoice' AND year = ? FOR UPDATE");
        $stmt->execute([$companyId, $year]);
        $seq = $stmt->fetch();

        if ($seq === false) {
            // No sequence row exists: insert starting at 0
            $stmtInsert = $DB->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, 'invoice', ?, 0)");
            $stmtInsert->execute([$companyId, $year]);
            $nextNum = 1;
        } else {
            $nextNum = intval($seq['next_number']) + 1;
        }

        // Update sequence
        $stmtUpdate = $DB->prepare("UPDATE qi_sequences SET next_number = ? WHERE company_id = ? AND type = 'invoice' AND year = ?");
        $stmtUpdate->execute([$nextNum, $companyId, $year]);

        // Build invoice number: include year and zero-pad
        $invoiceNumber = sprintf('INV%d-%04d', $year, $nextNum);

        // Insert new invoice; balance_due initially equals total
        $stmt = $DB->prepare("\n            INSERT INTO invoices (\n                company_id, invoice_number, customer_id, contact_id, project_id,\n                issue_date, due_date, status, subtotal, discount, tax, total, balance_due,\n                currency, terms, notes, created_by, created_at, updated_at\n            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, 'ZAR', ?, ?, ?, NOW(), NOW())\n        ");

        $stmt->execute([
            $companyId,
            $invoiceNumber,
            $input['customer_id'],
            $input['contact_id'] ?? null,
            $input['project_id'] ?? null,
            $input['issue_date'],
            $input['due_date'],
            $input['subtotal'],
            $input['discount'] ?? 0,
            $input['tax'],
            $input['total'],
            // balance_due
            $input['total'],
            $input['terms'] ?? '',
            $input['notes'] ?? '',
            $userId
        ]);

        $invoiceId = $DB->lastInsertId();
        $message = 'Invoice created successfully';
    }
    
    // Insert line items
    if (!empty($input['line_items'])) {
        $stmt = $DB->prepare(
            "INSERT INTO invoice_lines (
                invoice_id, item_description, quantity, unit_price, line_total, sort_order, inventory_item_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($input['line_items'] as $index => $item) {
            $invId = null;
            if (isset($item['inventory_item_id']) && $item['inventory_item_id'] !== '' && $item['inventory_item_id'] !== null) {
                $invId = $item['inventory_item_id'];
            }
            $stmt->execute([
                $invoiceId,
                $item['description'] ?? '',
                $item['quantity'] ?? 0,
                $item['unit_price'] ?? 0,
                $item['line_total'] ?? 0,
                $index,
                $invId
            ]);
        }
    }
    
    $DB->commit();

    // After invoice is saved, post journal entry to general ledger (Section 11).
    try {
        // JournalPoster is located under qi/services; relative to this file go two levels up
        require_once __DIR__ . '/../../services/JournalPoster.php';
        $poster = new JournalPoster($DB, $companyId, $userId);
        $poster->postInvoice($invoiceId);
    } catch (Exception $e) {
        // Log but do not interrupt response
        error_log('Invoice journal posting failed: ' . $e->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'message' => $message,
        'invoice_id' => $invoiceId
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Save invoice error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// After the invoice has been saved and committed, hook into the calendar to create/update due events.
if (isset($invoiceId) && isset($companyId) && isset($userId) && $invoiceId) {
    try {
        require_once __DIR__ . '/../../services/CalendarHook.php';
        $calendarHook = new CalendarHook($DB);
        // Fetch invoice number and due date
        $stmtInfo = $DB->prepare("SELECT invoice_number, due_date FROM invoices WHERE id = ? AND company_id = ?");
        $stmtInfo->execute([$invoiceId, $companyId]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
        if ($info) {
            $calendarHook->handleInvoiceEvent($companyId, $invoiceId, $info['invoice_number'], $info['due_date'], $userId);
        }
    } catch (Exception $chEx) {
        error_log('Calendar hook for invoice failed: ' . $chEx->getMessage());
    }
}