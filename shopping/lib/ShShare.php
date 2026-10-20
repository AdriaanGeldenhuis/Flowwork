<?php
// /shopping/lib/ShShare.php — a link for someone without Flowwork (a driver,
// a runner, family): they open /shopping/share.php?t=… on their phone and
// see the list, and can tick things off (and add to it, when allowed). No
// money is shown to them. Links run out (1, 7 or 30 days) and the owner can
// stop one at any time. Ticks made through a link carry the name the person
// typed, so everyone knows who put what in the trolley.

final class ShShare
{
    public const DAYS = [1, 7, 30];

    public static function url(string $token): string
    {
        $base = defined('APP_BASE_URL') ? rtrim((string)APP_BASE_URL, '/') : '';
        return $base . '/shopping/share.php?t=' . $token;
    }

    public static function forList(PDO $db, int $listId): array
    {
        $st = $db->prepare("SELECT * FROM shopping_share_tokens WHERE list_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC");
        $st->execute([$listId]);
        return array_map(fn($t) => ['id' => (int)$t['id'], 'url' => self::url((string)$t['token']), 'can_check' => (bool)$t['can_check'], 'can_edit' => (bool)$t['can_edit'],
                                     'expires_at' => $t['expires_at'], 'last_used_at' => $t['last_used_at'] ?? null, 'by' => ShKit::personName($db, (int)$t['created_by'])],
                         $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function create(PDO $db, int $companyId, int $userId, string $role, int $listId, array $in): array
    {
        ShLists::access($db, $companyId, $userId, $role, $listId, 'manage');
        $days = in_array((int)($in['days'] ?? 7), self::DAYS, true) ? (int)$in['days'] : 7;
        $st = $db->prepare("SELECT COUNT(*) FROM shopping_share_tokens WHERE list_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())");
        $st->execute([$listId]);
        if ((int)$st->fetchColumn() >= 10) throw new ShProblem('This list already has 10 links. Stop one you no longer need first.', 422);
        $token = bin2hex(random_bytes(24));
        $db->prepare("INSERT INTO shopping_share_tokens (list_id, token, can_edit, can_check, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$listId, $token, !empty($in['can_edit']) ? 1 : 0, array_key_exists('can_check', $in) && empty($in['can_check']) ? 0 : 1, date('Y-m-d H:i:s', time() + $days * 86400), $userId]);
        ShKit::act($db, $companyId, $listId, null, $userId, 'share', ['days' => $days]);
        ShKit::audit($db, $companyId, $userId, 'shopping_share', 'shopping_list', $listId, ['days' => $days, 'can_edit' => !empty($in['can_edit'])]);
        ShLists::touch($db, $listId);
        return ['url' => self::url($token), 'shares' => self::forList($db, $listId)];
    }

    public static function revoke(PDO $db, int $companyId, int $userId, string $role, int $listId, int $tokenId): array
    {
        ShLists::access($db, $companyId, $userId, $role, $listId, 'manage');
        $db->prepare("UPDATE shopping_share_tokens SET revoked_at = NOW() WHERE id = ? AND list_id = ? AND revoked_at IS NULL")->execute([$tokenId, $listId]);
        ShKit::act($db, $companyId, $listId, null, $userId, 'unshare');
        ShLists::touch($db, $listId);
        return self::forList($db, $listId);
    }

    /** The link's token row and its list, when the link still works. */
    public static function resolve(PDO $db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $st = $db->prepare("SELECT t.*, l.company_id, l.name AS list_name FROM shopping_share_tokens t JOIN shopping_lists l ON l.id = t.list_id
                             WHERE t.token = ? AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > NOW()) AND l.deleted_at IS NULL");
        $st->execute([$token]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;
        $list = ShLists::load($db, (int)$t['company_id'], (int)$t['list_id']);
        if (!$list) return null;
        if (!$t['last_used_at'] || strtotime((string)$t['last_used_at']) < time() - 300) {
            $db->prepare("UPDATE shopping_share_tokens SET last_used_at = NOW() WHERE id = ?")->execute([$t['id']]);
        }
        return ['token' => $t, 'list' => $list];
    }

    /** What someone with the link sees: names and amounts to buy, never money. */
    public static function view(PDO $db, array $share): array
    {
        $list = $share['list'];
        $t = $share['token'];
        $st = $db->prepare("SELECT name FROM companies WHERE id = ?");
        $st->execute([(int)$list['company_id']]);
        $items = array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'qty_text' => $i['qty_text'], 'unit' => $i['unit'], 'notes' => $i['notes'], 'category' => $i['category'],
                                      'category_label' => $i['category_label'], 'priority' => $i['priority'], 'bought' => $i['bought'], 'bought_by' => $i['bought_by'], 'store' => $i['store']],
                           ShLists::items($db, (int)$list['company_id'], (int)$list['id']));
        return [
            'list' => ['name' => (string)$list['name'], 'purpose' => (string)$list['purpose'], 'notes' => (string)($list['notes'] ?? ''), 'needed_by' => $list['needed_by'],
                       'status' => (string)$list['status'], 'owner' => ShKit::personName($db, (int)$list['owner_id'])],
            'company' => (string)$st->fetchColumn(),
            'items' => $items,
            'can_check' => (bool)$t['can_check'], 'can_edit' => (bool)$t['can_edit'], 'expires_at' => $t['expires_at'],
            'version' => ShLists::version($db, (int)$list['id']),
        ];
    }

    /** A tick through the link. */
    public static function toggle(PDO $db, array $share, int $itemId, bool $bought, string $guest): array
    {
        if (empty($share['token']['can_check'])) throw new ShForbidden('This link is for looking only.');
        $list = $share['list'];
        $guest = ShKit::text($guest, 60) ?: 'Guest';
        $r = ShItems::toggle($db, (int)$list['company_id'], (int)$share['token']['created_by'], $list, $itemId, $bought, false, false, $guest);
        if ($bought && $r['counts']['left'] === 0) {
            ShKit::notify($db, (int)$list['company_id'], (int)$list['owner_id'], 0, 'Everything is bought', '“' . $list['name'] . '” — ' . $guest . ' ticked off the last item through your link.', '/shopping/list.php?id=' . $list['id'], true);
        }
        return $r;
    }

    public static function add(PDO $db, array $share, string $text, string $guest): array
    {
        if (empty($share['token']['can_edit'])) throw new ShForbidden('This link cannot add to the list.');
        $list = $share['list'];
        $items = ShParse::items($text, 30);
        if (!$items) throw new ShProblem('Type what to add — one item a line.', 422);
        return ShItems::addMany($db, (int)$list['company_id'], (int)$share['token']['created_by'], $list, $items, ['guest' => ShKit::text($guest, 60) ?: 'Guest']);
    }
}
