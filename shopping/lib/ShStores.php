<?php
// /shopping/lib/ShStores.php — where to buy things:
//   - the company's stores (linked to a CRM supplier when they are one),
//   - the price book: what each thing cost, where and when (from ticks with a price),
//   - "Where to buy": what you paid before, the suppliers who billed you for it
//     (Finance's writers only), your stores for that aisle, preferred suppliers,
//     and — when an admin switched it on — ideas from AI, always marked as such
//     and never with made-up phone numbers or prices.

final class ShStores
{
    /** A posted store id, checked against the company (null for none). */
    public static function storeId(PDO $db, int $companyId, $v): ?int
    {
        $id = is_scalar($v) ? (int)$v : 0;
        if ($id <= 0) return null;
        $st = $db->prepare("SELECT id FROM shopping_stores WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        if (!$st->fetchColumn()) throw new ShProblem('That store is not in your list of stores any more.', 422);
        return $id;
    }

    public static function name(PDO $db, int $companyId, int $id): string
    {
        $st = $db->prepare("SELECT name FROM shopping_stores WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        return (string)$st->fetchColumn();
    }

    /** Every store, with what was bought there (on lists this person sees). */
    public static function all(PDO $db, int $companyId, int $userId): array
    {
        $args = [$companyId];
        $vis = ShLists::visibleSql($userId, $args);
        $st = $db->prepare("SELECT s.*, a.name AS crm_name, COALESCE(h.n, 0) AS n, COALESCE(h.spent, 0) AS spent, h.last_at
                              FROM shopping_stores s
                         LEFT JOIN crm_accounts a ON a.id = s.crm_account_id AND a.company_id = s.company_id AND a.deleted_at IS NULL
                         LEFT JOIN (SELECT h.store_id, COUNT(*) n, SUM(ROUND(h.price_cents * COALESCE(h.qty, 1))) spent, MAX(h.seen_at) last_at
                                      FROM shopping_price_history h JOIN shopping_lists l ON l.id = h.list_id
                                     WHERE h.company_id = ? AND $vis GROUP BY h.store_id) h ON h.store_id = s.id
                             WHERE s.company_id = ?
                          ORDER BY s.preferred DESC, n DESC, s.name LIMIT 500");
        $args[] = $companyId;
        $st->execute($args);
        return array_map(fn($s) => self::row($s), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function row(array $s): array
    {
        $cats = json_decode((string)($s['categories_json'] ?? ''), true);
        return [
            'id' => (int)$s['id'], 'name' => (string)$s['name'], 'phone' => (string)($s['phone'] ?? ''), 'email' => (string)($s['email'] ?? ''),
            'website' => (string)($s['website'] ?? ''), 'address' => (string)($s['address'] ?? ''),
            'lat' => $s['lat'] !== null ? (float)$s['lat'] : null, 'lng' => $s['lng'] !== null ? (float)$s['lng'] : null,
            'preferred' => (bool)$s['preferred'], 'categories' => is_array($cats) ? array_values(array_filter($cats, fn($c) => isset(ShParse::CATEGORIES[$c]) || $c === 'other')) : [],
            'crm_id' => $s['crm_account_id'] ? (int)$s['crm_account_id'] : null, 'crm_name' => $s['crm_name'] ?? null,
            'bought' => (int)($s['n'] ?? 0), 'spent' => (int)($s['spent'] ?? 0), 'last_at' => $s['last_at'] ?? null,
        ];
    }

    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!ShKit::canWrite($role)) throw new ShForbidden('Viewers cannot change the stores.');
        $id = (int)($in['id'] ?? 0);
        $name = ShKit::text($in['name'] ?? '', 190);
        if ($name === '') throw new ShProblem('Give the store a name.', 422);
        $phone = ShKit::text($in['phone'] ?? '', 40);
        if ($phone !== '' && !preg_match('/^[+()\d\s\-]{6,40}$/', $phone)) throw new ShProblem('That phone number has letters or signs a phone number cannot have.', 422);
        $email = ShKit::text($in['email'] ?? '', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ShProblem('That email address is not complete.', 422);
        $web = ShKit::text($in['website'] ?? '', 255);
        if ($web !== '' && !preg_match('~^https?://~i', $web)) $web = 'https://' . $web;
        if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) throw new ShProblem('That website address is not complete.', 422);
        $cats = array_values(array_unique(array_filter((array)($in['categories'] ?? []), fn($c) => is_string($c) && (isset(ShParse::CATEGORIES[$c]) || $c === 'other'))));
        $crm = (int)($in['crm_id'] ?? 0) ?: null;
        if ($crm) {
            $st = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier' AND deleted_at IS NULL");
            $st->execute([$crm, $companyId]);
            if (!$st->fetchColumn()) throw new ShProblem('That supplier is not in the CRM any more.', 422);
        }
        $vals = [$name, $phone ?: null, $email ?: null, $web ?: null, ShKit::text($in['address'] ?? '', 255) ?: null, json_encode($cats), !empty($in['preferred']) ? 1 : 0, $crm];
        if ($id) {
            $st = $db->prepare("SELECT id FROM shopping_stores WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            if (!$st->fetchColumn()) throw new ShProblem('That store is not there any more.', 404);
            $db->prepare("UPDATE shopping_stores SET name = ?, phone = ?, email = ?, website = ?, address = ?, categories_json = ?, preferred = ?, crm_account_id = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute(array_merge($vals, [$id, $companyId]));
        } else {
            $db->prepare("INSERT INTO shopping_stores (name, phone, email, website, address, categories_json, preferred, crm_account_id, company_id, created_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")->execute(array_merge($vals, [$companyId, $userId]));
            $id = (int)$db->lastInsertId();
        }
        $db->prepare("UPDATE shopping_price_history SET store_name = ? WHERE store_id = ? AND company_id = ?")->execute([$name, $id, $companyId]);
        return $id;
    }

    public static function delete(PDO $db, int $companyId, string $role, int $id): void
    {
        if (!ShKit::canWrite($role)) throw new ShForbidden('Viewers cannot change the stores.');
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE shopping_items i JOIN shopping_lists l ON l.id = i.list_id SET i.store_id = NULL WHERE i.store_id = ? AND l.company_id = ?")->execute([$id, $companyId]);
            $db->prepare("UPDATE shopping_lists SET store_id = NULL WHERE store_id = ? AND company_id = ?")->execute([$id, $companyId]);
            $db->prepare("UPDATE shopping_sessions s JOIN shopping_lists l ON l.id = s.list_id SET s.active_store_id = NULL WHERE s.active_store_id = ? AND l.company_id = ?")->execute([$id, $companyId]);
            $db->prepare("UPDATE shopping_preferences SET home_store_id = NULL WHERE home_store_id = ? AND company_id = ?")->execute([$id, $companyId]);
            // The price book keeps the store's name.
            $db->prepare("UPDATE shopping_price_history SET store_id = NULL WHERE store_id = ? AND company_id = ?")->execute([$id, $companyId]);
            $db->prepare("DELETE FROM shopping_stores WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** The store for a CRM supplier (made the first time). */
    public static function forSupplier(PDO $db, int $companyId, int $userId, int $crmId): int
    {
        $st = $db->prepare("SELECT id FROM shopping_stores WHERE company_id = ? AND crm_account_id = ? ORDER BY id LIMIT 1");
        $st->execute([$companyId, $crmId]);
        if ($id = (int)$st->fetchColumn()) return $id;
        $st = $db->prepare("SELECT name, phone, email, website, preferred FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier' AND deleted_at IS NULL");
        $st->execute([$crmId, $companyId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new ShProblem('That supplier is not in the CRM any more.', 422);
        $db->prepare("INSERT INTO shopping_stores (company_id, crm_account_id, name, phone, email, website, preferred, categories_json, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, '[]', ?, NOW(), NOW())")
           ->execute([$companyId, $crmId, $a['name'], $a['phone'] ?: null, $a['email'] ?: null, $a['website'] ?: null, (int)$a['preferred'], $userId]);
        return (int)$db->lastInsertId();
    }

    /** What one of these cost last time (cents each), or null. */
    public static function lastPrice(PDO $db, int $companyId, string $name, string $unit): ?int
    {
        $st = $db->prepare("SELECT price_cents FROM shopping_price_history WHERE company_id = ? AND item_fingerprint = ? ORDER BY seen_at DESC, id DESC LIMIT 1");
        $st->execute([$companyId, ShParse::fingerprint($name, $unit)]);
        $p = $st->fetchColumn();
        return $p === false ? null : (int)$p;
    }

    // ------------------------------------------------------------- where to buy

    public static function mapsUrl(string $name, string $address, string $town, ?float $lat = null, ?float $lng = null, string $mode = 'drive'): string
    {
        if ($lat !== null && $lng !== null) {
            return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($lat . ',' . $lng) . '&travelmode=' . (['walk' => 'walking', 'bike' => 'bicycling'][$mode] ?? 'driving');
        }
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($name . ' ' . ($address !== '' ? $address : $town)));
    }

    private static function contact(array $c, string $town, string $mode): array
    {
        $phone = (string)($c['phone'] ?? '');
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) $digits = '27' . substr($digits, 1);
        return [
            'tel' => $phone !== '' ? 'tel:' . preg_replace('/[^\d+]/', '', $phone) : null,
            'wa' => strlen($digits) >= 10 ? 'https://wa.me/' . $digits : null,
            'maps' => self::mapsUrl((string)$c['name'], (string)($c['address'] ?? ''), $town, isset($c['lat']) && $c['lat'] !== null ? (float)$c['lat'] : null, isset($c['lng']) && $c['lng'] !== null ? (float)$c['lng'] : null, $mode),
        ];
    }

    /** "Where to buy" for one item, from what the company already knows. */
    public static function suggest(PDO $db, int $companyId, int $userId, string $role, int $itemId): array
    {
        [$item] = ShItems::access($db, $companyId, $userId, $role, $itemId, 'see');
        $prefs = ShKit::prefs($db, $companyId, $userId);
        $town = ShKit::companyTown($db, $companyId);
        $mode = $prefs['route_mode'];
        $name = (string)$item['name_raw'];
        $cat = (string)($item['category'] ?: ShParse::category($name));
        $sections = [];
        $seenStores = [];
        $seenCrm = [];

        // 1. Bought before (lists this person sees)
        $args = [$companyId, ShParse::fingerprint($name, (string)$item['unit'])];
        $vis = ShLists::visibleSql($userId, $args);
        $st = $db->prepare("SELECT h.store_id, h.store_name, h.price_cents, h.seen_at, s.phone, s.address, s.lat, s.lng, s.crm_account_id
                              FROM shopping_price_history h JOIN shopping_lists l ON l.id = h.list_id
                         LEFT JOIN shopping_stores s ON s.id = h.store_id AND s.company_id = h.company_id
                             WHERE h.company_id = ? AND h.item_fingerprint = ? AND $vis ORDER BY h.seen_at DESC LIMIT 60");
        $st->execute($args);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $key = $h['store_id'] ? 's' . $h['store_id'] : 'n' . mb_strtolower((string)$h['store_name']);
            if (!isset($by[$key])) $by[$key] = ['h' => $h, 'n' => 0, 'best' => PHP_INT_MAX, 'last' => (int)$h['price_cents'], 'at' => (string)$h['seen_at']];
            $by[$key]['n']++;
            $by[$key]['best'] = min($by[$key]['best'], (int)$h['price_cents']);
        }
        $rows = [];
        foreach ($by as $b) {
            $h = $b['h'];
            $label = $h['store_name'] ?: 'Store not noted';
            if ($h['store_id']) $seenStores[(int)$h['store_id']] = true;
            if ($h['crm_account_id']) $seenCrm[(int)$h['crm_account_id']] = true;
            $rows[] = ['kind' => 'history', 'store_id' => $h['store_id'] ? (int)$h['store_id'] : null, 'name' => $label, 'price' => $b['last'], 'best' => $b['best'],
                       'why' => 'Bought here ' . ($b['n'] === 1 ? 'once' : $b['n'] . ' times') . ' · last ' . ShKit::money($b['last']) . ' each on ' . ShKit::date(substr($b['at'], 0, 10), false) . ($b['best'] < $b['last'] ? ' · best ' . ShKit::money($b['best']) : ''),
                       'address' => (string)($h['address'] ?? '')] + self::contact(['name' => $label] + $h, $town, $mode);
        }
        usort($rows, fn($a, $b) => $a['price'] <=> $b['price']);
        if ($rows) {
            $rows[0]['cheapest'] = count($rows) > 1;
            $sections[] = ['key' => 'history', 'title' => 'You bought it before', 'rows' => array_slice($rows, 0, 5)];
        }

        // 2. Suppliers who billed you for it (Finance's writers only: these are the books)
        $words = ShParse::keywords($name);
        if ($words && ShKit::canOrder($role)) {
            $cond = implode(' AND ', array_fill(0, count($words), 'CONVERT(bl.item_description USING utf8mb4) LIKE ?'));
            $args = [$companyId];
            foreach ($words as $w) $args[] = '%' . ShKit::likeEscape($w) . '%';
            try {
                $st = $db->prepare("SELECT b.supplier_id, a.name, a.phone, a.preferred, MAX(b.issue_date) last_date, COUNT(*) n,
                                           SUBSTRING_INDEX(GROUP_CONCAT(ROUND(bl.unit_price * (1 + COALESCE(bl.tax_rate, 0) / 100), 2) ORDER BY b.issue_date DESC SEPARATOR '|'), '|', 1) last_price,
                                           SUBSTRING_INDEX(GROUP_CONCAT(CONVERT(bl.item_description USING utf8mb4) ORDER BY b.issue_date DESC SEPARATOR '\\n'), '\\n', 1) last_desc
                                      FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id
                                      JOIN crm_accounts a ON a.id = b.supplier_id AND a.company_id = b.company_id AND a.deleted_at IS NULL
                                     WHERE b.company_id = ? AND b.status IN ('approved', 'posted', 'paid') AND $cond
                                  GROUP BY b.supplier_id, a.name, a.phone, a.preferred ORDER BY last_date DESC LIMIT 5");
                $st->execute($args);
                $rows = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $seenCrm[(int)$r['supplier_id']] = true;
                    $rows[] = ['kind' => 'supplier', 'crm_id' => (int)$r['supplier_id'], 'name' => (string)$r['name'], 'price' => $r['last_price'] !== null ? (int)round((float)$r['last_price'] * 100) : null,
                               'why' => 'Billed you for “' . mb_strimwidth((string)$r['last_desc'], 0, 60, '…') . '” on ' . ShKit::date((string)$r['last_date'], false) . ($r['last_price'] !== null ? ' · ' . ShKit::money((int)round((float)$r['last_price'] * 100)) . ' each incl. VAT' : '') . ($r['n'] > 1 ? ' · ' . $r['n'] . ' bills' : ''),
                               'preferred' => (bool)$r['preferred'], 'address' => ''] + self::contact(['name' => $r['name'], 'phone' => $r['phone']], $town, $mode);
                }
                if ($rows) $sections[] = ['key' => 'suppliers', 'title' => 'Suppliers who billed you for it', 'rows' => $rows];
            } catch (Throwable $e) {
                error_log('[shopping] supplier lines: ' . $e->getMessage());
            }
        }

        // 3. Your stores for this aisle (and the preferred ones)
        $st = $db->prepare("SELECT * FROM shopping_stores WHERE company_id = ? AND (categories_json LIKE ? OR preferred = 1) ORDER BY categories_json LIKE ? DESC, preferred DESC, name LIMIT 12");
        $catLike = '%"' . ShKit::likeEscape($cat) . '"%';
        $st->execute([$companyId, $catLike, $catLike]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if (isset($seenStores[(int)$s['id']])) continue;
            if ($s['crm_account_id']) $seenCrm[(int)$s['crm_account_id']] = true;
            $forAisle = str_contains((string)$s['categories_json'], '"' . $cat . '"');
            $rows[] = ['kind' => 'store', 'store_id' => (int)$s['id'], 'name' => (string)$s['name'], 'price' => null,
                       'why' => trim(($forAisle ? 'One of your stores for ' . mb_strtolower(ShParse::categoryLabel($cat)) : 'One of your preferred stores') . ($s['address'] ? ' · ' . $s['address'] : '')),
                       'preferred' => (bool)$s['preferred'], 'address' => (string)($s['address'] ?? '')] + self::contact($s, $town, $mode);
            if (count($rows) >= 5) break;
        }
        if ($rows) $sections[] = ['key' => 'stores', 'title' => 'Your stores', 'rows' => $rows];

        // 4. Preferred suppliers in the CRM
        $st = $db->prepare("SELECT id, name, phone FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' AND deleted_at IS NULL AND preferred = 1 ORDER BY name LIMIT 10");
        $st->execute([$companyId]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (isset($seenCrm[(int)$a['id']])) continue;
            $rows[] = ['kind' => 'crm', 'crm_id' => (int)$a['id'], 'name' => (string)$a['name'], 'price' => null, 'why' => 'A preferred supplier in your CRM', 'preferred' => true, 'address' => ''] + self::contact($a, $town, $mode);
            if (count($rows) >= 4) break;
        }
        if ($rows) $sections[] = ['key' => 'crm', 'title' => 'Preferred suppliers', 'rows' => $rows];

        return ['item' => ShLists::item($db, $companyId, $itemId), 'sections' => $sections, 'ai' => ShKit::aiReady($db, $companyId), 'town' => $town,
                'search' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($name . ' ' . $town))];
    }

    /**
     * Ideas from AI: kinds of shops and well-known chains that sell it near the
     * company. Only the item's name and the company's town are sent. No phone
     * numbers, addresses or prices are asked for (they would be guesses).
     */
    public static function ai(PDO $db, int $companyId, int $userId, string $role, int $itemId): array
    {
        [$item] = ShItems::access($db, $companyId, $userId, $role, $itemId, 'edit');
        if (!ShKit::aiReady($db, $companyId)) throw new ShProblem('AI store ideas are switched off. An admin can switch them on under Preferences.', 409);
        if (!function_exists('curl_init')) throw new ShProblem('The server cannot reach the AI (no cURL).', 503);
        if (($wait = ShKit::rateLimit('ai:' . $companyId, 40, 3600)) > 0) throw new ShProblem('AI ideas are paused for ' . ceil($wait / 60) . ' minutes (40 an hour).', 429);
        $key = (string)ShKit::aiKey($db, $companyId);
        $town = ShKit::companyTown($db, $companyId) ?: 'South Africa';
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['ideas'], 'properties' => ['ideas' => ['type' => 'array', 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['store', 'kind', 'why'],
            'properties' => ['store' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'why' => ['type' => 'string']]]]]];
        $body = json_encode([
            'model' => 'gpt-4o-mini', 'temperature' => 0.3, 'max_tokens' => 600,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'stores', 'strict' => true, 'schema' => $schema]],
            'messages' => [
                ['role' => 'system', 'content' => 'You help a South African business decide where to buy things. Suggest 3 to 5 well-known store chains or kinds of shop (e.g. "Builders", "a plumbing supply store", "Makro") that usually stock the item, near the town given. Never give phone numbers, street addresses, prices or opening hours. "why" is one short sentence. Answer in English.'],
                ['role' => 'user', 'content' => 'Item: ' . mb_substr((string)$item['name_raw'], 0, 200) . "\nTown: " . mb_substr($town, 0, 120)],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) throw new ShProblem('The AI could not be reached — try again in a moment.', 502);
        $res = json_decode((string)$raw, true);
        if ($code !== 200) {
            error_log('[shopping] AI ideas ' . $code . ': ' . mb_substr((string)($res['error']['message'] ?? ''), 0, 300));
            throw new ShProblem($code === 401 ? 'The OpenAI key under Suppliers AI was refused.' : ($code === 429 ? 'The AI is busy or out of credit — try again later.' : 'The AI had no ideas this time.'), 502);
        }
        $out = json_decode((string)($res['choices'][0]['message']['content'] ?? ''), true);
        $prefs = ShKit::prefs($db, $companyId, $userId);
        $rows = [];
        foreach ((array)($out['ideas'] ?? []) as $i) {
            $store = ShKit::text($i['store'] ?? '', 80);
            if ($store === '') continue;
            $rows[] = ['kind' => 'ai', 'name' => $store, 'why' => ShKit::text(($i['kind'] ?? '') !== '' ? $i['kind'] . ' — ' . ($i['why'] ?? '') : ($i['why'] ?? ''), 200),
                       'price' => null, 'tel' => null, 'wa' => null, 'address' => '', 'maps' => self::mapsUrl($store, '', $town, null, null, $prefs['route_mode'])];
            if (count($rows) >= 5) break;
        }
        ShKit::audit($db, $companyId, $userId, 'shopping_ai_ideas', 'shopping_item', $itemId, ['ideas' => count($rows)]);
        return ['rows' => $rows, 'town' => $town];
    }

    /** Plan to buy the item at a store (or at a CRM supplier, made into a store). */
    public static function plan(PDO $db, int $companyId, int $userId, string $role, int $itemId, $storeId, $crmId): array
    {
        [, $list] = ShItems::access($db, $companyId, $userId, $role, $itemId, 'edit');
        $store = (int)$crmId > 0 ? self::forSupplier($db, $companyId, $userId, (int)$crmId) : self::storeId($db, $companyId, $storeId);
        $db->prepare("UPDATE shopping_items SET store_id = ?, updated_at = NOW() WHERE id = ?")->execute([$store, $itemId]);
        ShLists::touch($db, (int)$list['id']);
        return ShLists::item($db, $companyId, $itemId);
    }

    // ------------------------------------------------------------- price book

    /** One row an item: last and best price, where, how often, and whether it went up. */
    public static function priceBook(PDO $db, int $companyId, int $userId, string $q = ''): array
    {
        $args = [$companyId];
        $vis = ShLists::visibleSql($userId, $args);
        $where = '';
        if (($q = ShKit::text($q, 80)) !== '') { $where = ' AND h.item_name LIKE ?'; $args[] = '%' . ShKit::likeEscape($q) . '%'; }
        $st = $db->prepare("SELECT h.item_fingerprint, h.item_name, h.unit, h.price_cents, h.store_name, h.store_id, h.seen_at
                              FROM shopping_price_history h JOIN shopping_lists l ON l.id = h.list_id
                             WHERE h.company_id = ? AND $vis$where ORDER BY h.seen_at DESC, h.id DESC LIMIT 5000");
        $st->execute($args);
        $book = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $k = $h['item_fingerprint'];
            if (!isset($book[$k])) {
                $book[$k] = ['key' => $k, 'name' => (string)$h['item_name'], 'unit' => (string)($h['unit'] ?: 'ea'), 'last' => (int)$h['price_cents'], 'last_store' => $h['store_name'],
                             'last_at' => (string)$h['seen_at'], 'prev' => null, 'best' => (int)$h['price_cents'], 'best_store' => $h['store_name'], 'n' => 0, 'stores' => []];
            } elseif ($book[$k]['prev'] === null) {
                $book[$k]['prev'] = (int)$h['price_cents'];
            }
            $b = &$book[$k];
            $b['n']++;
            if ((int)$h['price_cents'] < $b['best']) { $b['best'] = (int)$h['price_cents']; $b['best_store'] = $h['store_name']; }
            if ($h['store_name']) $b['stores'][mb_strtolower($h['store_name'])] = true;
            unset($b);
        }
        $out = [];
        foreach ($book as $b) {
            $b['stores'] = count($b['stores']);
            $b['trend'] = $b['prev'] === null || $b['prev'] === $b['last'] ? 0 : ($b['last'] > $b['prev'] ? 1 : -1);
            $b['change_pct'] = $b['prev'] ? (int)round(($b['last'] - $b['prev']) / $b['prev'] * 100) : 0;
            $out[] = $b;
        }
        usort($out, fn($a, $b) => strcmp($b['last_at'], $a['last_at']));
        return array_slice($out, 0, 400);
    }
}
