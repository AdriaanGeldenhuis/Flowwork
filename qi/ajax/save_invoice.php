<?php
// /qi/ajax/save_invoice.php - COMPLETE WITH UPDATE
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SequenceAllocator.php';
require_once __DIR__ . '/../lib/Currencies.php';

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

// Fetch default currency from qi_settings; the default VAT rate is the
// company's STD tax code (finances/lib/TaxCodes — single source of truth).
$stmtTax = $DB->prepare("SELECT default_tax_rate, default_currency FROM qi_settings WHERE company_id = ?");
$stmtTax->execute([$companyId]);
$qiSettings = $stmtTax->fetch();
require_once __DIR__ . '/../../finances/lib/TaxCodes.php';
$taxCodes = new TaxCodes($DB, (int)$companyId);
$defaultTaxRatePercent = $taxCodes->standardRatePercent();

// Resolve document currency + exchange rate (1 unit = X ZAR, used for GL conversion)
$currency = strtoupper(trim($input['currency'] ?? ''));
$clientSentCurrency = Currencies::isValid($currency);
if (!$clientSentCurrency) {
    $currency = Currencies::isValid($qiSettings['default_currency'] ?? null)
        ? strtoupper($qiSettings['default_currency'])
        : Currencies::BASE;
}
$exchangeRate = isset($input['exchange_rate']) ? (float)$input['exchange_rate'] : 0.0;
if ($currency === Currencies::BASE) {
    $exchangeRate = 1.0;
} elseif ($exchangeRate <= 0) {
    require_once __DIR__ . '/../../finances/lib/CurrencyService.php';
    $svc = new CurrencyService($DB, (int)$companyId);
    $exchangeRate = (float)($svc->getRate($currency, $input['issue_date'] ?? date('Y-m-d')) ?? 0);
}
if ($exchangeRate <= 0) {
    echo json_encode(['ok' => false, 'error' => "No exchange rate available for {$currency}. Enter a rate on the form or add one under Finances → Exchange Rates."]);
    exit;
}

// Recalculate subtotal, tax and total to prevent tampering. Amounts are
// computed per line in INTEGER CENTS with the same rounding the posting
// engine uses (PostingService::postInvoice), so the customer-facing header
// always equals the GL to the cent. Each line's VAT rate is validated
// against the company's tax codes and the resolved tax_code_id is kept for
// persistence — zero-rated/exempt classification survives to the VAT201.
$subtotalC = 0;
$discountC = 0;
$taxC = 0;
try {
    foreach ($lineItems as $idx => $item) {
        $qty = isset($item['quantity']) ? floatval($item['quantity']) : 0;
        $price = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
        $lineDiscount = isset($item['discount']) ? floatval($item['discount']) : 0;
        $lineTaxRate = isset($item['tax_rate']) && $item['tax_rate'] !== ''
            ? floatval($item['tax_rate'])
            : $defaultTaxRatePercent;
        $lineNetC = (int)round(($qty * $price - $lineDiscount) * 100);
        $lineVatC = (int)round($lineNetC * $lineTaxRate / 100);

        $taxCodeId = $taxCodes->resolveOutputForLine(
            isset($item['tax_code_id']) && $item['tax_code_id'] !== '' ? (int)$item['tax_code_id'] : null,
            isset($item['tax_code']) ? (string)$item['tax_code'] : null,
            $lineTaxRate
        );

        $lineItems[$idx]['tax_rate'] = $lineTaxRate;
        $lineItems[$idx]['tax_code_id'] = $taxCodeId;
        $lineItems[$idx]['line_total_c'] = $lineNetC + $lineVatC;

        $subtotalC += $lineNetC;
        $discountC += (int)round($lineDiscount * 100);
        $taxC += $lineVatC;
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Override incoming totals with calculated values
$input['subtotal'] = $subtotalC / 100;
$input['discount'] = $discountC / 100;
$input['tax'] = $taxC / 100;
$input['total'] = ($subtotalC + $taxC) / 100;
// Document total in currency units — balance_due preservation and milestone
// percentage checks below depend on it.
$totalCalc = ($subtotalC + $taxC) / 100;

try {
    $DB->beginTransaction();
    
    $editMode = !empty($input['edit_mode']) && !empty($input['invoice_id']);
    
    if ($editMode) {
        // UPDATE
        $invoiceId = (int)$input['invoice_id'];
        
        $stmt = $DB->prepare("SELECT status, total, balance_due, currency, exchange_rate FROM invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([$invoiceId, $companyId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            throw new Exception('Invoice not found');
        }

        if ($existing['status'] !== 'draft') {
            throw new Exception('Only draft invoices can be edited');
        }

        // If the request didn't carry a valid currency (e.g. stale cached form
        // JS), keep the document's existing currency/rate rather than silently
        // re-denominating it to the company default.
        if (!$clientSentCurrency && Currencies::isValid($existing['currency'] ?? null)) {
            $currency = strtoupper($existing['currency']);
            $exchangeRate = (float)($existing['exchange_rate'] ?? 1) ?: 1.0;
        }

        // Recalculate balance_due: preserve amount already paid
        $amountPaid = floatval($existing['total']) - floatval($existing['balance_due']);
        $newBalanceDue = max(0, $totalCalc - $amountPaid);

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
                balance_due = ?,
                currency = ?,
                exchange_rate = ?,
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
            $newBalanceDue,
            $currency,
            $exchangeRate,
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
        // Allocate a unique invoice number using SequenceAllocator (race-safe)
        $alloc = new SequenceAllocator($DB);
        [$invoiceNumber, $seqNum] = $alloc->allocate($companyId, 'invoice', $input['issue_date'] ?? null, true);

        // Insert new invoice; balance_due initially equals total
        $stmt = $DB->prepare("
            INSERT INTO invoices (
                company_id, invoice_number, customer_id, contact_id, project_id,
                issue_date, due_date, status, subtotal, discount, tax, total, balance_due,
                currency, exchange_rate, terms, notes, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

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
            $currency,
            $exchangeRate,
            $input['terms'] ?? '',
            $input['notes'] ?? '',
            $userId
        ]);

        $invoiceId = $DB->lastInsertId();
        $message = 'Invoice created successfully';
    }
    
    // Insert line items. discount / tax_rate / tax_code_id / gl_account_id
    // MUST be persisted: the posting engine derives the GL journal and the
    // VAT201 from these columns. (They were previously dropped, so the DB
    // default of 15% overrode whatever the user chose — zero-rated sales
    // posted 15% output VAT to the ledger.)
    if (!empty($lineItems)) {
        $stmt = $DB->prepare(
            "INSERT INTO invoice_lines (
                invoice_id, item_description, quantity, unit_price, discount,
                tax_rate, tax_code_id, gl_account_id, line_total, sort_order, inventory_item_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($lineItems as $index => $item) {
            $invId = null;
            if (isset($item['inventory_item_id']) && $item['inventory_item_id'] !== '' && $item['inventory_item_id'] !== null) {
                $invId = $item['inventory_item_id'];
            }
            $glAccountId = isset($item['gl_account_id']) && $item['gl_account_id'] !== ''
                ? (int)$item['gl_account_id'] : null;
            $stmt->execute([
                $invoiceId,
                $item['description'] ?? '',
                $item['quantity'] ?? 0,
                $item['unit_price'] ?? 0,
                $item['discount'] ?? 0,
                $item['tax_rate'],
                $item['tax_code_id'],
                $glAccountId,
                ($item['line_total_c'] ?? 0) / 100,
                $index,
                $invId
            ]);
        }
    }

    // Handle payment milestones
    $milestones = $input['milestones'] ?? [];
    $hasMilestones = !empty($milestones) && is_array($milestones) ? 1 : 0;

    if ($hasMilestones) {
        // Calculate total allocated (supports both percentage and fixed amounts)
        $totalAllocated = 0;
        foreach ($milestones as $ms) {
            if (isset($ms['fixed_amount']) && $ms['fixed_amount'] !== null) {
                $totalAllocated += floatval($ms['fixed_amount']);
            } else {
                $totalAllocated += $totalCalc * (floatval($ms['percentage'] ?? 0) / 100);
            }
        }
        $allocatedPct = $totalCalc > 0 ? ($totalAllocated / $totalCalc) * 100 : 0;
        if (abs($allocatedPct - 100) > 0.01) {
            throw new Exception('Milestone amounts must add up to 100% of total (currently ' . round($allocatedPct, 2) . '%)');
        }

        // Delete existing milestones on edit
        if ($editMode) {
            $stmt = $DB->prepare("DELETE FROM payment_milestones WHERE entity_type = 'invoice' AND entity_id = ? AND company_id = ?");
            $stmt->execute([$invoiceId, $companyId]);
        }

        // Insert milestones (first milestone starts as 'due', rest as 'pending')
        $msStmt = $DB->prepare("INSERT INTO payment_milestones (entity_type, entity_id, company_id, label, percentage, amount, due_date, status, sort_order) VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($milestones as $idx => $ms) {
            if (isset($ms['fixed_amount']) && $ms['fixed_amount'] !== null) {
                $msAmount = round(floatval($ms['fixed_amount']), 2);
                $pct = $totalCalc > 0 ? round(($msAmount / $totalCalc) * 100, 2) : 0;
            } else {
                $pct = floatval($ms['percentage'] ?? 0);
                $msAmount = round($totalCalc * ($pct / 100), 2);
            }
            $msDueDate = !empty($ms['due_date']) ? $ms['due_date'] : null;
            $msStatus = ($idx === 0) ? 'due' : 'pending';
            $msStmt->execute([
                $invoiceId,
                $companyId,
                $ms['label'] ?? ('Phase ' . ($idx + 1)),
                $pct,
                $msAmount,
                $msDueDate,
                $msStatus,
                $idx
            ]);
        }
    }

    // Update has_milestones flag
    $stmt = $DB->prepare("UPDATE invoices SET has_milestones = ? WHERE id = ?");
    $stmt->execute([$hasMilestones, $invoiceId]);

    $DB->commit();

    // Draft invoices do NOT post to the general ledger — a draft is not a tax
    // invoice. Posting happens when the invoice is issued (send_invoice.php /
    // invoice_action.php via InvoiceLifecycle::issueInvoice).

    echo json_encode([
        'ok' => true,
        'message' => $message,
        'invoice_id' => $invoiceId
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Save invoice error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    if ($e instanceof PDOException) {
        // Include the SQL error code for debugging
        $safeMsg = 'A database error occurred: ' . $e->getMessage();
    } else {
        $safeMsg = $e->getMessage();
    }
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}

// After the invoice has been saved and committed, hook into the calendar to create/update due events.
if (isset($invoiceId) && isset($companyId) && isset($userId) && $invoiceId) {
    try {
        require_once __DIR__ . '/../services/CalendarHook.php';
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