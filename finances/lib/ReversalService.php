<?php
// finances/lib/ReversalService.php
require_once __DIR__ . '/PeriodService.php';

class ReversalService
{
    private PDO $db;
    private int $companyId;
    private PeriodService $periods;

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
        $this->periods = new PeriodService($db, $companyId);
    }

    /**
     * Create a reversing journal for an existing journal id.
     * Returns new reversing journal id, or null if the original is not found,
     * not posted, already reversed, or dated in a locked period.
     *
     * Transaction-aware: if the caller already has an open transaction on the
     * shared PDO connection this method participates in it (and never commits
     * or rolls it back itself); otherwise it manages its own transaction.
     */
    public function reverseJournal(int $journalId, int $userId, ?string $reason = null, ?string $reversalDate = null): ?int
    {
        // SARS compliance: reason is required for audit trail
        if (!$reason || trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required for journal reversals (SARS compliance)');
        }

        // Fetch original header (including status / reversal link for guards)
        $stmt = $this->db->prepare("SELECT id, entry_date, description, status, reversed_by_journal_id FROM journal_entries WHERE id = ? AND company_id = ?");
        $stmt->execute([$journalId, $this->companyId]);
        $hdr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hdr) { return null; }

        // Guard: only posted, not-yet-reversed journals may be reversed
        if (($hdr['status'] ?? '') !== 'posted') { return null; }
        if (!empty($hdr['reversed_by_journal_id'])) { return null; }

        $date = $reversalDate ?: $hdr['entry_date'];
        if ($this->periods->isLocked($date)) {
            return null;
        }

        // Fetch lines (include tax_code_id for SARS VAT audit trail)
        $lines = $this->db->prepare("SELECT account_code, debit, credit, description, tax_code_id FROM journal_lines WHERE journal_id = ?");
        $lines->execute([$journalId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { return null; }

        // Atomic reversal: insert reversal + link original must succeed together.
        // Only start (and later commit/rollback) a transaction if the caller
        // hasn't already opened one on this connection.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Create reversing header (with reference for SARS audit trail)
            $desc = 'Reversal of #' . $journalId . ' - ' . $reason;
            $reference = 'REV-' . $journalId;
            $ins = $this->db->prepare(
                "INSERT INTO journal_entries (company_id, entry_date, reference, description, module, ref_type, ref_id, source_type, source_id, status, created_by, created_at, posted_by, posted_at, reverses_journal_id)
                 VALUES (?, ?, ?, ?, 'fin', 'reversal', ?, 'reversal', ?, 'posted', ?, NOW(), ?, NOW(), ?)"
            );
            $ins->execute([$this->companyId, $date, $reference, $desc, $journalId, $journalId, $userId, $userId, $journalId]);
            $revId = (int)$this->db->lastInsertId();

            // Insert reversing lines with swapped amounts (preserve tax_code_id for SARS VAT trail)
            $insL = $this->db->prepare("INSERT INTO journal_lines (journal_id, account_code, debit, credit, description, tax_code_id) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($rows as $r) {
                $insL->execute([
                    $revId,
                    $r['account_code'],
                    $r['credit'],  // swap: original credit becomes debit
                    $r['debit'],   // swap: original debit becomes credit
                    'Reversal',
                    $r['tax_code_id'] ?? null
                ]);
            }

            // Link original journal to the reversal. The IS NULL predicate plus
            // rowCount check guard against a concurrent reversal of the same
            // journal: if another process linked a reversal first, abort so we
            // never double-post the reversing entry.
            $up1 = $this->db->prepare("UPDATE journal_entries SET reversed_by_journal_id = ? WHERE id = ? AND company_id = ? AND reversed_by_journal_id IS NULL");
            $up1->execute([$revId, $journalId, $this->companyId]);
            if ($up1->rowCount() !== 1) {
                throw new RuntimeException('Journal #' . $journalId . ' has already been reversed by another process');
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $revId;
        } catch (Throwable $e) {
            // Only roll back a transaction we started; if the caller owns the
            // transaction, rethrow and let the caller decide (rolling back the
            // caller's transaction here would break its error handling).
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Journal reversal failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
