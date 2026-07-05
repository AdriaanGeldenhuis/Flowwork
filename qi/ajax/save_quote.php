<?php
// /qi/ajax/save_quote.php - COMPLETE WITH UPDATE
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

// Fetch default tax rate and currency from qi_settings
$stmtTax = $DB->prepare("SELECT default_tax_rate, default_currency FROM qi_settings WHERE company_id = ?");
$stmtTax->execute([$companyId]);
$qiSettings = $stmtTax->fetch();
$defaultTaxRate = ($qiSettings && isset($qiSettings['default_tax_rate']))
    ? floatval($qiSettings['default_tax_rate']) / 100
    : 0.15;

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

// Recalculate subtotal, tax and total to prevent tampering
$subtotalCalc = 0;
$discountCalc = 0;
$taxCalc = 0;
foreach ($lineItems as $item) {
    $qty = isset($item['quantity']) ? floatval($item['quantity']) : 0;
    $price = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
    $lineDiscount = isset($item['discount']) ? floatval($item['discount']) : 0;
    $lineSubtotal = $qty * $price;
    $lineNet = $lineSubtotal - $lineDiscount;
    $subtotalCalc += $lineNet;
    $discountCalc += $lineDiscount;
    $lineTaxRate = isset($item['tax_rate']) ? floatval($item['tax_rate']) / 100 : $defaultTaxRate;
    $taxCalc += $lineNet * $lineTaxRate;
}
$totalCalc = $subtotalCalc + $taxCalc;

// Override incoming totals with calculated values
$input['subtotal'] = $subtotalCalc;
$input['discount'] = $discountCalc;
$input['tax'] = $taxCalc;
$input['total'] = $totalCalc;

try {
    $DB->beginTransaction();
    
    $editMode = !empty($input['edit_mode']) && !empty($input['quote_id']);
    
    if ($editMode) {
        // UPDATE
        $quoteId = (int)$input['quote_id'];
        
        $stmt = $DB->prepare("SELECT status, currency, exchange_rate FROM quotes WHERE id = ? AND company_id = ?");
        $stmt->execute([$quoteId, $companyId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            throw new Exception('Quote not found');
        }

        if ($existing['status'] !== 'draft') {
            throw new Exception('Only draft quotes can be edited');
        }

        // If the request didn't carry a valid currency (e.g. stale cached form
        // JS), keep the document's existing currency/rate rather than silently
        // re-denominating it to the company default.
        if (!$clientSentCurrency && Currencies::isValid($existing['currency'] ?? null)) {
            $currency = strtoupper($existing['currency']);
            $exchangeRate = (float)($existing['exchange_rate'] ?? 1) ?: 1.0;
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
            $input['expiry_date'],
            $input['subtotal'],
            $input['discount'] ?? 0,
            $input['tax'],
            $input['total'],
            $currency,
            $exchangeRate,
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
        // Allocate a unique quote number using SequenceAllocator (race-safe)
        $alloc = new SequenceAllocator($DB);
        [$quoteNumber, $seqNum] = $alloc->allocate($companyId, 'quote', $input['issue_date'] ?? null, true);

        // Generate public token for this quote
        $publicToken = bin2hex(random_bytes(16));
        // Insert the quote header including public token
        $stmt = $DB->prepare("
            INSERT INTO quotes (
                company_id, quote_number, public_token, customer_id, contact_id, project_id,
                issue_date, expiry_date, status, subtotal, discount, tax, total,
                currency, exchange_rate, terms, notes, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
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
            $currency,
            $exchangeRate,
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

    // Handle payment milestones. Only requests that carry the key touch
    // stored phases: clients that never send it (no milestone UI) must not
    // wipe phases already attached to the quote.
    $milestonesProvided = array_key_exists('milestones', $input) && is_array($input['milestones']);
    $milestones = $milestonesProvided ? $input['milestones'] : [];
    $hasMilestones = !empty($milestones) ? 1 : 0;

    if ($milestonesProvided && !$hasMilestones && $editMode) {
        // Explicitly disabled on edit: clear stored phases.
        $stmt = $DB->prepare("DELETE FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ?");
        $stmt->execute([$quoteId, $companyId]);
    }

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
            $stmt = $DB->prepare("DELETE FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ?");
            $stmt->execute([$quoteId, $companyId]);
        }

        // Insert milestones (first milestone starts as 'due', rest as 'pending')
        $msStmt = $DB->prepare("INSERT INTO payment_milestones (entity_type, entity_id, company_id, label, percentage, amount, due_date, status, sort_order) VALUES ('quote', ?, ?, ?, ?, ?, ?, ?, ?)");
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
                $quoteId,
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

    // Update has_milestones flag (only when the request spoke about milestones)
    if ($milestonesProvided) {
        $stmt = $DB->prepare("UPDATE quotes SET has_milestones = ? WHERE id = ?");
        $stmt->execute([$hasMilestones, $quoteId]);
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
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}

// After the quote has been saved (either created or updated) and committed, hook into the calendar to create/update expiry events.
if (isset($quoteId) && isset($companyId) && isset($userId) && $quoteId) {
    try {
        require_once __DIR__ . '/../services/CalendarHook.php';
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