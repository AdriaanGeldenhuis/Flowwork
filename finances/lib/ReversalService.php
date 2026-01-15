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
    public function reverseJournal(int $journalId, ?string $reason = null, ?string $reversalDate = null): ?int
    {
        // Fetch original header
        $stmt = $this->db->prepare("SELECT id, entry_date, memo FROM journal_entries WHERE id = ? AND company_id = ?");
        $stmt->execute([$journalId, $this->companyId]);
        $hdr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hdr) { return null; }

        $date = $reversalDate ?: $hdr['entry_date'];
        if ($this->periods->isLocked($date)) {
            return null;
        }

        // Fetch lines
        $lines = $this->db->prepare("SELECT gl_account_id, debit_cents, credit_cents, description FROM journal_lines WHERE journal_id = ?");
        $lines->execute([$journalId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { return null; }

        // Create reversing header
        $memo = 'Reversal of #' . $journalId . ($reason ? ' - ' . $reason : '');
        $ins = $this->db->prepare("INSERT INTO journal_entries (company_id, entry_date, memo, created_at) VALUES (?, ?, ?, NOW())");
        $ins->execute([$this->companyId, $date, $memo]);
        $revId = (int)$this->db->lastInsertId();

        // Insert reversing lines with swapped amounts
        $insL = $this->db->prepare("INSERT INTO journal_lines (journal_id, gl_account_id, debit_cents, credit_cents, description) VALUES (?, ?, ?, ?, ?)");
        foreach ($rows as $r) {
            $insL->execute([
                $revId,
                (int)$r['gl_account_id'],
                (int)$r['credit_cents'],  // swap
                (int)$r['debit_cents'],
                'Reversal'
            ]);
        }

        // Try to link if columns exist (best-effort)
        try {
            $up1 = $this->db->prepare("UPDATE journal_entries SET reversed_by_journal_id = ? WHERE id = ? AND company_id = ?");
            $up1->execute([$revId, $journalId, $this->companyId]);
        } catch (Throwable $e) {}
        try {
            $up2 = $this->db->prepare("UPDATE journal_entries SET reverses_journal_id = ? WHERE id = ? AND company_id = ?");
            $up2->execute([$journalId, $revId, $this->companyId]);
        } catch (Throwable $e) {}

        return $revId;
    }
}
