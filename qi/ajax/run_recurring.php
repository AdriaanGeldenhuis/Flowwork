<?php
// /qi/ajax/run_recurring.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SequenceAllocator.php';

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

    // Generate invoice number using SequenceAllocator (race-safe)
    $alloc = new SequenceAllocator($DB);
    [$invoiceNumber, $seqNum] = $alloc->allocate($companyId, 'invoice', null, true);

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

    // Create invoice - use qi_settings for payment terms and default currency
    $issueDate = date('Y-m-d');
    $stmtTerms = $DB->prepare("SELECT default_payment_terms, default_currency FROM qi_settings WHERE company_id = ?");
    $stmtTerms->execute([$companyId]);
    $termsRow = $stmtTerms->fetch();
    $paymentDays = ($termsRow && $termsRow['default_payment_terms']) ? (int)$termsRow['default_payment_terms'] : 30;
    $dueDate = date('Y-m-d', strtotime("+{$paymentDays} days"));

    require_once __DIR__ . '/../lib/Currencies.php';
    $currency = Currencies::isValid($termsRow['default_currency'] ?? null) ? strtoupper($termsRow['default_currency']) : Currencies::BASE;
    $exchangeRate = 1.0;
    if ($currency !== Currencies::BASE) {
        require_once __DIR__ . '/../../finances/lib/CurrencyService.php';
        $svc = new CurrencyService($DB, (int)$companyId);
        $exchangeRate = (float)($svc->getRate($currency, $issueDate) ?? 0);
        if ($exchangeRate <= 0) {
            error_log("Recurring invoice: no {$currency} exchange rate for {$issueDate}; using 1.0");
            $exchangeRate = 1.0;
        }
    }

    $stmt = $DB->prepare("
        INSERT INTO invoices (
            company_id, invoice_number, customer_id,
            issue_date, due_date, status,
            subtotal, discount, tax, total, balance_due, currency, exchange_rate,
            terms, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId, $invoiceNumber, $recurring['customer_id'],
        $issueDate, $dueDate,
        $subtotal, $discount, $tax, $total, $total, $currency, $exchangeRate,
        $recurring['terms'] ?? '', 'Generated from recurring: ' . ($recurring['template_name'] ?? ('#' . $recurringId)), $userId
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
                invoice_id, item_description, quantity, unit, unit_price, discount, tax_rate, tax_code_id, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceId,
            $line['item_description'],
            $qty,
            $line['unit'],
            $price,
            $disc,
            $taxRate,
            $line['tax_code_id'] ?? null,
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

    // A generated recurring invoice is an intentional issuance: transition it
    // out of draft and post it to the GL inside this transaction (rolls back
    // the generation if posting fails).
    require_once __DIR__ . '/../lib/InvoiceLifecycle.php';
    InvoiceLifecycle::issueInvoice($DB, $companyId, $userId, (int)$invoiceId);

    $DB->commit();

    echo json_encode(['ok' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Run recurring error: " . $e->getMessage());
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}