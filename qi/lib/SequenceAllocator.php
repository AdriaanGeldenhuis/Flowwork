<?php
/**
 * SequenceAllocator
 * Concurrency-safe allocator for Q&I numbers.
 *
 * Usage (inside your create handlers):
 *   require_once __DIR__ . '/../lib/SequenceAllocator.php';
 *   $alloc = new SequenceAllocator($pdo);
 *   [$quoteNumber, $seqNum] = $alloc->allocate((int)$company_id, 'quote', $issue_date);
 *
 * The format comes from the company's Quotes & Invoices settings
 * (qi_settings: quote_prefix / invoice_prefix / credit_prefix, number_year,
 * number_digits, number_sep). The defaults reproduce the original numbers:
 * Q2026-0001, INV2026-0001, CN2026-0001. When the year is left out the
 * sequence never restarts (stored under year 0).
 *
 * A number that already exists (a prefix was changed back, documents were
 * imported, the counter was set lower) is skipped, so allocation never
 * collides with the unique keys on quotes / invoices / credit_notes.
 */
class SequenceAllocator {
    public const TYPES = ['quote', 'invoice', 'credit_note'];

    private const DEFAULT_PREFIX = ['quote' => 'Q', 'invoice' => 'INV', 'credit_note' => 'CN'];
    private const PREFIX_COLUMN = ['quote' => 'quote_prefix', 'invoice' => 'invoice_prefix', 'credit_note' => 'credit_prefix'];
    private const DOC_TABLE = ['quote' => ['quotes', 'quote_number'], 'invoice' => ['invoices', 'invoice_number'], 'credit_note' => ['credit_notes', 'credit_note_number']];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * @param int $companyId
     * @param string $type 'quote' | 'invoice' | 'credit_note'
     * @param string|null $issueDate 'YYYY-mm-dd'
     * @param bool $inTransaction When true, caller already holds a transaction — skip begin/commit/rollback
     * @return array [string $code, int $num]
     */
    public function allocate(int $companyId, string $type, ?string $issueDate = null, bool $inTransaction = false): array {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid type: " . $type);
        }
        $fmt = self::format($this->db, $companyId, $type);
        $year = self::sequenceYear($fmt, $issueDate);

        // SERIALIZE row for update so that two parallel requests can't get the same number.
        if (!$inTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // The row must exist before it can be locked; INSERT IGNORE lets the
            // first two callers of a new year both arrive here without a
            // duplicate-key failure, then FOR UPDATE serialises them.
            $this->db->prepare("INSERT IGNORE INTO qi_sequences (company_id, type, year, next_number) VALUES (?, ?, ?, 1)")
                     ->execute([$companyId, $type, $year]);
            $stmt = $this->db->prepare("SELECT id, next_number FROM qi_sequences WHERE company_id=? AND type=? AND year=? FOR UPDATE");
            $stmt->execute([$companyId, $type, $year]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Could not reserve a ' . str_replace('_', ' ', $type) . ' number');
            }
            $num = max(1, (int)$row['next_number']);
            $code = self::code($fmt, $year, $num);
            for ($guard = 0; $guard < 2000 && $this->exists($companyId, $type, $code); $guard++) {
                $num++;
                $code = self::code($fmt, $year, $num);
            }

            $upd = $this->db->prepare("UPDATE qi_sequences SET next_number = ? WHERE id=?");
            $upd->execute([$num + 1, (int)$row['id']]);
            if (!$inTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if (!$inTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [$code, $num];
    }

    /** The number the next document would get, without reserving it (editor, settings). */
    public function peek(int $companyId, string $type, ?string $issueDate = null): string {
        return $this->peekParts($companyId, $type, $issueDate)['code'];
    }

    /** @return array{code:string, num:int} */
    public function peekParts(int $companyId, string $type, ?string $issueDate = null): array {
        $fmt = self::format($this->db, $companyId, $type);
        $year = self::sequenceYear($fmt, $issueDate);
        $stmt = $this->db->prepare("SELECT next_number FROM qi_sequences WHERE company_id=? AND type=? AND year=?");
        $stmt->execute([$companyId, $type, $year]);
        $num = max(1, (int)($stmt->fetchColumn() ?: 1));
        $code = self::code($fmt, $year, $num);
        for ($guard = 0; $guard < 2000 && $this->exists($companyId, $type, $code); $guard++) {
            $code = self::code($fmt, $year, ++$num);
        }
        return ['code' => $code, 'num' => $num];
    }

    /** Settings: set the next number for this year's (or the continuous) sequence. */
    public function setNext(int $companyId, string $type, int $next, ?string $issueDate = null): void {
        $fmt = self::format($this->db, $companyId, $type);
        $year = self::sequenceYear($fmt, $issueDate);
        $this->db->prepare("INSERT INTO qi_sequences (company_id, type, year, next_number) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE next_number = VALUES(next_number)")
                 ->execute([$companyId, $type, $year, max(1, $next)]);
    }

    /** What a number looks like with the given settings (settings preview). */
    public static function sample(array $settings, string $type, int $num = 1, ?string $date = null): string {
        $fmt = self::formatFrom($settings, $type);
        return self::code($fmt, self::sequenceYear($fmt, $date), $num);
    }

    // ------------------------------------------------------------------

    private function exists(int $companyId, string $type, string $code): bool {
        [$table, $col] = self::DOC_TABLE[$type];
        $st = $this->db->prepare("SELECT 1 FROM `$table` WHERE company_id = ? AND `$col` = ? LIMIT 1");
        $st->execute([$companyId, $code]);
        return (bool)$st->fetchColumn();
    }

    private static function format(PDO $db, int $companyId, string $type): array {
        $row = [];
        try {
            $st = $db->prepare("SELECT * FROM qi_settings WHERE company_id = ? LIMIT 1");
            $st->execute([$companyId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = []; // settings table/columns not provisioned yet: defaults
        }
        return self::formatFrom($row, $type);
    }

    private static function formatFrom(array $row, string $type): array {
        $prefix = (string)($row[self::PREFIX_COLUMN[$type]] ?? '');
        $prefix = preg_replace('/[^A-Za-z0-9\/._-]/', '', $prefix) ?? '';
        if ($prefix === '' && !array_key_exists(self::PREFIX_COLUMN[$type], $row)) {
            $prefix = self::DEFAULT_PREFIX[$type];
        }
        $sep = (string)($row['number_sep'] ?? '-');
        if (!in_array($sep, ['', '-', '/', '.', '_'], true)) {
            $sep = '-';
        }
        $digits = (int)($row['number_digits'] ?? 4);
        return [
            'prefix' => $prefix,
            'year'   => !array_key_exists('number_year', $row) || (int)$row['number_year'] === 1,
            'digits' => max(1, min(8, $digits ?: 4)),
            'sep'    => $sep,
        ];
    }

    private static function sequenceYear(array $fmt, ?string $date): int {
        if (!$fmt['year']) {
            return 0;
        }
        $ts = $date ? strtotime($date) : false;
        return (int)date('Y', $ts ?: time());
    }

    private static function code(array $fmt, int $year, int $num): string {
        $padded = str_pad((string)$num, $fmt['digits'], '0', STR_PAD_LEFT);
        $head = $fmt['prefix'] . ($fmt['year'] ? (string)$year : '');
        return $head === '' ? $padded : $head . $fmt['sep'] . $padded;
    }
}
