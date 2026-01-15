<?php
// /qi/ajax/apply_credit_note.php – Apply an approved credit note to its linked invoice
// Accepts JSON input {credit_note_id} (and optionally invoice_id) and applies the credit amount to the invoice's balance.

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

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
    // Calculate new balance and status
    $creditAmount = (float)$credit['total'];
    if ($creditAmount <= 0) {
        throw new Exception('Invalid credit amount');
    }
    $newBalance = (float)$invoice['balance_due'] - $creditAmount;
    if ($newBalance < 0) {
        // Do not allow invoice balance to go negative
        $newBalance = 0;
    }
    // Determine new status
    $newStatus = ($newBalance <= 0) ? 'paid' : 'part-paid';
    $paidAt = ($newBalance <= 0) ? date('Y-m-d H:i:s') : $invoice['paid_at'];
    // Update invoice
    $stmt = $DB->prepare("UPDATE invoices SET balance_due = ?, status = ?, paid_at = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$newBalance, $newStatus, $paidAt, $invoiceId, $companyId]);
    // Update credit note status to applied
    $stmt = $DB->prepare("UPDATE credit_notes SET status = 'applied', updated_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$creditNoteId, $companyId]);
    // Insert allocation record (new table) for this credit application
    $stmt = $DB->prepare(
        "INSERT INTO credit_note_allocations (company_id, credit_note_id, invoice_id, amount) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$companyId, $creditNoteId, $invoiceId, $creditAmount]);
    // Record audit log
    $stmt = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip) VALUES (?, ?, 'credit_note_applied', ?, ?)");
    $details = json_encode(['credit_note_id' => $creditNoteId, 'credit_amount' => $creditAmount, 'invoice_id' => $invoiceId]);
    $stmt->execute([$companyId, $userId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    $DB->commit();

    // Post journal entry for this credit note application using PostingService
    try {
        require_once __DIR__ . '/../../finances/lib/PostingService.php';
        $posting = new PostingService($DB, $companyId, $userId);
        $posting->postCreditNote((int)$creditNoteId);
    } catch (Exception $e) {
        error_log('Credit note journal posting failed: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'new_balance' => $newBalance]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Apply credit note error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}