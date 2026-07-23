<?php
// /qi/ajax/delete_invoice.php
// Delete a draft invoice (or soft-delete / force-delete in admin mode).
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/require_writer.php';
require_once __DIR__ . '/../lib/InvoiceDeleteHelper.php';
require_once __DIR__ . '/../services/DocumentPdfService.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$invoiceId = (int)($input['invoice_id'] ?? 0);
$mode      = $input['mode']   ?? 'hard';   // hard | soft | force
$reason    = trim((string)($input['reason'] ?? ''));

if (!$invoiceId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    $invoice = InvoiceDeleteHelper::fetchInvoice($DB, $invoiceId, $companyId);
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    $isAdmin = !empty($_SESSION['is_admin']) || ($_SESSION['role'] ?? '') === 'admin';

    if ($mode === 'soft') {
        // A draft has no journal, so hiding it is harmless. A POSTED invoice
        // hidden by soft-delete keeps its revenue/VAT/AR-debit in the GL while
        // vanishing from every AR view — so it may only be soft-deleted by an
        // admin, with no payments/credits still allocated, and its journal
        // reversed in the same transaction. Void / Write Off are the normal
        // routes for an issued invoice.
        if ($invoice['status'] !== 'draft') {
            if (!$isAdmin) {
                throw new Exception('Only an admin can delete an issued invoice. Use Void, Write Off or Archive instead.');
            }
            $allocated = (float)$DB->query("SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id = " . (int)$invoiceId)->fetchColumn()
                       + (float)$DB->query("SELECT COALESCE(SUM(amount),0) FROM credit_note_allocations WHERE invoice_id = " . (int)$invoiceId)->fetchColumn();
            if ($allocated > 0) {
                throw new Exception('Unapply payments and credit notes before deleting this invoice.');
            }
            // A partial write-off's separate Cr AR journal is not touched by
            // reverseJournal (which only reverses the invoice journal).
            if ((float)($invoice['write_off_amount'] ?? 0) > 0) {
                throw new Exception('Reverse the write-off posted against this invoice before deleting it.');
            }
            InvoiceDeleteHelper::reverseJournal($DB, $companyId, $userId, $invoiceId);
        }
        $stmt = $DB->prepare("
            UPDATE invoices
               SET deleted_at = NOW(), deleted_by = ?, delete_reason = ?
             WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$userId, $reason ?: null, $invoiceId, $companyId]);

        InvoiceDeleteHelper::removeCalendarEvent($DB, $invoiceId);
        InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_soft_deleted', [
            'invoice_id' => $invoiceId, 'reason' => $reason,
        ]);
        $DB->commit();
        DocumentPdfService::removeFromDrive($DB, (int)$companyId, 'invoice', (int)$invoice['customer_id'], (string)$invoice['invoice_number']);
        echo json_encode(['ok' => true, 'mode' => 'soft']);
        exit;
    }

    if ($mode === 'force') {
        if (!$isAdmin) {
            throw new Exception('Admin privileges required for force delete');
        }
        // Force-delete reverses only the invoice journal; its payment and
        // credit-note journals (Cr AR) would stay posted and strand AR control
        // negative. Require them unapplied first.
        $allocated = (float)$DB->query("SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id = " . (int)$invoiceId)->fetchColumn()
                   + (float)$DB->query("SELECT COALESCE(SUM(amount),0) FROM credit_note_allocations WHERE invoice_id = " . (int)$invoiceId)->fetchColumn();
        if ($allocated > 0) {
            throw new Exception('Unapply all payments and credit notes before force-deleting this invoice.');
        }
        if ((float)($invoice['write_off_amount'] ?? 0) > 0) {
            throw new Exception('Reverse the write-off posted against this invoice before force-deleting it.');
        }
        InvoiceDeleteHelper::reverseJournal($DB, $companyId, $userId, $invoiceId);
        InvoiceDeleteHelper::purgeChildren($DB, $invoiceId, $companyId);

        $stmt = $DB->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([$invoiceId, $companyId]);

        InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_force_deleted', [
            'invoice_id' => $invoiceId, 'reason' => $reason,
            'prior_status' => $invoice['status'],
        ]);
        $DB->commit();
        DocumentPdfService::removeFromDrive($DB, (int)$companyId, 'invoice', (int)$invoice['customer_id'], (string)$invoice['invoice_number']);
        InvoiceDeleteHelper::removeCalendarEvent($DB, $invoiceId);
        echo json_encode(['ok' => true, 'mode' => 'force']);
        exit;
    }

    // Default: hard delete, draft only
    if ($invoice['status'] !== 'draft') {
        throw new Exception('Only draft invoices can be deleted. Try Void, Write Off, or Archive instead.');
    }

    InvoiceDeleteHelper::purgeChildren($DB, $invoiceId, $companyId);

    $stmt = $DB->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$invoiceId, $companyId]);

    InvoiceDeleteHelper::log($DB, $companyId, $userId, 'invoice_deleted', [
        'invoice_id' => $invoiceId,
    ]);

    $DB->commit();
    DocumentPdfService::removeFromDrive($DB, (int)$companyId, 'invoice', (int)$invoice['customer_id'], (string)$invoice['invoice_number']);
    InvoiceDeleteHelper::removeCalendarEvent($DB, $invoiceId);
    echo json_encode(['ok' => true, 'mode' => 'hard']);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Delete invoice error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
