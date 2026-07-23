<?php
// /qi/ajax/save_credit_note.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/require_writer.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
$invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT) ?: null;
$issueDate = $_POST['issue_date'] ?? date('Y-m-d');
$reason = $_POST['reason'] ?? '';
// SARS s21 reason classification (Migrations/2026-04-10-credit-note-reason-codes.sql)
$VALID_REASON_CODES = ['return', 'discount', 'correction', 'damaged', 'cancellation', 'vat_adjustment', 'other'];
$reasonCode = in_array($_POST['reason_code'] ?? '', $VALID_REASON_CODES, true)
    ? $_POST['reason_code'] : 'other';

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

require_once __DIR__ . '/../../finances/lib/TaxCodes.php';
$taxCodes = new TaxCodes($DB, (int)$companyId);

// Recalculate subtotal/tax/total from the lines in INTEGER CENTS (same
// rounding as the posting engine) — the header used to be taken verbatim
// from the client, but the s21 creditable cap checks the header while the
// GL posting is line-driven: a tampered header (total=0.01, lines=R1150)
// bypassed the cap and desynced the subledger from the GL.
$subtotalC = 0;
$taxC = 0;
try {
    foreach ($lines as $i => $line) {
        $qty = floatval($line['quantity'] ?? 1);
        $unitPrice = floatval($line['unit_price'] ?? 0);
        $discount = floatval($line['discount'] ?? 0);
        $taxRate = isset($line['tax_rate']) && $line['tax_rate'] !== ''
            ? floatval($line['tax_rate'])
            : $taxCodes->standardRatePercent();
        $taxCodeId = $taxCodes->resolveOutputForLine(
            isset($line['tax_code_id']) && $line['tax_code_id'] !== '' ? (int)$line['tax_code_id'] : null,
            $line['tax_code'] ?? null,
            $taxRate
        );
        $lineNetC = (int)round(($qty * $unitPrice - $discount) * 100);
        $lineVatC = (int)round($lineNetC * $taxRate / 100);
        $lines[$i]['tax_rate'] = $taxRate;
        $lines[$i]['tax_code_id'] = $taxCodeId;
        $lines[$i]['line_total_c'] = $lineNetC + $lineVatC;
        $subtotalC += $lineNetC;
        $taxC += $lineVatC;
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
$subtotal = $subtotalC / 100;
$tax = $taxC / 100;
$total = ($subtotalC + $taxC) / 100;

try {
    $DB->beginTransaction();

    // s21 control: a credit against an invoice may not exceed what is still
    // creditable on it (total minus credits already issued). The AP side has
    // had this check all along — the AR side did not.
    if ($invoiceId) {
        $stmt = $DB->prepare(
            "SELECT i.total,
                    COALESCE((SELECT SUM(cn.total) FROM credit_notes cn
                               WHERE cn.invoice_id = i.id AND cn.status <> 'cancelled'), 0) AS credited
               FROM invoices i WHERE i.id = ? AND i.company_id = ? FOR UPDATE"
        );
        $stmt->execute([$invoiceId, $companyId]);
        $invTotals = $stmt->fetch();
        $creditable = round((float)$invTotals['total'] - (float)$invTotals['credited'], 2);
        if (round($total, 2) > $creditable + 0.01) {
            throw new Exception(sprintf(
                'Credit note total R%.2f exceeds the remaining creditable amount R%.2f on the linked invoice',
                $total, max(0, $creditable)
            ));
        }
    }

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
            issue_date, status, subtotal, tax, total, currency, exchange_rate, reason, reason_code, created_by
        ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId, $creditNoteNumber, $invoiceId, $customerId,
        $issueDate, $subtotal, $tax, $total, $currency, $exchangeRate, $reason, $reasonCode, $userId
    ]);

    $creditNoteId = $DB->lastInsertId();

    // Insert line items (per-line tax code so the VAT reversal lands in the
    // right VAT201 classification — rates/codes resolved in the recompute
    // block above, totals in integer cents matching the engine)
    $sortOrder = 0;
    foreach ($lines as $line) {
        $stmt = $DB->prepare("
            INSERT INTO credit_note_lines (
                credit_note_id, item_description, quantity, unit, unit_price,
                discount, tax_rate, tax_code_id, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $creditNoteId,
            $line['description'] ?? '',
            floatval($line['quantity'] ?? 1),
            $line['unit'] ?? 'unit',
            floatval($line['unit_price'] ?? 0),
            floatval($line['discount'] ?? 0),
            $line['tax_rate'],
            $line['tax_code_id'],
            $line['line_total_c'] / 100,
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

    // Render the credit note PDF and publish it to FlowWork Drive under the
    // customer's folder (best-effort; never blocks the response).
    require_once __DIR__ . '/../services/DocumentPdfService.php';
    DocumentPdfService::generateAndFile($DB, (int)$companyId, 'credit_note', (int)$creditNoteId, (int)$userId);

    echo json_encode(['ok' => true, 'credit_note_id' => $creditNoteId, 'credit_note_number' => $creditNoteNumber]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Save credit note error: " . $e->getMessage());
    $safeMsg = ($e instanceof PDOException) ? 'Failed to create credit note' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}