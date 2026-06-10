<?php
// /qi/ajax/save_credit_note.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
$invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT) ?: null;
$issueDate = $_POST['issue_date'] ?? date('Y-m-d');
$reason = $_POST['reason'] ?? '';

$subtotal = floatval($_POST['subtotal'] ?? 0);
$tax = floatval($_POST['tax'] ?? 0);
$total = floatval($_POST['total'] ?? 0);

$lines = $_POST['lines'] ?? [];

if (!$customerId || empty($lines)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

// Credit notes inherit the linked invoice's currency; standalone credit notes
// use the company default currency with a fresh rate lookup.
require_once __DIR__ . '/../lib/Currencies.php';
$currency = Currencies::BASE;
$exchangeRate = 1.0;
if ($invoiceId) {
    $stmtInv = $DB->prepare("SELECT currency, exchange_rate FROM invoices WHERE id = ? AND company_id = ?");
    $stmtInv->execute([$invoiceId, $companyId]);
    $invRow = $stmtInv->fetch();
    if (!$invRow) {
        echo json_encode(['ok' => false, 'error' => 'Linked invoice not found']);
        exit;
    }
    if (Currencies::isValid($invRow['currency'] ?? null)) {
        $currency = strtoupper($invRow['currency']);
        $exchangeRate = (float)($invRow['exchange_rate'] ?? 1) ?: 1.0;
    }
} else {
    $stmtCur = $DB->prepare("SELECT default_currency FROM qi_settings WHERE company_id = ?");
    $stmtCur->execute([$companyId]);
    $defCur = $stmtCur->fetchColumn();
    if (Currencies::isValid($defCur) && strtoupper($defCur) !== Currencies::BASE) {
        $currency = strtoupper($defCur);
        require_once __DIR__ . '/../../finances/lib/CurrencyService.php';
        $svc = new CurrencyService($DB, (int)$companyId);
        $exchangeRate = (float)($svc->getRate($currency, $issueDate) ?? 0);
        if ($exchangeRate <= 0) {
            echo json_encode(['ok' => false, 'error' => "No exchange rate available for {$currency}. Add one under Finances → Exchange Rates."]);
            exit;
        }
    }
}

try {
    $DB->beginTransaction();

    // Generate credit note number
    $year = date('Y');
    $stmt = $DB->prepare("
        INSERT INTO qi_sequences (company_id, type, year, next_number)
        VALUES (?, 'credit_note', ?, 1)
        ON DUPLICATE KEY UPDATE next_number = next_number + 1
    ");
    $stmt->execute([$companyId, $year]);

    $stmt = $DB->prepare("SELECT next_number - 1 AS num FROM qi_sequences WHERE company_id = ? AND type = 'credit_note' AND year = ?");
    $stmt->execute([$companyId, $year]);
    $num = $stmt->fetchColumn();

    $creditNoteNumber = sprintf('CN%d-%04d', $year, $num);

    // Insert credit note
    $stmt = $DB->prepare("
        INSERT INTO credit_notes (
            company_id, credit_note_number, invoice_id, customer_id,
            issue_date, status, subtotal, tax, total, currency, exchange_rate, reason, created_by
        ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId, $creditNoteNumber, $invoiceId, $customerId,
        $issueDate, $subtotal, $tax, $total, $currency, $exchangeRate, $reason, $userId
    ]);

    $creditNoteId = $DB->lastInsertId();

    // Insert line items
    $sortOrder = 0;
    foreach ($lines as $line) {
        $qty = floatval($line['quantity'] ?? 1);
        $unitPrice = floatval($line['unit_price'] ?? 0);
        $discount = floatval($line['discount'] ?? 0);
        $taxRate = floatval($line['tax_rate'] ?? 15);
        
        $lineSubtotal = $qty * $unitPrice;
        $lineNet = $lineSubtotal - $discount;
        $lineTax = $lineNet * ($taxRate / 100);
        $lineTotal = $lineNet + $lineTax;

        $stmt = $DB->prepare("
            INSERT INTO credit_note_lines (
                credit_note_id, item_description, quantity, unit, unit_price,
                discount, tax_rate, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $creditNoteId,
            $line['description'] ?? '',
            $qty,
            $line['unit'] ?? 'unit',
            $unitPrice,
            $discount,
            $taxRate,
            $lineTotal,
            $sortOrder++
        ]);
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'credit_note_created', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['credit_note_id' => $creditNoteId, 'credit_note_number' => $creditNoteNumber]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'credit_note_id' => $creditNoteId, 'credit_note_number' => $creditNoteNumber]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Save credit note error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create credit note']);
}