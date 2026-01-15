<?php
// /qi/ajax/create_quote.php - COMPLETE FILE
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
$projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT) ?: null;
$issueDate = $_POST['issue_date'] ?? date('Y-m-d');
$expiryDate = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+14 days'));
$terms = $_POST['terms'] ?? '';
$notes = $_POST['notes'] ?? '';

$lines = $_POST['lines'] ?? [];

if (!$customerId || empty($lines)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input - customer and lines required']);
    exit;
}

try {
    $DB->beginTransaction();

    // Calculate totals
    $subtotal = 0;
    $totalDiscount = 0;
    $totalTax = 0;

    foreach ($lines as $line) {
        $qty = floatval($line['quantity'] ?? 1);
        $unitPrice = floatval($line['unit_price'] ?? 0);
        $lineDiscount = floatval($line['discount'] ?? 0);
        $taxRate = floatval($line['tax_rate'] ?? 15);
        
        $lineSubtotal = $qty * $unitPrice;
        $lineNet = $lineSubtotal - $lineDiscount;
        $lineTax = $lineNet * ($taxRate / 100);

        $subtotal += $lineSubtotal;
        $totalDiscount += $lineDiscount;
        $totalTax += $lineTax;
    }

    $total = $subtotal - $totalDiscount + $totalTax;

    // Generate quote number
    $year = date('Y');
    $stmt = $DB->prepare("
        INSERT INTO qi_sequences (company_id, type, year, next_number)
        VALUES (?, 'quote', ?, 1)
        ON DUPLICATE KEY UPDATE next_number = next_number + 1
    ");
    $stmt->execute([$companyId, $year]);

    $stmt = $DB->prepare("SELECT next_number - 1 AS num FROM qi_sequences WHERE company_id = ? AND type = 'quote' AND year = ?");
    $stmt->execute([$companyId, $year]);
    $num = $stmt->fetchColumn();

    $quoteNumber = sprintf('Q%d-%04d', $year, $num);

    // Insert quote
    $stmt = $DB->prepare("
        INSERT INTO quotes (
            company_id, quote_number, customer_id, project_id,
            issue_date, expiry_date, status,
            subtotal, discount, tax, total, currency,
            terms, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, 'ZAR', ?, ?, ?)
    ");
    $stmt->execute([
        $companyId, $quoteNumber, $customerId, $projectId,
        $issueDate, $expiryDate,
        $subtotal, $totalDiscount, $totalTax, $total,
        $terms, $notes, $userId
    ]);

    $quoteId = $DB->lastInsertId();

    // Insert line items
    $sortOrder = 0;
    foreach ($lines as $line) {
        $qty = floatval($line['quantity'] ?? 1);
        $unitPrice = floatval($line['unit_price'] ?? 0);
        $lineDiscount = floatval($line['discount'] ?? 0);
        $taxRate = floatval($line['tax_rate'] ?? 15);
        
        $lineSubtotal = $qty * $unitPrice;
        $lineNet = $lineSubtotal - $lineDiscount;
        $lineTax = $lineNet * ($taxRate / 100);
        $lineTotal = $lineNet + $lineTax;

        $stmt = $DB->prepare("
            INSERT INTO quote_lines (
                quote_id, item_description, quantity, unit, unit_price,
                discount, tax_rate, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $quoteId,
            $line['description'] ?? '',
            $qty,
            $line['unit'] ?? 'unit',
            $unitPrice,
            $lineDiscount,
            $taxRate,
            $lineTotal,
            $sortOrder++
        ]);
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'quote_created', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['quote_id' => $quoteId, 'quote_number' => $quoteNumber]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'quote_id' => $quoteId, 'quote_number' => $quoteNumber]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Create quote error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create quote: ' . $e->getMessage()]);
}