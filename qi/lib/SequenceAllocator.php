<?php
/**
 * SequenceAllocator
 * Concurrency-safe allocator for Q&I numbers.
 *
 * Usage (inside your create handlers):
 *   require_once __DIR__ . '/../lib/SequenceAllocator.php';
 *   $alloc = new SequenceAllocator($pdo);
 *   [$quoteNumber, $seqNum] = $alloc->allocate((int)$company_id, 'quote', $issue_date);
 */
class SequenceAllocator {
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * @param int $companyId
     * @param string $type 'quote' | 'invoice' | 'credit_note'
     * @param string|null $issueDate 'YYYY-mm-dd'
     * @return array [string $code, int $num]
     */
    public function allocate(int $companyId, string $type, ?string $issueDate = null): array {
        $valid = ['quote','invoice','credit_note'];
        if (!in_array($type, $valid, true)) {
            throw new InvalidArgumentException("Invalid type: " . $type);
        }
        $date = $issueDate ?: date('Y-m-d');
        $year = (int) date('Y', strtotime($date));

        // SERIALIZE row for update so that two parallel requests can't get the same number.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT id, next_number FROM qi_sequences WHERE company_id=? AND type=? AND year=? FOR UPDATE");
            $stmt->execute([$companyId, $type, $year]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $ins = $this->db->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, ?, ?, 1)");
                $ins->execute([$companyId, $type, $year]);
                $id  = (int)$this->db->lastInsertId();
                $num = 1;
            } else {
                $id  = (int)$row['id'];
                $num = (int)$row['next_number'];
            }

            $upd = $this->db->prepare("UPDATE qi_sequences SET next_number = next_number + 1 WHERE id=?");
            $upd->execute([$id]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $code = $this->formatCode($type, $year, $num);
        return [$code, $num];
    }

    private function formatCode(string $type, int $year, int $num): string {
        $padded = str_pad((string)$num, 4, '0', STR_PAD_LEFT);
        if ($type === 'quote') return "Q{$year}-{$padded}";
        if ($type === 'invoice') return "INV{$year}-{$padded}";
        if ($type === 'credit_note') return "CN{$year}-{$padded}";
        throw new InvalidArgumentException('Invalid type');
    }
}
