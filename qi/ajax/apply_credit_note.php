<?php
// /qi/ajax/apply_credit_note.php – Apply an approved credit note to its linked invoice
// Accepts JSON input {credit_note_id} (and optionally invoice_id) and applies the credit amount to the invoice's balance.

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/require_writer.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$creditNoteId = isset($input['credit_note_id']) ? (int)$input['credit_note_id'] : 0;
$overrideInvoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;

if (!$creditNoteId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid credit note ID']);
    exit;
}

try {
    $DB->beginTransaction();
    // Lock credit note
    $stmt = $DB->prepare("SELECT * FROM credit_notes WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$creditNoteId, $companyId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credit) {
        throw new Exception('Credit note not found');
    }
    if ($credit['status'] !== 'approved') {
        throw new Exception('Only approved credit notes can be applied');
    }
    // Determine target invoice
    $invoiceId = $overrideInvoiceId ?: (int)$credit['invoice_id'];
    if (!$invoiceId) {
        throw new Exception('No invoice linked to this credit note');
    }
    // Lock invoice row
    $stmt = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        throw new Exception('Linked invoice not found');
    }
    // Both amounts are in document currency — they must be the same currency
    $creditCurrency  = strtoupper($credit['currency'] ?? 'ZAR') ?: 'ZAR';
    $invoiceCurrency = strtoupper($invoice['currency'] ?? 'ZAR') ?: 'ZAR';
    if ($creditCurrency !== $invoiceCurrency) {
        throw new Exception("Currency mismatch: credit note is in {$creditCurrency} but the invoice is in {$invoiceCurrency}");
    }
    $creditTotal = (float)$credit['total'];
    if ($creditTotal <= 0) {
        throw new Exception('Invalid credit amount');
    }
    // Same-customer safety: a credit note may only be applied to an invoice for
    // its own customer (guards the arbitrary invoice_id override, which could
    // otherwise apply customer A's credit to customer B's invoice).
    $cnCustomer  = (int)($credit['customer_id'] ?? 0);
    $invCustomer = (int)($invoice['customer_id'] ?? 0);
    if ($cnCustomer && $invCustomer && $cnCustomer !== $invCustomer) {
        throw new Exception('This credit note belongs to a different customer than the invoice');
    }

    // B6: cap the application at BOTH the credit note's unallocated remainder and
    // the invoice's outstanding balance. The old code recorded the FULL credit
    // amount in the allocation while only reducing the balance to 0, so the
    // excess was silently lost and the allocation subledger no longer matched the
    // invoice's actual balance reduction.
    $allocStmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM credit_note_allocations WHERE credit_note_id = ?");
    $allocStmt->execute([$creditNoteId]);
    $alreadyAllocated = (float)$allocStmt->fetchColumn();
    $remainingCredit  = round($creditTotal - $alreadyAllocated, 2);
    if ($remainingCredit <= 0.01) {
        throw new Exception('This credit note has already been fully applied');
    }
    $balanceDue = (float)$invoice['balance_due'];
    if ($balanceDue <= 0.01) {
        throw new Exception('The invoice has no outstanding balance to credit');
    }
    $appliedAmount = round(min($remainingCredit, $balanceDue), 2);
    $excess     = round($remainingCredit - $appliedAmount, 2); // unapplied remainder
    $newBalance = round($balanceDue - $appliedAmount, 2);
    $newStatus  = ($newBalance <= 0.01) ? 'paid' : 'part-paid';
    $paidAt     = ($newBalance <= 0.01) ? date('Y-m-d H:i:s') : $invoice['paid_at'];

    // Update invoice
    $stmt = $DB->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$newBalance, $newStatus, $paidAt, $invoiceId, $companyId]);
    // Credit note becomes 'applied' only when fully allocated; otherwise it
    // stays 'approved' so the unapplied remainder can be applied elsewhere.
    $cnFullyApplied = round($alreadyAllocated + $appliedAmount, 2) >= round($creditTotal, 2) - 0.01;
    $stmt = $DB->prepare("UPDATE credit_notes SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$cnFullyApplied ? 'applied' : 'approved', $creditNoteId, $companyId]);
    // Allocation records the amount ACTUALLY applied to this invoice.
    $stmt = $DB->prepare(
        "INSERT INTO credit_note_allocations (company_id, credit_note_id, invoice_id, amount) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$companyId, $creditNoteId, $invoiceId, $appliedAmount]);
    // Record audit log
    $stmt = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip) VALUES (?, ?, 'credit_note_applied', ?, ?)");
    $details = json_encode(['credit_note_id' => $creditNoteId, 'applied_amount' => $appliedAmount, 'invoice_id' => $invoiceId, 'unapplied_remainder' => $excess]);
    $stmt->execute([$companyId, $userId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);

    // B4: post the credit-note journal INSIDE this transaction. The old code
    // committed first and posted afterwards in a swallow-all try/catch, so a
    // posting failure left the credit applied with no journal — AR overstated,
    // VAT201 missing the output-tax reduction. postCreditNote posts the FULL
    // credit note once (it supersedes on re-post); only trigger it on the first
    // application (journal_id NULL) so later partial applications reuse it.
    if (empty($credit['journal_id'])) {
        require_once __DIR__ . '/../../finances/lib/PostingService.php';
        $posting = new PostingService($DB, $companyId, $userId);
        $posting->postCreditNote((int)$creditNoteId);
    }

    $DB->commit();

    // Invoice balance changed — refresh its PDF and drive copy.
    require_once __DIR__ . '/../services/DocumentPdfService.php';
    DocumentPdfService::generateAndFile($DB, (int)$companyId, 'invoice', (int)$invoiceId, (int)$userId);

    $response = ['ok' => true, 'new_balance' => $newBalance];
    if ($excess > 0.01) {
        $response['warning'] = 'R' . number_format($excess, 2) . ' of the credit note remains unapplied and can be applied to another invoice.';
    }
    echo json_encode($response);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Apply credit note error: ' . $e->getMessage());
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}