<?php
// /qi/lib/InvoiceLifecycle.php
//
// Invoice issue lifecycle. Invoices post to the general ledger when they are
// ISSUED (leave draft), not while they are being drafted — a draft is not a
// tax invoice and must not appear in the GL or the VAT201. All GL work goes
// through finances/lib/PostingService (the canonical engine).

require_once __DIR__ . '/../../finances/lib/PostingService.php';
require_once __DIR__ . '/../../finances/lib/Audit.php';

class InvoiceLifecycle
{
    /**
     * Issue an invoice: transition draft -> sent (unless the caller manages
     * status itself) and post it to the GL. Safe to call on an already-issued
     * invoice — posting is skipped when a journal already exists (edits are
     * only possible in draft, so an existing journal is current).
     *
     * When a validation callback is registered (s20 tax-invoice checks), it
     * runs before anything changes and blocks the issue on failure.
     *
     * @return bool true when a journal was posted by this call
     */
    public static function issueInvoice(PDO $db, int $companyId, int $userId, int $invoiceId, bool $updateStatus = true): bool
    {
        $stmt = $db->prepare("SELECT id, status, journal_id, project_id, invoice_number
                                FROM invoices WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$invoiceId, $companyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new Exception('Invoice not found');
        }

        if (self::$issueValidator) {
            (self::$issueValidator)($db, $companyId, $invoiceId);
        }

        if ($updateStatus && $invoice['status'] === 'draft') {
            $db->prepare("UPDATE invoices SET status = 'sent', updated_at = NOW()
                           WHERE id = ? AND company_id = ?")
               ->execute([$invoiceId, $companyId]);
        }

        if (!empty($invoice['journal_id'])) {
            return false; // already in the GL
        }

        $posting = new PostingService($db, $companyId, $userId);
        $posting->postInvoice($invoiceId, $invoice['project_id'] ? (int)$invoice['project_id'] : null);

        Audit::log('invoice_issued', [
            'invoice_number' => $invoice['invoice_number'],
            'prior_status' => $invoice['status'],
        ], 'invoice', $invoiceId, $companyId, $userId);
        return true;
    }

    /** Optional s20 validation hook: fn(PDO $db, int $companyId, int $invoiceId): void (throws to block). */
    private static $issueValidator = null;

    public static function setIssueValidator(?callable $fn): void
    {
        self::$issueValidator = $fn;
    }
}
