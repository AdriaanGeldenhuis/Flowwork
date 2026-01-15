<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$fileId = (int)($_POST['file_id'] ?? 0);
$supplierId = (int)($_POST['supplier_id'] ?? 0);
$invoiceNumber = trim($_POST['invoice_number'] ?? '');
$invoiceDate = $_POST['invoice_date'] ?? date('Y-m-d');
$currency = $_POST['currency'] ?? 'ZAR';
$subtotal = (float)($_POST['subtotal'] ?? 0);
$tax = (float)($_POST['tax'] ?? 0);
$total = (float)($_POST['total'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$lines = $_POST['lines'] ?? [];

// Validation
if (!$fileId || !$supplierId || !$invoiceNumber || !$total) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Check if draft invoice already exists for this file
$stmt = $DB->prepare("
    SELECT id FROM invoices 
    WHERE company_id = ? AND invoice_number = ? AND customer_id = ? AND status = 'draft'
    LIMIT 1
");
$stmt->execute([$companyId, $invoiceNumber, $supplierId]);
$existingInvoice = $stmt->fetch();

try {
    $DB->beginTransaction();

    if ($existingInvoice) {
        // Update existing draft
        $invoiceId = $existingInvoice['id'];
        
        $stmt = $DB->prepare("
            UPDATE invoices SET
                issue_date = ?,
                currency = ?,
                subtotal = ?,
                tax = ?,
                total = ?,
                balance_due = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $invoiceDate,
            $currency,
            $subtotal,
            $tax,
            $total,
            $total,
            $notes,
            $invoiceId,
            $companyId
        ]);

        // Delete old lines
        $stmt = $DB->prepare("DELETE FROM invoice_lines WHERE invoice_id = ?");
        $stmt->execute([$invoiceId]);

    } else {
        // Create new draft
        $stmt = $DB->prepare("
            INSERT INTO invoices (
                company_id, invoice_number, customer_id, issue_date, due_date,
                status, subtotal, tax, total, balance_due, currency, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $invoiceNumber,
            $supplierId,
            $invoiceDate,
            date('Y-m-d', strtotime($invoiceDate . ' +30 days')),
            $subtotal,
            $tax,
            $total,
            $total,
            $currency,
            $notes,
            $userId
        ]);
        $invoiceId = $DB->lastInsertId();

        // Link to file
        $stmt = $DB->prepare("UPDATE receipt_file SET invoice_id = ? WHERE file_id = ? AND company_id = ?");
        $stmt->execute([$invoiceId, $fileId, $companyId]);
    }

    // Insert lines
    $sortOrder = 0;
    foreach ($lines as $line) {
        if (empty($line['description'])) continue;

        $qty = (float)($line['qty'] ?? 1);
        $unitPrice = (float)($line['unit_price'] ?? 0);
        $lineTotal = $qty * $unitPrice;

        $stmt = $DB->prepare("
            INSERT INTO invoice_lines (
                invoice_id, item_description, quantity, unit, unit_price,
                discount, tax_rate, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, 0, 15.00, ?, ?)
        ");
        $stmt->execute([
            $invoiceId,
            $line['description'],
            $qty,
            $line['unit'] ?? 'ea',
            $unitPrice,
            $lineTotal,
            $sortOrder++
        ]);
    }

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Draft saved successfully',
        'invoice_id' => $invoiceId
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log('Draft save error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}