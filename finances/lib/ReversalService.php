<?php
// finances/lib/ReversalService.php
//
// Creates linked reversing journals. Posted journals are never edited or
// deleted — a reversal is the only way to back one out (SARS immutability).
// Joins the caller's transaction when one is open, so PostingService can
// supersede-and-repost atomically.

require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/Audit.php';

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
     *
     * @param int         $journalId    journal to reverse
     * @param int         $userId       acting user (audit)
     * @param string|null $reason       mandatory (SARS audit trail)
     * @param string|null $reversalDate defaults to the original entry date
     * @return int the reversing journal id
     * @throws Exception if the journal is missing/already reversed or the
     *                   reversal date falls in a locked period
     */
    public function reverseJournal(int $journalId, int $userId, ?string $reason = null, ?string $reversalDate = null): int
    {
        if (!$reason || trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for journal reversals (SARS compliance)');
        }

        // The transaction opens BEFORE the header read so FOR UPDATE actually
        // holds: two concurrent reversals of the same journal serialize here,
        // and the loser sees reversed_by_journal_id set (double-click on
        // "Reverse" used to create two posted reversal journals).
        $ownTxn = !$this->db->inTransaction();
        if ($ownTxn) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT id, entry_date, description, reversed_by_journal_id
                   FROM journal_entries WHERE id = ? AND company_id = ? FOR UPDATE"
            );
            $stmt->execute([$journalId, $this->companyId]);
            $hdr = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$hdr) {
                throw new Exception('Journal #' . $journalId . ' not found');
            }
            if (!empty($hdr['reversed_by_journal_id'])) {
                throw new Exception('Journal #' . $journalId . ' has already been reversed');
            }

            $date = $reversalDate ?: $hdr['entry_date'];
            if ($this->periods->isLocked($date)) {
                throw new Exception('Cannot post reversal into locked period (' . $date . ')');
            }

            $lines = $this->db->prepare(
                "SELECT account_code, debit, credit, description, tax_code_id, is_capital_goods,
                        customer_id, supplier_id, employee_id, project_id, board_id, item_id, reference
                   FROM journal_lines WHERE journal_id = ?"
            );
            $lines->execute([$journalId]);
            $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                throw new Exception('Journal #' . $journalId . ' has no lines to reverse');
            }
            $desc = 'Reversal of #' . $journalId . ' - ' . $reason;
            $reference = 'REV-' . $journalId;
            $ins = $this->db->prepare(
                "INSERT INTO journal_entries
                    (company_id, entry_date, reference, description, module, ref_type, ref_id,
                     source_type, source_id, status, created_by, created_at, posted_by, posted_at,
                     reverses_journal_id)
                 VALUES (?, ?, ?, ?, 'fin', 'reversal', ?, 'reversal', ?, 'posted', ?, NOW(), ?, NOW(), ?)"
            );
            $ins->execute([$this->companyId, $date, $reference, $desc, $journalId, $journalId, $userId, $userId, $journalId]);
            $revId = (int)$this->db->lastInsertId();

            // Swap debit/credit; preserve every analytic column so reversals
            // are symmetric in VAT (tax_code_id, is_capital_goods) and in the
            // customer/supplier/project dimensions.
            $insL = $this->db->prepare(
                "INSERT INTO journal_lines
                    (journal_id, account_code, debit, credit, description, tax_code_id, is_capital_goods,
                     customer_id, supplier_id, employee_id, project_id, board_id, item_id, reference)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($rows as $r) {
                $insL->execute([
                    $revId,
                    $r['account_code'],
                    $r['credit'],
                    $r['debit'],
                    'Reversal: ' . ($r['description'] ?? ''),
                    $r['tax_code_id'] ?? null,
                    $r['is_capital_goods'] ?? 0,
                    $r['customer_id'] ?? null,
                    $r['supplier_id'] ?? null,
                    $r['employee_id'] ?? null,
                    $r['project_id'] ?? null,
                    $r['board_id'] ?? null,
                    $r['item_id'] ?? null,
                    $r['reference'] ?? null,
                ]);
            }

            // Predicate + rowCount: belt-and-braces against a concurrent
            // reversal that slipped past the lock (e.g. a caller without a
            // transaction) — the loser rolls its reversal journal back.
            $up1 = $this->db->prepare(
                "UPDATE journal_entries SET reversed_by_journal_id = ?
                  WHERE id = ? AND company_id = ? AND reversed_by_journal_id IS NULL"
            );
            $up1->execute([$revId, $journalId, $this->companyId]);
            if ($up1->rowCount() === 0) {
                throw new Exception('Journal #' . $journalId . ' has already been reversed');
            }

            Audit::log('journal_reversed', [
                'journal_id' => $journalId,
                'reversal_journal_id' => $revId,
                'reason' => $reason,
                'reversal_date' => $date,
            ], 'journal', $journalId, $this->companyId, $userId);

            if ($ownTxn) {
                $this->db->commit();
            }
            return $revId;
        } catch (Throwable $e) {
            if ($ownTxn && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Journal reversal failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
