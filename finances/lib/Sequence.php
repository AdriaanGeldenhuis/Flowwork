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

        $this->db->beginTransaction();
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
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $num = str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
        return $prefixFmt . $num;
    }
}
