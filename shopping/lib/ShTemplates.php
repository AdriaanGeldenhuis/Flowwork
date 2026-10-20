<?php
// /shopping/lib/ShTemplates.php — reusable lists for the things bought again
// and again ("Monthly office", "Site start-up kit"). The whole company can use
// them; whoever made one (or an admin) can change or delete it. The items are
// kept as the same one-a-line text people type, so editing is just typing.

final class ShTemplates
{
    public static function all(PDO $db, int $companyId, int $userId, string $role): array
    {
        $st = $db->prepare("SELECT t.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS by_name,
                                   (SELECT COUNT(*) FROM shopping_lists l WHERE l.template_id = t.id AND l.company_id = t.company_id AND l.deleted_at IS NULL) AS used
                              FROM shopping_templates t LEFT JOIN users u ON u.id = t.created_by
                             WHERE t.company_id = ? ORDER BY t.name LIMIT 300");
        $st->execute([$companyId]);
        return array_map(fn($t) => self::row($t, $userId, $role), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function row(array $t, int $userId, string $role): array
    {
        $items = self::itemsOf($t);
        $est = 0;
        foreach ($items as $i) $est += (int)round((float)$i['qty'] * (int)($i['price_cents'] ?? 0));
        return [
            'id' => (int)$t['id'], 'name' => (string)$t['name'], 'purpose' => (string)$t['purpose'], 'purpose_label' => ShKit::PURPOSES[$t['purpose']] ?? 'General',
            'count' => count($items), 'preview' => array_map(fn($i) => ShKit::qtyText($i['qty']) . ($i['unit'] !== 'ea' ? ' ' . $i['unit'] : ' ×') . ' ' . $i['name'], array_slice($items, 0, 6)),
            'text' => self::text($items), 'est' => $est ?: null, 'by' => trim((string)($t['by_name'] ?? '')), 'used' => (int)($t['used'] ?? 0),
            'created_at' => (string)$t['created_at'], 'edit' => ShKit::canWrite($role) && ((int)$t['created_by'] === $userId || ShKit::role($role) === 'admin'),
        ];
    }

    /** The stored items, tidied (older templates stored {name, qty, unit} only). */
    public static function itemsOf(array $t): array
    {
        $out = [];
        foreach ((array)json_decode((string)$t['items_json'], true) as $i) {
            if (!is_array($i) || trim((string)($i['name'] ?? '')) === '') continue;
            $name = ShKit::text($i['name'], 200);
            $out[] = ['name' => $name, 'qty' => max(0.001, (float)($i['qty'] ?? 1)), 'unit' => ShParse::unit($i['unit'] ?? 'ea'),
                      'price_cents' => isset($i['price_cents']) && $i['price_cents'] !== null ? (int)$i['price_cents'] : null,
                      'priority' => ($i['priority'] ?? 'med') === 'high' ? 'high' : 'med', 'category' => (string)($i['category'] ?? ShParse::category($name))];
        }
        return $out;
    }

    /** Items → the one-a-line text that ShParse reads back the same. */
    public static function text(array $items): string
    {
        $lines = [];
        foreach ($items as $i) {
            $q = ShKit::qtyText($i['qty']);
            $line = $i['unit'] === 'ea' ? ($q === '1' ? '' : $q . 'x ') : $q . ' ' . $i['unit'] . ' ';
            $line .= $i['name'];
            if (!empty($i['price_cents'])) $line .= ' @ R' . number_format($i['price_cents'] / 100, 2, '.', '');
            if (($i['priority'] ?? '') === 'high') $line .= ' !';
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private static function load(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT * FROM shopping_templates WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new ShProblem('That template is not there any more.', 404);
        return $t;
    }

    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!ShKit::canWrite($role)) throw new ShForbidden('Viewers cannot make templates.');
        $id = (int)($in['id'] ?? 0);
        if ($id) {
            $t = self::load($db, $companyId, $id);
            if ((int)$t['created_by'] !== $userId && ShKit::role($role) !== 'admin') throw new ShForbidden('Only ' . (ShKit::personName($db, (int)$t['created_by']) ?: 'whoever made it') . ' (or an admin) can change this template.');
        }
        $name = ShKit::text($in['name'] ?? '', 120);
        if ($name === '') throw new ShProblem('Give the template a name.', 422);
        $purpose = isset(ShKit::PURPOSES[$in['purpose'] ?? '']) ? $in['purpose'] : 'general';
        $items = isset($in['items']) && is_array($in['items']) ? $in['items'] : ShParse::items((string)($in['text'] ?? ''), ShKit::MAX_ITEMS_PER_LIST);
        if (!$items) throw new ShProblem('A template needs at least one item — one a line.', 422);
        $json = json_encode(array_map(fn($i) => ['name' => ShKit::text($i['name'], 200), 'qty' => round((float)($i['qty'] ?? 1), 3), 'unit' => ShParse::unit($i['unit'] ?? 'ea'),
                                                 'price_cents' => isset($i['price_cents']) && $i['price_cents'] !== null ? (int)$i['price_cents'] : null,
                                                 'priority' => ($i['priority'] ?? 'med') === 'high' ? 'high' : 'med', 'category' => (string)($i['category'] ?? 'other')], array_slice($items, 0, ShKit::MAX_ITEMS_PER_LIST)),
                           JSON_UNESCAPED_UNICODE);
        if ($id) {
            $db->prepare("UPDATE shopping_templates SET name = ?, purpose = ?, items_json = ? WHERE id = ? AND company_id = ?")->execute([$name, $purpose, $json, $id, $companyId]);
        } else {
            $db->prepare("INSERT INTO shopping_templates (company_id, name, purpose, items_json, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute([$companyId, $name, $purpose, $json, $userId]);
            $id = (int)$db->lastInsertId();
        }
        return $id;
    }

    /** A template from a list: everything on it, still to buy or bought. */
    public static function fromList(PDO $db, int $companyId, int $userId, string $role, int $listId, string $name): int
    {
        $list = ShLists::access($db, $companyId, $userId, $role, $listId);
        $items = [];
        foreach (ShLists::items($db, $companyId, $listId) as $i) {
            $each = $i['est'] ?? ($i['paid'] !== null && $i['qty'] > 0 ? (int)round($i['paid'] / $i['qty']) : null);
            $items[] = ['name' => $i['name'], 'qty' => $i['qty'], 'unit' => $i['unit'], 'price_cents' => $each, 'priority' => $i['priority'], 'category' => $i['category']];
        }
        if (!$items) throw new ShProblem('Add a few items to the list first.', 422);
        return self::save($db, $companyId, $userId, $role, ['name' => $name !== '' ? $name : $list['name'], 'purpose' => $list['purpose'], 'items' => $items]);
    }

    /** A new list from a template. */
    public static function use(PDO $db, int $companyId, int $userId, string $role, int $id, array $in): int
    {
        $t = self::load($db, $companyId, $id);
        $name = ShKit::text($in['name'] ?? '', 120) ?: $t['name'] . ' · ' . date('j M');
        return ShLists::create($db, $companyId, $userId, $role, ['name' => $name, 'purpose' => $t['purpose'], 'template_id' => $id] + array_intersect_key($in, ['shared' => 1, 'needed_by' => 1, 'budget' => 1, 'project_id' => 1]),
                               self::itemsOf($t), ['template' => $t['name']]);
    }

    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        $t = self::load($db, $companyId, $id);
        if (!ShKit::canWrite($role) || ((int)$t['created_by'] !== $userId && ShKit::role($role) !== 'admin')) throw new ShForbidden('Only whoever made this template (or an admin) can delete it.');
        $db->prepare("DELETE FROM shopping_templates WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        $db->prepare("UPDATE shopping_lists SET template_id = NULL WHERE template_id = ? AND company_id = ?")->execute([$id, $companyId]);
    }
}
