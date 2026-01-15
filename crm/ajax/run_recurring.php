<?php
// /qi/ajax/run_recurring.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$recurringId = $input['id'] ?? null;

if (!$recurringId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    // Fetch recurring invoice
    $stmt = $DB->prepare("SELECT * FROM recurring_invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$recurringId, $companyId]);
    $recurring = $stmt->fetch();

    if (!$recurring || !$recurring['active']) {
        throw new Exception('Recurring invoice not found or inactive');
    }

    // Fetch lines
    $stmt = $DB->prepare("SELECT * FROM recurring_invoice_lines WHERE recurring_invoice_id = ? ORDER BY sort_order");
    $stmt->execute([$recurringId]);
    $lines = $stmt->fetchAll();

    // Generate invoice number using race-proof sequence allocation (see Section 1)
    $year = date('Y');
    // Lock sequence row for invoices for this company/year
    $seqStmt = $DB->prepare("SELECT next_number FROM qi_sequences WHERE company_id = ? AND type = 'invoice' AND year = ? FOR UPDATE");
    $seqStmt->execute([$companyId, $year]);
    $seqRow = $seqStmt->fetch(PDO::FETCH_ASSOC);
    if (!$seqRow) {
        // If no sequence row exists yet, insert one starting at 0
        $insertSeq = $DB->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, 'invoice', ?, 0)");
        $insertSeq->execute([$companyId, $year]);
        $nextNum = 1;
    } else {
        $nextNum = intval($seqRow['next_number']) + 1;
    }
    // Update sequence with new number
    $updateSeq = $DB->prepare("UPDATE qi_sequences SET next_number = ? WHERE company_id = ? AND type = 'invoice' AND year = ?");
    $updateSeq->execute([$nextNum, $companyId, $year]);
    // Build invoice number with year prefix and zero-padded sequence
    $invoiceNumber = sprintf('INV%d-%04d', $year, $nextNum);

    // Calculate totals
    $subtotal = 0;
    $discount = 0;
    $tax = 0;

    foreach ($lines as $line) {
        $qty = floatval($line['quantity']);
        $price = floatval($line['unit_price']);
        $disc = floatval($line['discount']);
        $taxRate = floatval($line['tax_rate']);

        $lineSubtotal = $qty * $price;
        $lineNet = $lineSubtotal - $disc;
        $lineTax = $lineNet * ($taxRate / 100);

        $subtotal += $lineSubtotal;
        $discount += $disc;
        $tax += $lineTax;
    }

    $total = $subtotal - $discount + $tax;

    // Create invoice
    $issueDate = date('Y-m-d');
    $dueDate = date('Y-m-d', strtotime('+30 days'));

    $stmt = $DB->prepare("
        INSERT INTO invoices (
            company_id, invoice_number, customer_id,
            issue_date, due_date, status,
            subtotal, discount, tax, total, balance_due, currency,
            terms, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, 'ZAR', ?, ?, ?)
    ");
    $stmt->execute([
        $companyId, $invoiceNumber, $recurring['customer_id'],
        $issueDate, $dueDate,
        $subtotal, $discount, $tax, $total, $total,
        $recurring['terms'], 'Generated from recurring: ' . $recurring['template_name'], $userId
    ]);

    $invoiceId = $DB->lastInsertId();

    // Copy line items
    $sortOrder = 0;
    foreach ($lines as $line) {
        $qty = floatval($line['quantity']);
        $price = floatval($line['unit_price']);
        $disc = floatval($line['discount']);
        $taxRate = floatval($line['tax_rate']);

        $lineSubtotal = $qty * $price;
        $lineNet = $lineSubtotal - $disc;
        $lineTax = $lineNet * ($taxRate / 100);
        $lineTotal = $lineNet + $lineTax;

        $stmt = $DB->prepare("
            INSERT INTO invoice_lines (
                invoice_id, item_description, quantity, unit, unit_price, discount, tax_rate, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceId,
            $line['item_description'],
            $qty,
            $line['unit'],
            $price,
            $disc,
            $taxRate,
            $lineTotal,
            $sortOrder++
        ]);
    }

    // Update recurring invoice - calculate next run date
    $nextDate = date('Y-m-d', strtotime($recurring['next_run_date']));
    
    switch ($recurring['frequency']) {
        case 'weekly':
            $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . $recurring['interval_count'] . ' weeks'));
            break;
        case 'monthly':
            $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . $recurring['interval_count'] . ' months'));
            break;
        case 'quarterly':
            $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . ($recurring['interval_count'] * 3) . ' months'));
            break;
        case 'yearly':
            $nextDate = date('Y-m-d', strtotime($nextDate . ' +' . $recurring['interval_count'] . ' years'));
            break;
    }

    $stmt = $DB->prepare("
        UPDATE recurring_invoices 
        SET next_run_date = ?, last_generated_date = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$nextDate, $recurringId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'recurring_invoice_generated', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['recurring_id' => $recurringId, 'invoice_id' => $invoiceId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Run recurring error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}