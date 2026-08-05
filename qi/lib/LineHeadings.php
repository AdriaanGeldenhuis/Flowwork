<?php
// /qi/lib/LineHeadings.php
// Section headings for quote/invoice line items (board-group style).
//
// Headings live in their own table (qi_line_headings) rather than in
// quote_lines / invoice_lines: the GL posting engine, VAT201 calculator,
// SARS audit file, credit notes and the project P&L all read the line tables
// and must never encounter zero-amount pseudo-lines. Headings share the
// lines' sort_order sequence, so merging the two sets by sort_order yields
// the document order the user arranged on the editor.

class LineHeadings
{
    public const TYPE_QUOTE = 'quote';
    public const TYPE_INVOICE = 'invoice';

    /**
     * Create the table if it doesn't exist yet. DDL implicitly commits in
     * MySQL, so call this BEFORE beginTransaction(), never inside one.
     */
    public static function ensureTable(PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS qi_line_headings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT NOT NULL,
                parent_type VARCHAR(10) NOT NULL,
                parent_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_qlh_parent (parent_type, parent_id, sort_order),
                KEY idx_qlh_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * All headings for a document, in order. Returns [] when the table does
     * not exist yet, so documents render fine before the migration runs.
     */
    public static function fetch(PDO $db, string $parentType, int $parentId): array
    {
        try {
            $stmt = $db->prepare(
                "SELECT id, title, sort_order FROM qi_line_headings
                  WHERE parent_type = ? AND parent_id = ?
                  ORDER BY sort_order, id"
            );
            $stmt->execute([$parentType, $parentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Replace a document's headings. $headings entries: ['title' => string,
     * 'sort_order' => int]. Blank titles are skipped. Safe inside the caller's
     * transaction (run ensureTable() before opening it).
     */
    public static function replace(PDO $db, int $companyId, string $parentType, int $parentId, array $headings): void
    {
        $del = $db->prepare("DELETE FROM qi_line_headings WHERE parent_type = ? AND parent_id = ?");
        $del->execute([$parentType, $parentId]);

        if (empty($headings)) {
            return;
        }
        $ins = $db->prepare(
            "INSERT INTO qi_line_headings (company_id, parent_type, parent_id, title, sort_order)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($headings as $h) {
            $title = trim((string)($h['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $ins->execute([
                $companyId,
                $parentType,
                $parentId,
                mb_substr($title, 0, 255),
                (int)($h['sort_order'] ?? 0),
            ]);
        }
    }

    /**
     * Copy one document's headings onto another (quote -> invoice conversion,
     * duplicates). Best-effort: never breaks the surrounding financial write.
     */
    public static function copy(PDO $db, int $companyId, string $fromType, int $fromId, string $toType, int $toId): void
    {
        try {
            $rows = self::fetch($db, $fromType, $fromId);
            if (empty($rows)) {
                return;
            }
            $ins = $db->prepare(
                "INSERT INTO qi_line_headings (company_id, parent_type, parent_id, title, sort_order)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($rows as $r) {
                $ins->execute([$companyId, $toType, $toId, $r['title'], (int)$r['sort_order']]);
            }
        } catch (Throwable $e) {
            error_log('LineHeadings::copy: ' . $e->getMessage());
        }
    }

    /** Delete a document's headings (document delete). Best-effort. */
    public static function delete(PDO $db, string $parentType, int $parentId): void
    {
        try {
            $stmt = $db->prepare("DELETE FROM qi_line_headings WHERE parent_type = ? AND parent_id = ?");
            $stmt->execute([$parentType, $parentId]);
        } catch (Throwable $e) {
            error_log('LineHeadings::delete: ' . $e->getMessage());
        }
    }

    /**
     * Interleave line rows and heading rows into document order.
     * Each returned row gains '_kind' => 'item' | 'heading'; heading rows
     * carry their title in 'item_description' too, so existing render loops
     * can treat rows uniformly. Ties sort heading-first (a heading introduces
     * the item at the same position).
     */
    public static function merge(array $lines, array $headings): array
    {
        if (empty($headings)) {
            foreach ($lines as &$l) {
                $l['_kind'] = 'item';
            }
            return $lines;
        }

        $rows = [];
        $seq = 0;
        foreach ($lines as $l) {
            $l['_kind'] = 'item';
            $rows[] = ['sort' => (int)($l['sort_order'] ?? 0), 'rank' => 1, 'seq' => $seq++, 'row' => $l];
        }
        foreach ($headings as $h) {
            $row = [
                '_kind' => 'heading',
                'item_description' => (string)($h['title'] ?? ''),
                'title' => (string)($h['title'] ?? ''),
                'sort_order' => (int)($h['sort_order'] ?? 0),
            ];
            $rows[] = ['sort' => $row['sort_order'], 'rank' => 0, 'seq' => $seq++, 'row' => $row];
        }
        usort($rows, function ($a, $b) {
            return [$a['sort'], $a['rank'], $a['seq']] <=> [$b['sort'], $b['rank'], $b['seq']];
        });
        return array_map(fn($r) => $r['row'], $rows);
    }
}
