<?php
// /qi/ajax/delete_invoice.php
// Delete a draft invoice (or soft-delete / force-delete in admin mode).
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/InvoiceDeleteHelper.php';

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
        echo json_encode(['ok' => true, 'mode' => 'soft']);
        exit;
    }

    if ($mode === 'force') {
        if (!$isAdmin) {
            throw new Exception('Admin privileges required for force delete');
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
    InvoiceDeleteHelper::removeCalendarEvent($DB, $invoiceId);
    echo json_encode(['ok' => true, 'mode' => 'hard']);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Delete invoice error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
