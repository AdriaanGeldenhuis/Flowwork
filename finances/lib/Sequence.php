<?php
// finances/lib/Sequence.php
// Thread-safe document numbering with per-company sequences.

class Sequence
{
    private PDO $db;
    private int $companyId;

    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * Issue next number for a doc type.
     * Options:
     *  - prefix: e.g. 'INV-{YYYY}-{MM}-'
     *  - pad: integer, default 4
     *  - period_key: optional custom key, default '{YYYY}{MM}'
     */
    public function issue(string $docType, array $opts = []): string
    {
        $pad = isset($opts['pad']) ? max(1, (int)$opts['pad']) : 4;
        $now = new DateTimeImmutable('now');
        $prefix = $opts['prefix'] ?? ($docType === 'AR-INVOICE' ? 'INV-{YYYY}-{MM}-' : $docType.'-{YYYY}-{MM}-');
        $periodKey = $opts['period_key'] ?? $now->format('Ym');

        $prefixFmt = strtr($prefix, [
            '{YYYY}' => $now->format('Y'),
            '{YY}'   => $now->format('y'),
            '{MM}'   => $now->format('m'),
        ]);

        // First issuance in a new period is racy without a unique constraint:
        // SELECT ... FOR UPDATE on a nonexistent row does not serialize two
        // concurrent INSERTs. With the UNIQUE KEY on (company_id, doc_type,
        // period_key) (see Migrations/2026-07-05-doc-sequences-unique.sql) the
        // losing INSERT fails with a duplicate-key error; we catch it and retry
        // once — by then the row exists, so SELECT ... FOR UPDATE serializes.
        $next = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $next = $this->allocateNext($docType, $periodKey, $prefixFmt, $pad);
                break;
            } catch (PDOException $e) {
                $isDuplicate = ($e->errorInfo[1] ?? null) == 1062 || $e->getCode() == '23000';
                if (!$isDuplicate || $attempt > 0) {
                    throw $e;
                }
                // Lost the first-insert race; loop and take the locked-SELECT path.
            }
        }

        $num = str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
        return $prefixFmt . $num;
    }

    /**
     * Allocate the next number inside a transaction. Transaction-aware: if the
     * caller already opened a transaction on this connection we participate in
     * it instead of calling beginTransaction() again (which would throw).
     */
    private function allocateNext(string $docType, string $periodKey, string $prefixFmt, int $pad): int
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Upsert the sequence row
            $sel = $this->db->prepare("SELECT id, last_number FROM doc_sequences WHERE company_id = :cid AND doc_type = :dt AND period_key = :pk FOR UPDATE");
            $sel->execute([':cid'=>$this->companyId, ':dt'=>$docType, ':pk'=>$periodKey]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $next = (int)$row['last_number'] + 1;
                $upd = $this->db->prepare("UPDATE doc_sequences SET last_number = :n, prefix = :p, pad = :pad, updated_at = NOW() WHERE id = :id");
                $upd->execute([':n'=>$next, ':p'=>$prefixFmt, ':pad'=>$pad, ':id'=>$row['id']]);
            } else {
                $next = 1;
                $ins = $this->db->prepare("INSERT INTO doc_sequences (company_id, doc_type, period_key, prefix, pad, last_number, updated_at) VALUES (:cid,:dt,:pk,:p,:pad,:n,NOW())");
                $ins->execute([':cid'=>$this->companyId, ':dt'=>$docType, ':pk'=>$periodKey, ':p'=>$prefixFmt, ':pad'=>$pad, ':n'=>$next]);
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $next;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
