<?php
// finances/lib/ArService.php
//
// A thin wrapper service for Accounts Receivable (AR) transactions.
// This service delegates the creation of accounting entries for
// invoices, payments and credit notes to the central PostingService.
// By encapsulating these calls, the rest of the application can
// interact with a simpler API and avoid direct dependencies on
// PostingService throughout non‑financial modules.

require_once __DIR__ . '/PostingService.php';

class ArService
{
    /**
     * @var PostingService The posting service used to create GL entries
     */
    private $postingService;

    /**
     * Database connection
     *
     * @var PDO
     */
    private $db;

    /**
     * Current company id
     *
     * @var int
     */
    private $companyId;

    /**
     * Constructor.
     *
     * @param PDO $db        PDO connection to the database
     * @param int $companyId The current company identifier
     * @param int $userId    The user performing the posting
     */
    public function __construct(PDO $db, int $companyId, int $userId)
    {
        $this->postingService = new PostingService($db, $companyId, $userId);
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * Post an invoice to the general ledger.
     *
     * This method simply delegates to PostingService::postInvoice(). If
     * the invoice has already been posted, the existing journal will be
     * replaced (subject to period locks enforced by PostingService).
     *
     * @param int $invoiceId The ID of the invoice to post
     * @throws Exception If PostingService encounters an error
     */
    public function postInvoice(int $invoiceId): void
    {
        // Post the invoice using PostingService
        $this->postingService->postInvoice($invoiceId);
        // After posting, attempt to tag the resulting journal lines with project context
        try {
            $this->updateInvoiceJournalProjectContext($invoiceId);
        } catch (\Exception $ex) {
            // Silently ignore tagging errors; posting already completed
            error_log('AR project context tagging failed (invoice): ' . $ex->getMessage());
        }
    }

    /**
     * Post a customer payment to the general ledger.
     *
     * Delegates to PostingService::postCustomerPayment(). Use this after
     * recording a payment in the payments table to ensure the GL is
     * updated. The posting service will enforce period locks and
     * remove/repost existing journals as needed.
     *
     * @param int $paymentId The ID of the customer payment
     * @throws Exception If PostingService encounters an error
     */
    public function postCustomerPayment(int $paymentId): void
    {
        // Post the payment using PostingService
        $this->postingService->postCustomerPayment($paymentId);
        // After posting, attempt to tag the journal lines with project context per allocation
        try {
            $this->updatePaymentJournalProjectContext($paymentId);
        } catch (\Exception $ex) {
            // Logging only; do not interrupt posting flow
            error_log('AR project context tagging failed (payment): ' . $ex->getMessage());
        }
    }

    /**
     * Post a credit note to the general ledger.
     *
     * Delegates to PostingService::postCreditNote(). The posting service
     * will handle reversing revenue and VAT and updating the invoice
     * balance as necessary.
     *
     * @param int $creditNoteId The ID of the credit note to post
     * @throws Exception If PostingService encounters an error
     */
    public function postCreditNote(int $creditNoteId): void
    {
        // Post the credit note via PostingService
        $this->postingService->postCreditNote($creditNoteId);
        // After posting, attempt to tag the journal lines with the project context from the original invoice
        try {
            $this->updateCreditNoteJournalProjectContext($creditNoteId);
        } catch (\Exception $ex) {
            error_log('AR project context tagging failed (credit note): ' . $ex->getMessage());
        }
    }

    /**
     * Update all journal lines for a given journal with project, board and item context.
     * If no project id is supplied, the update is skipped. Board and item ids
     * default to null when not provided.
     *
     * @param int      $journalId
     * @param int|null $projectId
     * @param int|null $boardId
     * @param int|null $itemId
     * @return void
     */
    private function updateJournalProjectContext(int $journalId, ?int $projectId, ?int $boardId = null, ?int $itemId = null): void
    {
        if (!$journalId || !$projectId) {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE journal_lines SET project_id = ?, board_id = ?, item_id = ? WHERE journal_id = ?"
        );
        $stmt->execute([$projectId, $boardId, $itemId, $journalId]);
    }

    /**
     * Fetch the journal id for a given invoice and, if a project id is present,
     * tag the corresponding journal lines with that project id.
     *
     * @param int $invoiceId
     * @return void
     */
    private function updateInvoiceJournalProjectContext(int $invoiceId): void
    {
        $stmt = $this->db->prepare(
            "SELECT project_id, journal_id FROM invoices WHERE id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$invoiceId, $this->companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['journal_id']) && !empty($row['project_id'])) {
            $this->updateJournalProjectContext((int)$row['journal_id'], (int)$row['project_id']);
        }
    }

    /**
     * For a payment, update each credit journal line referencing an invoice
     * with the project id of that invoice. This uses the reference field
     * on journal_lines (invoice number) to match lines to invoices.
     *
     * @param int $paymentId
     * @return void
     */
    private function updatePaymentJournalProjectContext(int $paymentId): void
    {
        // Fetch journal id for this payment
        $stmt = $this->db->prepare(
            "SELECT journal_id FROM payments WHERE id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$paymentId, $this->companyId]);
        $journalId = $stmt->fetchColumn();
        if (!$journalId) {
            return;
        }
        // Fetch allocations with invoice numbers and project ids
        $allocStmt = $this->db->prepare(
            "SELECT pa.invoice_id, i.invoice_number, i.project_id
             FROM payment_allocations pa
             LEFT JOIN invoices i ON pa.invoice_id = i.id
             WHERE pa.payment_id = ?"
        );
        $allocStmt->execute([$paymentId]);
        $allocs = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$allocs) {
            return;
        }
        // Prepare update statement
        $updStmt = $this->db->prepare(
            "UPDATE journal_lines SET project_id = ? WHERE journal_id = ? AND reference = ?"
        );
        foreach ($allocs as $al) {
            $projId = $al['project_id'] ?? null;
            $invNum = $al['invoice_number'] ?? null;
            if ($projId && $invNum) {
                $updStmt->execute([$projId, $journalId, $invNum]);
            }
        }
    }

    /**
     * Update journal lines for a credit note with the project id from the
     * original invoice (if any).
     *
     * @param int $creditNoteId
     * @return void
     */
    private function updateCreditNoteJournalProjectContext(int $creditNoteId): void
    {
        $stmt = $this->db->prepare(
            "SELECT cn.journal_id, i.project_id
             FROM credit_notes cn
             LEFT JOIN invoices i ON cn.invoice_id = i.id
             WHERE cn.id = ? AND cn.company_id = ? LIMIT 1"
        );
        $stmt->execute([$creditNoteId, $this->companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['journal_id']) && !empty($row['project_id'])) {
            $this->updateJournalProjectContext((int)$row['journal_id'], (int)$row['project_id']);
        }
    }
}
