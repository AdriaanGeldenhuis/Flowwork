<?php
// /receipts/lib/RcLearn.php — what Receipts remembers from the bills people
// capture, so the next slip from the same place fills itself in:
//   - the supplier a printed name belongs to ("ENGEN FOURWAYS" → Engen)   ai_hints (type vendor)
//   - the account a supplier's bills go to, and the account words point
//     to ("diesel" → Motor vehicle expenses)                             company_settings.receipts_ai_map
//   - the project board a supplier's bills went to last
// Everything can be looked at and forgotten under Settings → Learning.

final class RcLearn
{
    private const MAP_KEY = 'receipts_ai_map';
    private const MAX_WORDS = 1500;
    private const STOP = ['with', 'from', 'this', 'that', 'each', 'item', 'items', 'unit', 'units', 'pack', 'size', 'large', 'small', 'total', 'price', 'amount',
                          'incl', 'excl', 'qty', 'invoice', 'receipt', 'slip', 'tax', 'vat', 'goods', 'sundry', 'sundries', 'misc', 'other', 'various', 'the', 'and', 'for'];

    public static function map(PDO $db, int $companyId): array
    {
        $map = ['supplier_default_gl' => [], 'token_map' => [], 'supplier_board' => []];
        try {
            $st = $db->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1");
            $st->execute([$companyId, self::MAP_KEY]);
            $d = json_decode((string)$st->fetchColumn(), true);
            if (is_array($d)) foreach ($map as $k => $_) if (is_array($d[$k] ?? null)) $map[$k] = $d[$k];
        } catch (Throwable $e) {
        }
        return $map;
    }

    private static function saveMap(PDO $db, int $companyId, array $map): void
    {
        $db->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
           ->execute([$companyId, self::MAP_KEY, json_encode($map, JSON_UNESCAPED_UNICODE)]);
    }

    /** Words worth remembering in a line description. */
    public static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($words, fn($w) => mb_strlen($w) >= 4 && !in_array($w, self::STOP, true))));
    }

    public static function key(string $vendorName): string
    {
        return mb_substr(mb_strtolower(trim(preg_replace('/\s+/', ' ', $vendorName))), 0, 190);
    }

    /** The supplier this printed name was matched to before (and the hit is counted). */
    public static function supplierFor(PDO $db, int $companyId, string $vendorName): ?int
    {
        $key = self::key($vendorName);
        if ($key === '') return null;
        $st = $db->prepare("SELECT h.hint_value FROM ai_hints h JOIN crm_accounts a ON a.id = h.hint_value AND a.company_id = h.company_id AND a.type = 'supplier' AND a.deleted_at IS NULL
                             WHERE h.company_id = ? AND h.hint_type = 'vendor' AND h.hint_key = ? ORDER BY h.hits DESC, h.updated_at DESC LIMIT 1");
        $st->execute([$companyId, $key]);
        $id = (int)$st->fetchColumn();
        if ($id) $db->prepare("UPDATE ai_hints SET hits = hits + 1, last_used_at = NOW() WHERE company_id = ? AND hint_type = 'vendor' AND hint_key = ?")->execute([$companyId, $key]);
        return $id ?: null;
    }

    public static function rememberSupplier(PDO $db, int $companyId, string $vendorName, int $supplierId): void
    {
        $key = self::key($vendorName);
        if ($key === '' || $supplierId <= 0) return;
        $st = $db->prepare("UPDATE ai_hints SET hint_value = ?, hits = hits + 1, last_used_at = NOW(), updated_at = NOW() WHERE company_id = ? AND hint_type = 'vendor' AND hint_key = ?");
        $st->execute([(string)$supplierId, $companyId, $key]);
        if ($st->rowCount() === 0) {
            $db->prepare("INSERT INTO ai_hints (company_id, hint_type, hint_key, hint_value, hits, last_used_at, created_at, updated_at) VALUES (?, 'vendor', ?, ?, 1, NOW(), NOW(), NOW())")
               ->execute([$companyId, $key, (string)$supplierId]);
        }
    }

    /** After a bill is saved: the supplier's account and board, and the account each word went to. */
    public static function rememberBill(PDO $db, int $companyId, int $supplierId, array $lines, ?int $boardId): void
    {
        $map = self::map($db, $companyId);
        $first = null;
        foreach ($lines as $l) {
            $acc = (int)($l['account_id'] ?? 0);
            if (!$acc) continue;
            $first = $first ?? $acc;
            foreach (self::words((string)($l['description'] ?? '')) as $w) {
                unset($map['token_map'][$w]);
                $map['token_map'][$w] = $acc; // most recent last
            }
        }
        if ($first) $map['supplier_default_gl'][(string)$supplierId] = $first;
        if ($boardId) $map['supplier_board'][(string)$supplierId] = $boardId;
        elseif (isset($map['supplier_board'][(string)$supplierId])) unset($map['supplier_board'][(string)$supplierId]);
        if (count($map['token_map']) > self::MAX_WORDS) $map['token_map'] = array_slice($map['token_map'], -self::MAX_WORDS, null, true);
        self::saveMap($db, $companyId, $map);
    }

    /** @return array{0:?int, 1:string} the account for a line and why ('word', 'supplier', 'history', '') */
    public static function accountFor(PDO $db, int $companyId, array $map, ?int $supplierId, string $description): array
    {
        foreach (self::words($description) as $w) {
            if ((int)($map['token_map'][$w] ?? 0) > 0) return [(int)$map['token_map'][$w], 'word'];
        }
        if ($supplierId && (int)($map['supplier_default_gl'][(string)$supplierId] ?? 0) > 0) return [(int)$map['supplier_default_gl'][(string)$supplierId], 'supplier'];
        if ($supplierId) {
            $st = $db->prepare("SELECT bl.gl_account_id FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id
                                 WHERE b.company_id = ? AND b.supplier_id = ? AND bl.gl_account_id IS NOT NULL ORDER BY b.issue_date DESC, bl.id DESC LIMIT 1");
            $st->execute([$companyId, $supplierId]);
            if ($id = (int)$st->fetchColumn()) return [$id, 'history'];
        }
        return [null, ''];
    }

    public static function boardFor(array $map, ?int $supplierId): ?int
    {
        return $supplierId && (int)($map['supplier_board'][(string)$supplierId] ?? 0) > 0 ? (int)$map['supplier_board'][(string)$supplierId] : null;
    }

    /** What is remembered, for Settings → Learning. */
    public static function summary(PDO $db, int $companyId): array
    {
        $map = self::map($db, $companyId);
        $names = [];
        try {
            $st = $db->prepare("SELECT h.hint_key, h.hits, a.name FROM ai_hints h LEFT JOIN crm_accounts a ON a.id = h.hint_value AND a.company_id = h.company_id
                                 WHERE h.company_id = ? AND h.hint_type = 'vendor' ORDER BY h.hits DESC, h.updated_at DESC LIMIT 200");
            $st->execute([$companyId]);
            $names = array_map(fn($r) => ['key' => (string)$r['hint_key'], 'supplier' => (string)($r['name'] ?? '(deleted supplier)'), 'hits' => (int)$r['hits']], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
        }
        $acc = [];
        $ids = array_unique(array_merge(array_map('intval', $map['supplier_default_gl']), array_map('intval', $map['token_map'])));
        if ($ids) {
            $st = $db->prepare("SELECT account_id, CONCAT(account_code, ' · ', account_name) FROM gl_accounts WHERE company_id = ? AND account_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute(array_merge([$companyId], array_values($ids)));
            $acc = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $sup = [];
        $sids = array_map('intval', array_keys($map['supplier_default_gl']));
        if ($sids) {
            $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND id IN (" . implode(',', array_fill(0, count($sids), '?')) . ")");
            $st->execute(array_merge([$companyId], $sids));
            $sup = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $suppliers = [];
        foreach ($map['supplier_default_gl'] as $sid => $aid) {
            if (!isset($sup[(int)$sid])) continue;
            $suppliers[] = ['id' => (int)$sid, 'supplier' => (string)$sup[(int)$sid], 'account' => (string)($acc[(int)$aid] ?? '—')];
        }
        usort($suppliers, fn($a, $b) => strcasecmp($a['supplier'], $b['supplier']));
        $words = [];
        foreach (array_reverse($map['token_map'], true) as $w => $aid) {
            if (!isset($acc[(int)$aid]) || mb_strlen((string)$w) < 4) continue;
            $words[] = ['word' => (string)$w, 'account' => (string)$acc[(int)$aid]];
            if (count($words) >= 300) break;
        }
        return ['names' => $names, 'suppliers' => $suppliers, 'words' => $words];
    }

    /** $what: all | name:<key> | supplier:<id> | word:<word> */
    public static function forget(PDO $db, int $companyId, string $what): void
    {
        [$kind, $val] = array_pad(explode(':', $what, 2), 2, '');
        if ($kind === 'all' || $kind === 'name') {
            $sql = "DELETE FROM ai_hints WHERE company_id = ? AND hint_type IN ('vendor','gl','project')" . ($kind === 'name' ? " AND hint_key = ?" : '');
            $db->prepare($sql)->execute($kind === 'name' ? [$companyId, $val] : [$companyId]);
        }
        if ($kind === 'all') {
            self::saveMap($db, $companyId, ['supplier_default_gl' => [], 'token_map' => [], 'supplier_board' => []]);
            return;
        }
        if ($kind === 'supplier' || $kind === 'word') {
            $map = self::map($db, $companyId);
            if ($kind === 'supplier') { unset($map['supplier_default_gl'][(string)(int)$val], $map['supplier_board'][(string)(int)$val]); }
            else unset($map['token_map'][$val]);
            self::saveMap($db, $companyId, $map);
        }
    }
}
