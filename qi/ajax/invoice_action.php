<?php
// /qi/ajax/invoice_action.php
// Umbrella endpoint for status-change / archive / restore style actions on an invoice.
// Complex flows (credit, refund, duplicate, force delete) have their own endpoints.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/InvoiceDeleteHelper.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$invoiceId = (int)($input['invoice_id'] ?? 0);
$action    = (string)($input['action'] ?? '');
$reason    = trim((string)($input['reason'] ?? ''));
$amount    = isset($input['amount']) ? (float)$input['amount'] : null;

$ALLOWED = ['void','cancel','revert_to_draft','write_off','mark_uncollectible',
            'archive','unarchive','restore','unapply_payments'];

if (!$invoiceId || !in_array($action, $ALLOWED, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    // Row lock: balance/status checks below are check-then-act — concurrent
    // actions on the same invoice (double-click write-off) must serialize.
    $invoice = InvoiceDeleteHelper::fetchInvoice($DB, $invoiceId, $companyId, true);
    if (!$invoice) throw new Exception('Invoice not found');

    $status = $invoice['status'];

    switch ($action) {
        case 'void':
        case 'cancel':
            if (in_array($status, ['cancelled','written_off','refunded'], true)) {
                throw new Exception('Invoice is already closed (' . $status . ')');
            }
            // Voiding reverses the invoice journal; allocated payments' posted
            // journals (Cr AR) would stay behind and drive the AR control
            // negative. Same rule as revert_to_draft: detach payments first.
            $paidStmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id = ?");
            $paidStmt->execute([$invoiceId]);
            if ((float)$paidStmt->fetchColumn() > 0) {
                throw new Exception('Cannot void: payments have been allocated to this invoice. Unapply payments first.');
            }
            // Applied credit notes also credit AR; voiding the invoice would
            // reverse only its own journal and strand the credit note's Cr AR.
            $cnStmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM credit_note_allocations WHERE invoice_id = ?");
            $cnStmt->execute([$invoiceId]);
            if ((float)$cnStmt->fetchColumn() > 0) {
                throw new Exception('Cannot void: credit notes have been applied to this invoice. Unapply the credit notes first.');
            }
            // Zero the balance so the cancelled invoice is no longer payable
            // (record_payment.php also refuses cancelled invoices, but a stale
            // balance_due would still show it as outstanding in AR views).
            $stmt = $DB->prepare("
                UPDATE invoices
                   SET status='cancelled', balance_due = 0, cancellation_reason = ?, updated_at = NOW()
                 WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$reason ?: null, $invoiceId, $companyId]);
            // Reverse the GL journal (throws on locked period, rolling back
            // the void — a cancelled invoice must not keep revenue/VAT posted)
            require_once __DIR__ . '/../../finances/lib/PostingService.php';
            (new PostingService($DB, $companyId, $userId))
                ->unpostInvoice($invoiceId, 'Invoice voided' . ($reason ? ': ' . $reason : ''));
            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_voided',
                ['invoice_id'=>$invoiceId, 'prior_status'=>$status, 'reason'=>$reason]);
            break;

        case 'revert_to_draft':
            if (!in_array($status, ['sent','viewed','overdue','cancelled'], true)) {
                throw new Exception('Only sent/viewed/overdue/cancelled invoices can revert to draft');
            }
            // Block revert if any payments OR credit notes have been allocated —
            // reverting unposts the invoice journal and would strand their Cr AR.
            $paidStmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id = ?");
            $paidStmt->execute([$invoiceId]);
            if ((float)$paidStmt->fetchColumn() > 0) {
                throw new Exception('Cannot revert: payments have been allocated. Unapply first.');
            }
            $cnStmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM credit_note_allocations WHERE invoice_id = ?");
            $cnStmt->execute([$invoiceId]);
            if ((float)$cnStmt->fetchColumn() > 0) {
                throw new Exception('Cannot revert: credit notes have been applied. Unapply the credit notes first.');
            }
            $stmt = $DB->prepare("UPDATE invoices SET status='draft', updated_at=NOW() WHERE id=? AND company_id=?");
            $stmt->execute([$invoiceId, $companyId]);
            // A draft is not a tax invoice — its journal must leave the GL.
            require_once __DIR__ . '/../../finances/lib/PostingService.php';
            (new PostingService($DB, $companyId, $userId))
                ->unpostInvoice($invoiceId, 'Invoice reverted to draft');
            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_reverted_to_draft',
                ['invoice_id'=>$invoiceId, 'prior_status'=>$status]);
            break;

        case 'write_off':
            if (!in_array($status, ['sent','viewed','overdue','part-paid'], true)) {
                throw new Exception('Only open invoices can be written off');
            }
            $writeAmount = ($amount !== null && $amount > 0) ? $amount : (float)$invoice['balance_due'];
            if ($writeAmount <= 0) throw new Exception('Nothing to write off');
            if ($writeAmount > (float)$invoice['balance_due'] + 0.01) {
                throw new Exception('Write-off amount exceeds outstanding balance');
            }
            $newBalance = max(0.0, (float)$invoice['balance_due'] - $writeAmount);
            $newStatus  = ($newBalance <= 0.01) ? 'written_off' : $status;
            $stmt = $DB->prepare("
                UPDATE invoices
                   SET balance_due = ?, status = ?,
                       write_off_amount = COALESCE(write_off_amount,0) + ?,
                       write_off_at = NOW(), write_off_by = ?,
                       delete_reason = ?, updated_at = NOW()
                 WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$newBalance, $newStatus, $writeAmount, $userId, $reason ?: null, $invoiceId, $companyId]);
            // GL: DR Bad Debts (net) + DR VAT Input (s22 relief) / CR AR.
            // Previously only balance_due changed — the AR control account,
            // P&L and VAT201 never saw the write-off.
            require_once __DIR__ . '/../../finances/lib/PostingService.php';
            (new PostingService($DB, $companyId, $userId))
                ->postInvoiceWriteOff($invoiceId, $writeAmount, $reason);
            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_written_off',
                ['invoice_id'=>$invoiceId, 'amount'=>$writeAmount, 'reason'=>$reason]);
            break;

        case 'mark_uncollectible':
            if (!in_array($status, ['sent','viewed','overdue','part-paid'], true)) {
                throw new Exception('Only open invoices can be marked uncollectible');
            }
            $stmt = $DB->prepare("UPDATE invoices SET status='uncollectible', delete_reason=?, updated_at=NOW() WHERE id=? AND company_id=?");
            $stmt->execute([$reason ?: null, $invoiceId, $companyId]);
            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_marked_uncollectible',
                ['invoice_id'=>$invoiceId, 'reason'=>$reason]);
            break;

        case 'archive':
            $stmt = $DB->prepare("UPDATE invoices SET archived_at=NOW(), archived_by=? WHERE id=? AND company_id=?");
            $stmt->execute([$userId, $invoiceId, $companyId]);
            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_archived', ['invoice_id'=>$invoiceId]);
            break;

        case 'unarchive':
            $stmt = $DB->prepare("UPDATE invoices SET archived_at=NULL, archived_by=NULL WHERE id=? AND company_id=?");
            $stmt->execute([$invoiceId, $companyId]);
            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_unarchived', ['invoice_id'=>$invoiceId]);
            break;

        case 'restore':
            if (empty($invoice['deleted_at'])) throw new Exception('Invoice is not soft-deleted');
            $stmt = $DB->prepare("UPDATE invoices SET deleted_at=NULL, deleted_by=NULL, delete_reason=NULL WHERE id=? AND company_id=?");
            $stmt->execute([$invoiceId, $companyId]);
            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_restored', ['invoice_id'=>$invoiceId]);
            break;

        case 'unapply_payments':
            // Detach any payment allocations so the invoice can be voided / reverted
            $total = (float)$DB->query(
                "SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id = " . (int)$invoiceId
            )->fetchColumn();
            if ($total <= 0) throw new Exception('No payments to unapply');

            $stmt = $DB->prepare("DELETE FROM payment_allocations WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);

            // Reset milestone tracking on this invoice, then re-arm the first phase
            $stmt = $DB->prepare("
                UPDATE payment_milestones
                   SET amount_paid = 0, status = 'pending'
                 WHERE entity_type='invoice' AND entity_id = ? AND company_id = ?
            ");
            $stmt->execute([$invoiceId, $companyId]);

            $stmt = $DB->prepare("
                UPDATE payment_milestones
                   SET status = 'due'
                 WHERE entity_type='invoice' AND entity_id = ? AND company_id = ?
                 ORDER BY sort_order ASC, id ASC
                 LIMIT 1
            ");
            $stmt->execute([$invoiceId, $companyId]);

            // Recompute balance and status
            $stmt = $DB->prepare("
                UPDATE invoices
                   SET balance_due = total, paid_at = NULL,
                       status = CASE WHEN status IN ('paid','part-paid') THEN 'sent' ELSE status END,
                       updated_at = NOW()
                 WHERE id = ? AND company_id = ?
            ");
            $stmt->execute([$invoiceId, $companyId]);

            InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_payments_unapplied',
                ['invoice_id'=>$invoiceId, 'amount_detached'=>$total]);
            break;
    }

    $DB->commit();
    echo json_encode(['ok' => true, 'action' => $action]);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Invoice action error: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'The action failed — please try again' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
}
