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
     * Returns new reversing journal id, or null if original not found or locked.
     */
    public function reverseJournal(int $journalId, int $userId, ?string $reason = null, ?string $reversalDate = null): ?int
    {
        // Fetch original header
        $stmt = $this->db->prepare("SELECT id, entry_date, description FROM journal_entries WHERE id = ? AND company_id = ?");
        $stmt->execute([$journalId, $this->companyId]);
        $hdr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hdr) { return null; }

        $date = $reversalDate ?: $hdr['entry_date'];
        if ($this->periods->isLocked($date)) {
            return null;
        }

        // Fetch lines
        $lines = $this->db->prepare("SELECT account_code, debit, credit, description FROM journal_lines WHERE journal_id = ?");
        $lines->execute([$journalId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { return null; }

        // Create reversing header
        $desc = 'Reversal of #' . $journalId . ($reason ? ' - ' . $reason : '');
        $ins = $this->db->prepare(
            "INSERT INTO journal_entries (company_id, entry_date, description, module, ref_type, ref_id, source_type, source_id, status, created_by, created_at, posted_by, posted_at, reverses_journal_id)
             VALUES (?, ?, ?, 'fin', 'reversal', ?, 'reversal', ?, 'posted', ?, NOW(), ?, NOW(), ?)"
        );
        $ins->execute([$this->companyId, $date, $desc, $journalId, $journalId, $userId, $userId, $journalId]);
        $revId = (int)$this->db->lastInsertId();

        // Insert reversing lines with swapped amounts
        $insL = $this->db->prepare("INSERT INTO journal_lines (journal_id, account_code, debit, credit, description) VALUES (?, ?, ?, ?, ?)");
        foreach ($rows as $r) {
            $insL->execute([
                $revId,
                $r['account_code'],
                $r['credit'],  // swap: original credit becomes debit
                $r['debit'],   // swap: original debit becomes credit
                'Reversal'
            ]);
        }

        // Link original journal to the reversal
        try {
            $up1 = $this->db->prepare("UPDATE journal_entries SET reversed_by_journal_id = ? WHERE id = ? AND company_id = ?");
            $up1->execute([$revId, $journalId, $this->companyId]);
        } catch (Throwable $e) {}

        return $revId;
    }
}
