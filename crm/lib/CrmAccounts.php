<?php
// crm/lib/CrmAccounts.php
//
// Customers and suppliers: the lists (search, filters, sorting, the columns
// each person chooses), one account with everything around it, saving with
// the company's rules (required fields, duplicate warnings, custom fields),
// soft delete / restore / permanent delete, bulk changes, tags, contacts and
// addresses, and a simple, explainable health score.

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmFields.php';
require_once __DIR__ . '/CrmLinked.php';
require_once __DIR__ . '/CrmCompliance.php';

final class CrmAccounts
{
    // ------------------------------------------------------------------ lists

    /**
     * One page of accounts.
     * $p: type, q, status (all|active|prospect|inactive|banned|preferred|cold|mine|deleted), tag, industry,
     *     region, owner, sort, dir, page, per, columns (wanted column keys)
     */
    public static function list(PDO $db, int $companyId, int $userId, array $p): array
    {
        $type = in_array($p['type'] ?? '', Crm::TYPES, true) ? $p['type'] : 'customer';
        $cfg = Crm::config($db, $companyId);
        $status = (string)($p['status'] ?? 'all');
        $q = mb_substr(trim((string)($p['q'] ?? '')), 0, 100);
        $page = max(1, (int)($p['page'] ?? 1));
        $per = max(10, min(200, (int)($p['per'] ?? 50)));
        $dir = strtolower((string)($p['dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

        $lastSql = "(SELECT MAX(i.created_at) FROM crm_interactions i WHERE i.company_id = a.company_id AND i.account_id = a.id)";
        $base = "FROM crm_accounts a WHERE a.company_id = ? AND a.type = ?";
        $params = [$companyId, $type];
        if ($q !== '') {
            $like = '%' . Crm::likeEscape($q) . '%';
            $digits = preg_replace('/\D+/', '', $q);
            $base .= " AND (a.name LIKE ? OR a.legal_name LIKE ? OR a.email LIKE ? OR a.vat_no LIKE ? OR a.reg_no LIKE ?"
                   . (strlen($digits) >= 4 ? " OR REPLACE(REPLACE(REPLACE(a.phone, ' ', ''), '-', ''), '+', '') LIKE ?" : '')
                   . " OR EXISTS (SELECT 1 FROM crm_contacts c WHERE c.company_id = a.company_id AND c.account_id = a.id
                                  AND (CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR c.email LIKE ?))"
                   . " OR EXISTS (SELECT 1 FROM crm_account_tags at JOIN crm_tags t ON t.id = at.tag_id WHERE at.account_id = a.id AND t.name LIKE ?))";
            array_push($params, $like, $like, $like, $like, $like);
            // match on the last nine digits so 082… finds +2782…
            if (strlen($digits) >= 4) $params[] = '%' . (strlen($digits) >= 9 ? substr($digits, -9) : $digits) . '%';
            array_push($params, $like, $like, $like);
        }
        foreach (['industry' => 'industry_id', 'region' => 'region_id'] as $k => $col) {
            if ((int)($p[$k] ?? 0) > 0) { $base .= " AND a.$col = ?"; $params[] = (int)$p[$k]; }
        }
        if ((int)($p['tag'] ?? 0) > 0) {
            $base .= " AND EXISTS (SELECT 1 FROM crm_account_tags at WHERE at.account_id = a.id AND at.tag_id = ?)";
            $params[] = (int)$p['tag'];
        }
        $owner = (string)($p['owner'] ?? '');
        if ($owner === 'me') { $base .= " AND a.owner_id = ?"; $params[] = $userId; }
        elseif ($owner === 'none') { $base .= " AND a.owner_id IS NULL"; }
        elseif ((int)$owner > 0) { $base .= " AND a.owner_id = ?"; $params[] = (int)$owner; }

        $coldCut = date('Y-m-d H:i:s', strtotime('-' . $cfg['cold_days'] . ' days'));
        $chips = [
            'all'       => "a.deleted_at IS NULL",
            'active'    => "a.deleted_at IS NULL AND a.status = 'active'",
            'prospect'  => "a.deleted_at IS NULL AND a.status = 'prospect'",
            'inactive'  => "a.deleted_at IS NULL AND a.status = 'inactive'",
            'banned'    => "a.deleted_at IS NULL AND a.status = 'banned'",
            'preferred' => "a.deleted_at IS NULL AND a.preferred = 1",
            'mine'      => "a.deleted_at IS NULL AND a.owner_id = " . (int)$userId,
            'cold'      => "a.deleted_at IS NULL AND a.status IN ('active','prospect') AND a.created_at < " . $db->quote($coldCut)
                         . " AND COALESCE($lastSql, '1000-01-01') < " . $db->quote($coldCut),
            'deleted'   => "a.deleted_at IS NOT NULL",
        ];
        $counts = [];
        $st = $db->prepare("SELECT " . implode(', ', array_map(fn($k, $c) => "SUM($c) AS `$k`", array_keys($chips), $chips)) . " $base");
        $st->execute($params);
        foreach (($st->fetch(PDO::FETCH_ASSOC) ?: []) as $k => $v) $counts[$k] = (int)$v;
        $cond = $chips[$status] ?? $chips['all'];

        $sorts = [
            'name' => 'a.name', 'created' => 'a.created_at', 'status' => "FIELD(a.status, 'active','prospect','inactive','banned')",
            'last_activity' => $lastSql, 'balance' => CrmLinked::balanceSql($db, $type), 'revenue' => CrmLinked::billedSql($db, $type),
            'open_deals' => "(SELECT COALESCE(SUM(o.amount), 0) FROM crm_opportunities o WHERE o.company_id = a.company_id AND o.account_id = a.id AND o.stage NOT IN ('won','lost'))",
            'owner' => "(SELECT CONCAT(u.first_name, ' ', u.last_name) FROM users u WHERE u.id = a.owner_id)",
            'city' => "(SELECT ad.city FROM crm_addresses ad WHERE ad.company_id = a.company_id AND ad.account_id = a.id ORDER BY FIELD(ad.type, 'head_office','billing','site','shipping'), ad.id LIMIT 1)",
            'industry' => "(SELECT ci.name FROM crm_industries ci WHERE ci.id = a.industry_id)",
        ];
        $sortKey = (string)($p['sort'] ?? '');
        $order = isset($sorts[$sortKey]) ? $sorts[$sortKey] . ' ' . $dir . ', a.name ASC' : 'a.preferred DESC, a.name ASC';
        if (!isset($sorts[$sortKey]) && $status === 'deleted') $order = 'a.deleted_at DESC';

        $st = $db->prepare("SELECT COUNT(*) $base AND $cond");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT a.*, $lastSql AS last_activity $base AND $cond ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return [
            'type' => $type, 'rows' => self::decorate($db, $companyId, $type, $rows), 'counts' => $counts,
            'total' => $total, 'page' => $page, 'per' => $per,
        ];
    }

    /** Add contacts, tags, owners, money, deals, compliance and custom values to list rows. */
    public static function decorate(PDO $db, int $companyId, string $type, array $rows): array
    {
        if (!$rows) return [];
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in = implode(',', $ids);
        $people = Crm::people($db, $companyId);
        $cfg = Crm::config($db, $companyId);

        $contacts = [];
        foreach ($db->query("SELECT id, account_id, first_name, last_name, role_title, email, phone, mobile, is_primary FROM crm_contacts
                              WHERE company_id = " . (int)$companyId . " AND account_id IN ($in) ORDER BY is_primary DESC, id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $aid = (int)$c['account_id'];
            if (!isset($contacts[$aid])) $contacts[$aid] = ['primary' => self::contactShape($c), 'count' => 0];
            $contacts[$aid]['count']++;
        }
        $tags = self::tagsFor($db, $ids);
        $cities = [];
        foreach ($db->query("SELECT account_id, city, type FROM crm_addresses WHERE company_id = " . (int)$companyId . " AND account_id IN ($in)
                              ORDER BY FIELD(type, 'head_office','billing','site','shipping'), id")->fetchAll(PDO::FETCH_ASSOC) as $ad) {
            if (!isset($cities[(int)$ad['account_id']]) && trim((string)$ad['city']) !== '') $cities[(int)$ad['account_id']] = (string)$ad['city'];
        }
        $deals = [];
        foreach ($db->query("SELECT account_id, COUNT(*) n, COALESCE(SUM(amount), 0) v FROM crm_opportunities
                              WHERE company_id = " . (int)$companyId . " AND account_id IN ($in) AND stage NOT IN ('won','lost') GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $deals[(int)$d['account_id']] = ['count' => (int)$d['n'], 'value' => (float)$d['v']];
        }
        $names = self::lookupNames($db, $companyId);
        $money = CrmLinked::money($db, $companyId, $type, $ids);
        $compliance = CrmCompliance::states($db, $companyId, array_fill_keys($ids, $type));
        $listFields = array_values(array_filter(CrmFields::forAccountType($db, $companyId, $type), fn($f) => $f['show_in_list']));
        $custom = $listFields ? CrmFields::values($db, $companyId, array_column($listFields, 'id'), $ids) : [];

        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $row = self::shape($r);
            $row['owner'] = $r['owner_id'] ? ['id' => (int)$r['owner_id'], 'name' => $people[(int)$r['owner_id']]['name'] ?? Crm::personName($db, $companyId, (int)$r['owner_id'])] : null;
            $row['contact'] = $contacts[$id]['primary'] ?? null;
            $row['contacts'] = $contacts[$id]['count'] ?? 0;
            $row['tags'] = $tags[$id] ?? [];
            $row['city'] = $cities[$id] ?? '';
            $row['industry'] = $names['industries'][(int)$r['industry_id']] ?? '';
            $row['region'] = $names['regions'][(int)$r['region_id']] ?? '';
            $row['open_deals'] = $deals[$id] ?? ['count' => 0, 'value' => 0.0];
            $row['money'] = $money[$id];
            $row['compliance'] = ['status' => $compliance[$id]['status'], 'blocking' => $compliance[$id]['blocking']];
            $row['last_activity'] = $r['last_activity'] ?? null;
            $row['custom'] = [];
            foreach ($listFields as $f) $row['custom']['cf_' . $f['id']] = CrmFields::display($f, $custom[$id][$f['id']] ?? null);
            $row['health'] = self::health($row, $cfg);
            $out[] = $row;
        }
        return $out;
    }

    /** Account columns as the API returns them. */
    public static function shape(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'type' => (string)$r['type'], 'name' => (string)$r['name'],
            'legal_name' => (string)($r['legal_name'] ?? ''), 'reg_no' => (string)($r['reg_no'] ?? ''), 'vat_no' => (string)($r['vat_no'] ?? ''),
            'email' => (string)($r['email'] ?? ''), 'phone' => (string)($r['phone'] ?? ''), 'website' => (string)($r['website'] ?? ''),
            'industry_id' => $r['industry_id'] !== null ? (int)$r['industry_id'] : null, 'region_id' => $r['region_id'] !== null ? (int)$r['region_id'] : null,
            'status' => (string)$r['status'], 'preferred' => (bool)$r['preferred'], 'currency' => (string)($r['currency'] ?? ''),
            'owner_id' => isset($r['owner_id']) && $r['owner_id'] !== null ? (int)$r['owner_id'] : null,
            'notes' => (string)($r['notes'] ?? ''), 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'] ?? null,
            'deleted_at' => $r['deleted_at'] ?? null,
            'supplier_kpis' => $r['type'] === 'supplier' ? [
                'on_time' => $r['on_time_percent'] !== null ? (float)$r['on_time_percent'] : null,
                'response_hours' => $r['avg_response_hours'] !== null ? (float)$r['avg_response_hours'] : null,
                'defect_rate' => $r['defect_rate_percent'] !== null ? (float)$r['defect_rate_percent'] : null,
            ] : null,
        ];
    }

    public static function contactShape(array $c): array
    {
        $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        return [
            'id' => (int)$c['id'], 'account_id' => (int)($c['account_id'] ?? 0), 'first_name' => (string)($c['first_name'] ?? ''), 'last_name' => (string)($c['last_name'] ?? ''),
            'name' => $name !== '' ? $name : ((string)($c['email'] ?? '') ?: 'Unnamed contact'),
            'role' => (string)($c['role_title'] ?? ''), 'email' => (string)($c['email'] ?? ''), 'phone' => (string)($c['phone'] ?? ''),
            'mobile' => (string)($c['mobile'] ?? ''), 'primary' => (bool)($c['is_primary'] ?? false), 'notes' => (string)($c['notes'] ?? ''),
        ];
    }

    /**
     * 0–100 with the reasons behind it. Customers: recent contact, paying on
     * time, open deals. Suppliers: recent contact, compliance, what we owe.
     */
    public static function health(array $row, array $cfg): array
    {
        if ($row['deleted_at']) return ['score' => 0, 'label' => 'Deleted', 'tone' => 'muted', 'why' => []];
        if ($row['status'] === 'banned') return ['score' => 0, 'label' => 'Blocked', 'tone' => 'danger', 'why' => ['Marked as blocked']];
        $score = 70;
        $why = [];
        $last = $row['last_activity'] ? (int)floor((time() - strtotime((string)$row['last_activity'])) / 86400) : null;
        if ($last === null) {
            $age = (int)floor((time() - strtotime((string)$row['created_at'])) / 86400);
            if ($age > 14) { $score -= 10; $why[] = 'No activity logged yet'; }
        } elseif ($last <= 14) { $score += 15; $why[] = 'In touch in the last two weeks'; }
        elseif ($last <= $cfg['cold_days']) { $score += 5; }
        else { $score -= 20; $why[] = "No contact for {$last} days"; }
        if ($row['type'] === 'customer') {
            if (($row['money']['overdue'] ?? 0) > 0.004) { $score -= 25; $why[] = 'Has overdue invoices'; }
            elseif (($row['money']['balance'] ?? 0) > 0.004) { $why[] = 'Invoices awaiting payment, none late'; }
            if (($row['money']['billed12'] ?? 0) > 0.004) { $score += 5; $why[] = 'Billed in the last 12 months'; }
            if (($row['open_deals']['count'] ?? 0) > 0) { $score += 10; $why[] = 'Has open deals'; }
        } else {
            $c = $row['compliance']['status'] ?? 'none';
            if ($c === 'missing' || $c === 'expired') { $score -= 30; $why[] = $c === 'missing' ? 'Required documents missing' : 'Compliance documents expired'; }
            elseif ($c === 'expiring') { $score -= 10; $why[] = 'Documents expiring soon'; }
            elseif ($c === 'valid') { $score += 10; $why[] = 'Compliance in order'; }
            if (($row['money']['overdue'] ?? 0) > 0.004) { $score -= 10; $why[] = 'We have overdue bills to pay'; }
        }
        if ($row['status'] === 'inactive') { $score -= 15; $why[] = 'Marked inactive'; }
        // Money owed late, or documents missing, always needs someone's attention.
        if (($row['money']['overdue'] ?? 0) > 0.004 && $row['type'] === 'customer') $score = min($score, 60);
        if (in_array($row['compliance']['status'] ?? '', ['missing', 'expired'], true) && $row['type'] === 'supplier') $score = min($score, 55);
        $score = max(0, min(100, $score));
        [$label, $tone] = $score >= 70 ? ['Healthy', 'success'] : ($score >= 40 ? ['Needs attention', 'warning'] : ['At risk', 'danger']);
        return ['score' => $score, 'label' => $label, 'tone' => $tone, 'why' => $why];
    }

    /** [industries => [id => name], regions => [id => name]] visible to the company. */
    public static function lookupNames(PDO $db, int $companyId): array
    {
        $out = ['industries' => [], 'regions' => []];
        foreach (['industries' => 'crm_industries', 'regions' => 'crm_regions'] as $k => $t) {
            try {
                $hasCo = isset(CrmSchema::columns($db, $t)['company_id']);
                $st = $db->prepare("SELECT id, name FROM `$t`" . ($hasCo ? " WHERE company_id IS NULL OR company_id = ?" : '') . " ORDER BY name");
                $st->execute($hasCo ? [$companyId] : []);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$k][(int)$r['id']] = (string)$r['name'];
            } catch (Throwable $e) { }
        }
        return $out;
    }

    /** Lists for pickers: [{id, name, custom}] */
    public static function lookups(PDO $db, int $companyId): array
    {
        $out = ['industries' => [], 'regions' => []];
        foreach (['industries' => 'crm_industries', 'regions' => 'crm_regions'] as $k => $t) {
            try {
                $hasCo = isset(CrmSchema::columns($db, $t)['company_id']);
                $st = $db->prepare("SELECT id, name" . ($hasCo ? ", company_id" : ", NULL AS company_id") . " FROM `$t`"
                                 . ($hasCo ? " WHERE company_id IS NULL OR company_id = ?" : '') . " ORDER BY name");
                $st->execute($hasCo ? [$companyId] : []);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$k][] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'custom' => $r['company_id'] !== null];
            } catch (Throwable $e) { }
        }
        return $out;
    }

    // ---------------------------------------------------------------- one row

    public static function row(PDO $db, int $companyId, int $id): ?array
    {
        $st = $db->prepare("SELECT * FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Everything the account page needs. */
    public static function full(PDO $db, int $companyId, int $userId, string $role, int $id): ?array
    {
        $r = self::row($db, $companyId, $id);
        if (!$r) return null;
        $st = $db->prepare("SELECT a.*, (SELECT MAX(i.created_at) FROM crm_interactions i WHERE i.company_id = a.company_id AND i.account_id = a.id) AS last_activity
                              FROM crm_accounts a WHERE a.id = ?");
        $st->execute([$id]);
        $row = self::decorate($db, $companyId, (string)$r['type'], [$st->fetch(PDO::FETCH_ASSOC)])[0];
        $names = self::lookupNames($db, $companyId);
        $row['industry'] = $names['industries'][(int)$r['industry_id']] ?? '';
        $row['region'] = $names['regions'][(int)$r['region_id']] ?? '';

        $st = $db->prepare("SELECT * FROM crm_contacts WHERE company_id = ? AND account_id = ? ORDER BY is_primary DESC, first_name, last_name, id");
        $st->execute([$companyId, $id]);
        $contactRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $contactDefs = CrmFields::defs($db, $companyId, 'contact');
        $contactVals = $contactDefs ? CrmFields::values($db, $companyId, array_column($contactDefs, 'id'), array_column($contactRows, 'id')) : [];
        $row['contact_list'] = array_map(function ($c) use ($contactDefs, $contactVals) {
            $s = self::contactShape($c);
            $s['custom'] = [];
            foreach ($contactDefs as $f) $s['custom'][$f['id']] = CrmFields::decode($f, $contactVals[(int)$c['id']][$f['id']] ?? null);
            return $s;
        }, $contactRows);

        $st = $db->prepare("SELECT * FROM crm_addresses WHERE company_id = ? AND account_id = ? ORDER BY FIELD(type, 'head_office','billing','shipping','site'), id");
        $st->execute([$companyId, $id]);
        $row['addresses'] = array_map(fn($a) => [
            'id' => (int)$a['id'], 'type' => (string)$a['type'], 'type_label' => Crm::ADDRESS_TYPES[$a['type']] ?? ucfirst((string)$a['type']),
            'line1' => (string)$a['line1'], 'line2' => (string)($a['line2'] ?? ''), 'city' => (string)($a['city'] ?? ''),
            'region' => (string)($a['region'] ?? ''), 'postal_code' => (string)($a['postal_code'] ?? ''), 'country' => (string)($a['country'] ?? 'ZA'),
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        $defs = CrmFields::forAccountType($db, $companyId, (string)$r['type']);
        $row['custom_values'] = CrmFields::valuesFor($db, $companyId, $defs, $id);
        $row['compliance_state'] = CrmCompliance::state($db, $companyId, $id, (string)$r['type']);
        $row['lifetime'] = CrmLinked::lifetime($db, $companyId, (string)$r['type'], $id);
        $row['series'] = CrmLinked::series($db, $companyId, (string)$r['type'], $id);
        $row['created_by_name'] = Crm::personName($db, $companyId, $r['created_by'] !== null ? (int)$r['created_by'] : null);

        if ($r['type'] === 'customer') {
            $row['portal_url'] = '';
            try {
                if (is_file(__DIR__ . '/../../portal/functions.php')) {
                    require_once __DIR__ . '/../../portal/functions.php';
                    if (function_exists('generatePortalToken')) {
                        $row['portal_url'] = '/portal/client/index.php?cid=' . $id . '&token=' . generatePortalToken($id);
                    }
                }
            } catch (Throwable $e) { }
        } else {
            $row['portal_url'] = '';
            try {
                if (is_file(__DIR__ . '/../../portal/functions.php')) {
                    require_once __DIR__ . '/../../portal/functions.php';
                    if (function_exists('generatePortalToken')) {
                        $row['portal_url'] = '/portal/supplier/index.php?sid=' . $id . '&token=' . generatePortalToken($id);
                    }
                }
            } catch (Throwable $e) { }
        }
        return $row;
    }

    // ----------------------------------------------------------------- saving

    /**
     * Create or update an account. $in keys: id, type, name, legal_name, reg_no, vat_no, email, phone,
     * website, industry_id, region_id, owner_id, status, preferred, currency, notes, custom{}, tags[],
     * confirm_duplicate; on create also contact{first_name,last_name,role,email,phone,mobile} and
     * address{type,line1,line2,city,region,postal_code,country}.
     * @return array{id: int, created: bool, duplicates?: array}
     */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $cur = $id > 0 ? self::row($db, $companyId, $id) : null;
        if ($id > 0 && !$cur) throw new CrmUserError('Account not found.');
        $creating = !$cur;
        $type = $cur ? (string)$cur['type'] : (in_array($in['type'] ?? '', Crm::TYPES, true) ? $in['type'] : '');
        if ($type === '') throw new CrmUserError('Choose customer or supplier.');
        $cfg = Crm::config($db, $companyId);
        $rules = Crm::rules($db, $companyId);
        $has = fn($k) => array_key_exists($k, $in);
        $str = fn($k, $max) => mb_substr(trim((string)(is_scalar($in[$k] ?? null) ? $in[$k] : '')), 0, $max);

        $v = [];
        if ($creating || $has('name')) {
            $v['name'] = $str('name', 255);
            if ($v['name'] === '') throw new CrmUserError('Give the ' . strtolower($cfg['labels'][$type]) . ' a name.');
        }
        foreach (['legal_name' => 255, 'reg_no' => 64, 'vat_no' => 64, 'notes' => 10000] as $k => $max) {
            if ($creating || $has($k)) $v[$k] = $str($k, $max) ?: null;
        }
        if ($creating || $has('email')) {
            $e = strtolower($str('email', 190));
            if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) throw new CrmUserError('The email address does not look right.');
            $v['email'] = $e ?: null;
        }
        if ($creating || $has('phone')) $v['phone'] = Crm::phone($str('phone', 64), $rules['crm_phone_format']) ?: null;
        if ($creating || $has('website')) {
            $w = $str('website', 255);
            if ($w !== '' && !preg_match('#^https?://#i', $w)) $w = 'https://' . $w;
            if ($w !== '' && !filter_var($w, FILTER_VALIDATE_URL)) throw new CrmUserError('The website address does not look right.');
            $v['website'] = $w ?: null;
        }
        if ($creating || $has('vat_no')) {
            if ($v['vat_no'] !== null && preg_match('/^\d[\d\s]*$/', $v['vat_no'])) $v['vat_no'] = preg_replace('/\s+/', '', $v['vat_no']);
        }
        $names = self::lookupNames($db, $companyId);
        foreach (['industry_id' => 'industries', 'region_id' => 'regions'] as $k => $list) {
            if ($creating || $has($k)) {
                $n = (int)($in[$k] ?? 0);
                if ($n > 0 && !isset($names[$list][$n])) throw new CrmUserError('Choose an industry and region from the list.');
                $v[$k] = $n > 0 ? $n : null;
            }
        }
        if ($creating || $has('owner_id')) {
            $o = (int)($in['owner_id'] ?? 0);
            if ($o > 0 && !isset(Crm::people($db, $companyId)[$o])) throw new CrmUserError('The owner must be someone in your company.');
            // Changing the owner: admins, the current owner, or anyone while nobody owns it.
            $was = (int)($cur['owner_id'] ?? 0);
            if ($cur && $o !== $was && $was !== 0 && $was !== $userId && !Crm::isAdmin($role)) {
                throw new CrmForbidden('Only an admin or the current owner can hand this account to someone else.');
            }
            $v['owner_id'] = $o > 0 ? $o : null;
        }
        if ($creating || $has('status')) {
            $s = $str('status', 20);
            if ($s === '') $s = $rules['crm_default_' . $type . '_status'] ?? 'active';
            if (!in_array($s, Crm::STATUSES, true)) throw new CrmUserError('Choose a status from the list.');
            $v['status'] = $s;
        }
        if ($creating || $has('preferred')) $v['preferred'] = !empty($in['preferred']) && $in['preferred'] !== '0' ? 1 : 0;
        if ($creating || $has('currency')) {
            $c = strtoupper($str('currency', 3));
            if ($creating && $c === '' && $rules['crm_default_currency'] !== '') $c = strtoupper($rules['crm_default_currency']);
            $valid = $c !== '' && preg_match('/^[A-Z]{3}$/', $c);
            if ($valid && is_file(__DIR__ . '/../../qi/lib/Currencies.php')) {
                require_once __DIR__ . '/../../qi/lib/Currencies.php';
                $valid = Currencies::isValid($c);
            }
            $v['currency'] = $valid ? $c : null;
        }

        // Company rules: required fields (on create, and never cleared later).
        foreach ($cfg['required'] as $req) {
            if ($req === 'contact') {
                if ($creating) {
                    $ct = is_array($in['contact'] ?? null) ? $in['contact'] : [];
                    if (trim((string)($ct['first_name'] ?? '')) === '' && trim((string)($ct['last_name'] ?? '')) === '' && trim((string)($ct['email'] ?? '')) === '') {
                        throw new CrmUserError('Add a contact person.');
                    }
                }
                continue;
            }
            if (!array_key_exists($req, $v)) continue;
            $empty = $v[$req] === null || $v[$req] === '';
            if ($empty && ($creating || (string)($cur[$req] ?? '') !== '')) throw new CrmUserError(Crm::REQUIRABLE[$req] . ' is required.');
        }

        // Custom fields
        $defs = CrmFields::forAccountType($db, $companyId, $type);
        $custom = CrmFields::validate($defs, is_array($in['custom'] ?? null) ? $in['custom'] : [], $creating);

        // Same name (the database allows one per type)
        if (isset($v['name']) && (!$cur || $v['name'] !== $cur['name'])) {
            $st = $db->prepare("SELECT id, deleted_at FROM crm_accounts WHERE company_id = ? AND type = ? AND name = ? AND id <> ?");
            $st->execute([$companyId, $type, $v['name'], $id]);
            if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
                $what = strtolower($cfg['labels'][$type]);
                if ($dup['deleted_at']) throw new CrmUserError("A deleted {$what} is already called “{$v['name']}”. Restore it from the Deleted list, or choose another name.");
                throw new CrmUserError("There is already a {$what} called “{$v['name']}”.");
            }
        }
        // Likely duplicates (on create, unless confirmed)
        if ($creating && $rules['crm_enable_duplicate_check'] !== '0' && empty($in['confirm_duplicate'])) {
            $similar = self::similar($db, $companyId, $type, $v, (float)$rules['crm_duplicate_threshold']);
            if ($similar) return ['id' => 0, 'created' => false, 'duplicates' => $similar];
        }

        try {
            $id = Crm::atomic($db, function () use ($db, $companyId, $userId, $in, $creating, $cur, $type, $custom, &$v, $id) {
                if ($creating) {
                    $v += ['type' => $type, 'company_id' => $companyId, 'created_by' => $userId];
                    if (!array_key_exists('owner_id', $v) || $v['owner_id'] === null) $v['owner_id'] = $userId;
                    $cols = array_keys($v);
                    $db->prepare("INSERT INTO crm_accounts (`" . implode('`, `', $cols) . "`, created_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", NOW())")
                       ->execute(array_values($v));
                    $id = (int)$db->lastInsertId();
                    $ct = is_array($in['contact'] ?? null) ? $in['contact'] : [];
                    if (trim((string)($ct['first_name'] ?? '')) !== '' || trim((string)($ct['last_name'] ?? '')) !== '' || trim((string)($ct['email'] ?? '')) !== '') {
                        self::saveContact($db, $companyId, $userId, ['account_id' => $id, 'primary' => 1] + $ct, false);
                    }
                    $ad = is_array($in['address'] ?? null) ? $in['address'] : [];
                    if (trim((string)($ad['line1'] ?? '')) !== '') self::saveAddress($db, $companyId, ['account_id' => $id] + $ad);
                    foreach (array_slice((array)($in['tags'] ?? []), 0, 20) as $t) {
                        if (is_string($t) && trim($t) !== '') self::addTag($db, $companyId, $id, $t, null);
                    }
                    Crm::audit($db, $companyId, $userId, 'crm_account_create', 'crm_account', $id, ['name' => $v['name'], 'type' => $type]);
                } elseif ($v) {
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
                    $db->prepare("UPDATE crm_accounts SET $sets, updated_at = NOW() WHERE id = ? AND company_id = ?")
                       ->execute(array_merge(array_values($v), [$id, $companyId]));
                    $changed = [];
                    foreach ($v as $k => $val) if ((string)($cur[$k] ?? '') !== (string)($val ?? '')) $changed[] = $k;
                    if ($changed || $custom) Crm::audit($db, $companyId, $userId, 'crm_account_update', 'crm_account', $id, ['changed' => $changed, 'custom' => array_keys($custom)]);
                }
                CrmFields::save($db, $companyId, $id, $custom);
                return $id;
            });
        } catch (Throwable $e) {
            if ($e instanceof PDOException && str_contains($e->getMessage(), '1062')) throw new CrmUserError('There is already an account with this name.');
            throw $e;
        }
        // Tell a new owner (not yourself)
        $newOwner = (int)($v['owner_id'] ?? 0);
        if ($newOwner > 0 && $newOwner !== $userId && ($creating || $newOwner !== (int)($cur['owner_id'] ?? 0))) {
            self::notify($db, $companyId, $newOwner, 'crm_account_owner', Crm::personName($db, $companyId, $userId) . ' made you the owner of ' . ($v['name'] ?? $cur['name']),
                         'You are now the account owner in the CRM.', '/crm/account_view.php?id=' . $id);
        }
        return ['id' => $id, 'created' => $creating];
    }

    /** Accounts that look like the one being created. */
    public static function similar(PDO $db, int $companyId, string $type, array $v, float $threshold): array
    {
        $conds = [];
        $params = [$companyId, $type];
        if (!empty($v['email'])) { $conds[] = "LOWER(a.email) = ?"; $params[] = strtolower($v['email']); }
        if (!empty($v['vat_no']) && strlen(preg_replace('/\D+/', '', $v['vat_no'])) >= 8) { $conds[] = "REPLACE(a.vat_no, ' ', '') = ?"; $params[] = preg_replace('/\s+/', '', $v['vat_no']); }
        if (!empty($v['reg_no'])) { $conds[] = "a.reg_no = ?"; $params[] = $v['reg_no']; }
        if (!empty($v['phone']) && strlen(preg_replace('/\D+/', '', $v['phone'])) >= 9) { $conds[] = "a.phone = ?"; $params[] = $v['phone']; }
        $norm = self::normName((string)($v['name'] ?? ''));
        $first = $norm !== '' ? explode(' ', $norm)[0] : '';
        if (mb_strlen($first) >= 3) { $conds[] = "a.name LIKE ?"; $params[] = '%' . Crm::likeEscape($first) . '%'; }
        if (!$conds) return [];
        $st = $db->prepare("SELECT a.id, a.name, a.email, a.phone, a.vat_no, a.reg_no, a.status FROM crm_accounts a
                             WHERE a.company_id = ? AND a.type = ? AND a.deleted_at IS NULL AND (" . implode(' OR ', $conds) . ") LIMIT 40");
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $why = [];
            if (!empty($v['email']) && strtolower((string)$r['email']) === strtolower($v['email'])) $why[] = 'same email';
            if (!empty($v['vat_no']) && preg_replace('/\s+/', '', (string)$r['vat_no']) === preg_replace('/\s+/', '', $v['vat_no'])) $why[] = 'same VAT number';
            if (!empty($v['reg_no']) && $r['reg_no'] === $v['reg_no']) $why[] = 'same registration number';
            if (!empty($v['phone']) && $r['phone'] === $v['phone']) $why[] = 'same phone';
            $sim = self::nameSimilarity($norm, self::normName((string)$r['name']));
            if ($sim >= $threshold) $why[] = 'similar name';
            if ($why) $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'status' => $r['status'], 'why' => $why, 'score' => round($sim, 2)];
        }
        usort($out, fn($a, $b) => count($b['why']) <=> count($a['why']) ?: $b['score'] <=> $a['score']);
        return array_slice($out, 0, 5);
    }

    public static function normName(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\b(pty|ltd|limited|cc|inc|llc|co|company|the|\(pty\)|bk|edms|bpk)\b/u', ' ', $s);
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function nameSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        similar_text($a, $b, $pct);
        $lev = 1 - levenshtein(substr($a, 0, 255), substr($b, 0, 255)) / max(strlen($a), strlen($b), 1);
        return max($pct / 100, $lev);
    }

    public static function setDeleted(PDO $db, int $companyId, int $userId, array $ids, bool $deleted): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return 0;
        $in = implode(',', $ids);
        if ($deleted) {
            $st = $db->prepare("UPDATE crm_accounts SET deleted_at = NOW(), updated_at = NOW() WHERE company_id = ? AND id IN ($in) AND deleted_at IS NULL");
        } else {
            // A restored account must not clash with a live one of the same name.
            $clash = $db->prepare("SELECT d.name FROM crm_accounts d JOIN crm_accounts l ON l.company_id = d.company_id AND l.type = d.type AND l.name = d.name AND l.id <> d.id AND l.deleted_at IS NULL
                                    WHERE d.company_id = ? AND d.id IN ($in) LIMIT 1");
            $clash->execute([$companyId]);
            if ($n = $clash->fetchColumn()) throw new CrmUserError("Another account is already called “{$n}”. Rename one of them first.");
            $st = $db->prepare("UPDATE crm_accounts SET deleted_at = NULL, updated_at = NOW() WHERE company_id = ? AND id IN ($in) AND deleted_at IS NOT NULL");
        }
        $st->execute([$companyId]);
        foreach ($ids as $id) Crm::audit($db, $companyId, $userId, $deleted ? 'crm_account_delete' : 'crm_account_restore', 'crm_account', $id);
        return $st->rowCount();
    }

    /** Remove a deleted account and everything the CRM keeps about it — only when nothing in the books points at it. */
    public static function purge(PDO $db, int $companyId, int $userId, int $id): void
    {
        $r = self::row($db, $companyId, $id);
        if (!$r) throw new CrmUserError('Account not found.');
        if (!$r['deleted_at']) throw new CrmUserError('Delete the account first; it can then be removed for good from the Deleted list.');
        if (CrmLinked::financialLinks($db, $companyId, $id) > 0) {
            throw new CrmUserError('This account has quotes, invoices, bills, purchase orders or projects, so it is kept for your records.');
        }
        $docs = $db->prepare("SELECT id FROM crm_compliance_docs WHERE company_id = ? AND account_id = ?");
        $docs->execute([$companyId, $id]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $docId) CrmCompliance::deleteDoc($db, $companyId, $userId, (int)$docId);
        $db->beginTransaction();
        try {
            $opps = $db->prepare("SELECT id FROM crm_opportunities WHERE company_id = ? AND account_id = ?");
            $opps->execute([$companyId, $id]);
            foreach ($opps->fetchAll(PDO::FETCH_COLUMN) as $oid) CrmFields::forget($db, $companyId, 'deal', (int)$oid);
            $cts = $db->prepare("SELECT id FROM crm_contacts WHERE company_id = ? AND account_id = ?");
            $cts->execute([$companyId, $id]);
            foreach ($cts->fetchAll(PDO::FETCH_COLUMN) as $cid) CrmFields::forget($db, $companyId, 'contact', (int)$cid);
            foreach (['crm_tasks', 'crm_interactions', 'crm_contacts', 'crm_addresses', 'crm_opportunities'] as $t) {
                $db->prepare("DELETE FROM `$t` WHERE company_id = ? AND account_id = ?")->execute([$companyId, $id]);
            }
            $db->prepare("DELETE FROM crm_account_tags WHERE account_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM crm_merge_candidates WHERE company_id = ? AND (left_id = ? OR right_id = ?)")->execute([$companyId, $id, $id]);
            try { $db->prepare("DELETE FROM email_links WHERE linked_type IN ('customer','supplier') AND linked_id = ? AND (company_id = ? OR company_id IS NULL)")->execute([$id, $companyId]); } catch (Throwable $e) { }
            CrmFields::forget($db, $companyId, 'account', $id);
            $db->prepare("DELETE FROM crm_accounts WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            Crm::audit($db, $companyId, $userId, 'crm_account_purge', 'crm_account', $id, ['name' => $r['name'], 'type' => $r['type']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Bulk change: status | owner | preferred | tag_add | tag_remove | delete | restore */
    public static function bulk(PDO $db, int $companyId, int $userId, string $role, array $ids, string $action, $value): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw new CrmUserError('Select at least one account.');
        if (count($ids) > 500) throw new CrmUserError('Change at most 500 accounts at once.');
        $in = implode(',', $ids);
        $chk = $db->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND id IN ($in)");
        $chk->execute([$companyId]);
        if ((int)$chk->fetchColumn() !== count($ids)) throw new CrmUserError('Some of these accounts no longer exist. Refresh and try again.');
        switch ($action) {
            case 'status':
                if (!in_array($value, Crm::STATUSES, true)) throw new CrmUserError('Choose a status.');
                $st = $db->prepare("UPDATE crm_accounts SET status = ?, updated_at = NOW() WHERE company_id = ? AND id IN ($in)");
                $st->execute([$value, $companyId]);
                break;
            case 'owner':
                if (!Crm::isAdmin($role)) throw new CrmForbidden('Only an admin can change owners in bulk.');
                $o = (int)$value;
                if ($o > 0 && !isset(Crm::people($db, $companyId)[$o])) throw new CrmUserError('The owner must be someone in your company.');
                $st = $db->prepare("UPDATE crm_accounts SET owner_id = ?, updated_at = NOW() WHERE company_id = ? AND id IN ($in)");
                $st->execute([$o > 0 ? $o : null, $companyId]);
                if ($o > 0 && $o !== $userId) {
                    self::notify($db, $companyId, $o, 'crm_account_owner', Crm::personName($db, $companyId, $userId) . ' gave you ' . count($ids) . ' ' . (count($ids) === 1 ? 'account' : 'accounts'),
                                 'You are now the owner in the CRM.', '/crm/#customers?owner=me');
                }
                break;
            case 'preferred':
                $st = $db->prepare("UPDATE crm_accounts SET preferred = ?, updated_at = NOW() WHERE company_id = ? AND id IN ($in)");
                $st->execute([$value ? 1 : 0, $companyId]);
                break;
            case 'tag_add':
                foreach ($ids as $id) self::addTag($db, $companyId, $id, (string)$value, null);
                return count($ids);
            case 'tag_remove':
                $st = $db->prepare("DELETE FROM crm_account_tags WHERE tag_id = ? AND account_id IN ($in)");
                $st->execute([(int)$value]);
                break;
            case 'delete':
                return self::setDeleted($db, $companyId, $userId, $ids, true);
            case 'restore':
                return self::setDeleted($db, $companyId, $userId, $ids, false);
            default:
                throw new CrmUserError('Unknown action.');
        }
        Crm::audit($db, $companyId, $userId, 'crm_account_bulk', 'crm_account', 0, ['action' => $action, 'ids' => $ids]);
        return $st->rowCount();
    }

    // ------------------------------------------------------------------- tags

    public static function tagsFor(PDO $db, array $accountIds): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (!$accountIds) return [];
        $out = [];
        foreach ($db->query("SELECT at.account_id, t.id, t.name, t.color FROM crm_account_tags at JOIN crm_tags t ON t.id = at.tag_id
                              WHERE at.account_id IN (" . implode(',', $accountIds) . ") ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $out[(int)$t['account_id']][] = ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'color' => (string)($t['color'] ?: '#8a7f76')];
        }
        return $out;
    }

    public static function allTags(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT t.id, t.name, t.color, (SELECT COUNT(*) FROM crm_account_tags at JOIN crm_accounts a ON a.id = at.account_id AND a.deleted_at IS NULL WHERE at.tag_id = t.id) AS n
                              FROM crm_tags t WHERE t.company_id = ? ORDER BY t.name");
        $st->execute([$companyId]);
        return array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'color' => (string)($t['color'] ?: '#8a7f76'), 'count' => (int)$t['n']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Tag an account by name (the tag is made when new). Returns the tag. */
    public static function addTag(PDO $db, int $companyId, int $accountId, string $name, ?string $color): array
    {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 64);
        if ($name === '') throw new CrmUserError('Give the tag a name.');
        $chk = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
        $chk->execute([$accountId, $companyId]);
        if (!$chk->fetchColumn()) throw new CrmUserError('Account not found.');
        $st = $db->prepare("SELECT id, name, color FROM crm_tags WHERE company_id = ? AND name = ?");
        $st->execute([$companyId, $name]);
        $tag = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tag) {
            $palette = ['#7a1f30', '#b08d57', '#2f7457', '#2f5c84', '#7a5ab8', '#b4577a', '#c08a2b', '#1f7a7a', '#4b4fa8', '#6b7d2b'];
            $color = Crm::color($color) ?: $palette[abs(crc32(mb_strtolower($name))) % count($palette)];
            $db->prepare("INSERT INTO crm_tags (company_id, name, color) VALUES (?, ?, ?)")->execute([$companyId, $name, $color]);
            $tag = ['id' => (int)$db->lastInsertId(), 'name' => $name, 'color' => $color];
        }
        $db->prepare("INSERT IGNORE INTO crm_account_tags (account_id, tag_id) VALUES (?, ?)")->execute([$accountId, (int)$tag['id']]);
        return ['id' => (int)$tag['id'], 'name' => (string)$tag['name'], 'color' => (string)$tag['color']];
    }

    public static function removeTag(PDO $db, int $companyId, int $accountId, int $tagId): void
    {
        $db->prepare("DELETE at FROM crm_account_tags at JOIN crm_tags t ON t.id = at.tag_id AND t.company_id = ?
                       WHERE at.account_id = ? AND at.tag_id = ?")->execute([$companyId, $accountId, $tagId]);
    }

    public static function saveTag(PDO $db, int $companyId, int $tagId, string $name, string $color): void
    {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 64);
        if ($name === '') throw new CrmUserError('Give the tag a name.');
        $dup = $db->prepare("SELECT id FROM crm_tags WHERE company_id = ? AND name = ? AND id <> ?");
        $dup->execute([$companyId, $name, $tagId]);
        if ($dup->fetchColumn()) throw new CrmUserError('There is already a tag with this name.');
        $st = $db->prepare("UPDATE crm_tags SET name = ?, color = ? WHERE id = ? AND company_id = ?");
        $st->execute([$name, Crm::color($color) ?: '#8a7f76', $tagId, $companyId]);
    }

    public static function deleteTag(PDO $db, int $companyId, int $tagId): void
    {
        $chk = $db->prepare("SELECT 1 FROM crm_tags WHERE id = ? AND company_id = ?");
        $chk->execute([$tagId, $companyId]);
        if (!$chk->fetchColumn()) throw new CrmUserError('Tag not found.');
        $db->prepare("DELETE FROM crm_account_tags WHERE tag_id = ?")->execute([$tagId]);
        $db->prepare("DELETE FROM crm_tags WHERE id = ? AND company_id = ?")->execute([$tagId, $companyId]);
    }

    // --------------------------------------------------------------- contacts

    /** Create or update a contact. Returns its id. */
    public static function saveContact(PDO $db, int $companyId, int $userId, array $in, bool $withCustom = true): int
    {
        $id = (int)($in['id'] ?? 0);
        $accountId = (int)($in['account_id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $st = $db->prepare("SELECT * FROM crm_contacts WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new CrmUserError('Contact not found.');
            $accountId = (int)$cur['account_id'];
        } else {
            $chk = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
            $chk->execute([$accountId, $companyId]);
            if (!$chk->fetchColumn()) throw new CrmUserError('Account not found.');
        }
        $rules = Crm::rules($db, $companyId);
        $s = fn($k, $max) => mb_substr(trim((string)(is_scalar($in[$k] ?? null) ? $in[$k] : '')), 0, $max);
        $first = $s('first_name', 120);
        $last = $s('last_name', 120);
        $email = strtolower($s('email', 190));
        if ($first === '' && $last === '' && $email === '') throw new CrmUserError('Give the contact a name or an email address.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new CrmUserError('The email address does not look right.');
        $phone = Crm::phone($s('phone', 64), $rules['crm_phone_format']);
        $mobile = Crm::phone($s('mobile', 64), $rules['crm_phone_format']);
        $role = $s('role', 120) ?: $s('role_title', 120);
        $notes = $s('notes', 5000);
        $primary = !empty($in['primary']) || !empty($in['is_primary']);
        $custom = [];
        if ($withCustom) {
            $defs = CrmFields::defs($db, $companyId, 'contact');
            $custom = CrmFields::validate($defs, is_array($in['custom'] ?? null) ? $in['custom'] : [], !$cur);
        }
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $n = $db->prepare("SELECT COUNT(*) FROM crm_contacts WHERE company_id = ? AND account_id = ? AND id <> ?");
            $n->execute([$companyId, $accountId, $id]);
            if ((int)$n->fetchColumn() === 0) $primary = true; // the first contact is the primary one
            if ($primary) $db->prepare("UPDATE crm_contacts SET is_primary = 0 WHERE company_id = ? AND account_id = ?")->execute([$companyId, $accountId]);
            if ($cur) {
                $db->prepare("UPDATE crm_contacts SET first_name = ?, last_name = ?, role_title = ?, email = ?, phone = ?, mobile = ?, notes = ?, is_primary = ?, updated_at = NOW()
                               WHERE id = ? AND company_id = ?")
                   ->execute([$first ?: null, $last ?: null, $role ?: null, $email ?: null, $phone ?: null, $mobile ?: null, $notes ?: null,
                              // the primary contact stays primary until another one takes over
                              $primary || (int)$cur['is_primary'] ? 1 : 0, $id, $companyId]);
            } else {
                $db->prepare("INSERT INTO crm_contacts (company_id, account_id, first_name, last_name, role_title, email, phone, mobile, notes, is_primary, created_by, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                   ->execute([$companyId, $accountId, $first ?: null, $last ?: null, $role ?: null, $email ?: null, $phone ?: null, $mobile ?: null, $notes ?: null, $primary ? 1 : 0, $userId]);
                $id = (int)$db->lastInsertId();
            }
            CrmFields::save($db, $companyId, $id, $custom);
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $id;
    }

    public static function deleteContact(PDO $db, int $companyId, int $userId, int $id): void
    {
        $st = $db->prepare("SELECT * FROM crm_contacts WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new CrmUserError('Contact not found.');
        $db->beginTransaction();
        try {
            // Documents and activity keep working without the person.
            foreach (['quotes', 'invoices', 'crm_interactions', 'crm_tasks', 'crm_opportunities'] as $t) {
                try { $db->prepare("UPDATE `$t` SET contact_id = NULL WHERE company_id = ? AND contact_id = ?")->execute([$companyId, $id]); } catch (Throwable $e) { }
            }
            CrmFields::forget($db, $companyId, 'contact', $id);
            $db->prepare("DELETE FROM crm_contacts WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            if ((int)$c['is_primary']) {
                $db->prepare("UPDATE crm_contacts SET is_primary = 1 WHERE company_id = ? AND account_id = ? ORDER BY id LIMIT 1")->execute([$companyId, (int)$c['account_id']]);
            }
            Crm::audit($db, $companyId, $userId, 'crm_contact_delete', 'crm_contact', $id, ['account_id' => (int)$c['account_id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Everyone across all accounts (the Contacts view). */
    public static function directory(PDO $db, int $companyId, array $p): array
    {
        $q = mb_substr(trim((string)($p['q'] ?? '')), 0, 100);
        $page = max(1, (int)($p['page'] ?? 1));
        $per = max(20, min(200, (int)($p['per'] ?? 60)));
        $type = in_array($p['type'] ?? '', Crm::TYPES, true) ? $p['type'] : '';
        $base = "FROM crm_contacts c JOIN crm_accounts a ON a.id = c.account_id AND a.company_id = c.company_id AND a.deleted_at IS NULL WHERE c.company_id = ?";
        $params = [$companyId];
        if ($type !== '') { $base .= " AND a.type = ?"; $params[] = $type; }
        if ($q !== '') {
            $like = '%' . Crm::likeEscape($q) . '%';
            $base .= " AND (CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.mobile LIKE ? OR c.role_title LIKE ? OR a.name LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $st = $db->prepare("SELECT COUNT(*) $base");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $sort = ($p['sort'] ?? '') === 'account' ? 'a.name, c.first_name' : (($p['sort'] ?? '') === 'recent' ? 'c.created_at DESC' : 'c.first_name, c.last_name');
        $st = $db->prepare("SELECT c.*, a.name AS account_name, a.type AS account_type $base ORDER BY $sort LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($params);
        $rows = array_map(fn($c) => self::contactShape($c) + ['account' => ['id' => (int)$c['account_id'], 'name' => $c['account_name'], 'type' => $c['account_type']]], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per];
    }

    // -------------------------------------------------------------- addresses

    public static function saveAddress(PDO $db, int $companyId, array $in): int
    {
        $id = (int)($in['id'] ?? 0);
        $accountId = (int)($in['account_id'] ?? 0);
        if ($id > 0) {
            $st = $db->prepare("SELECT account_id FROM crm_addresses WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $aid = $st->fetchColumn();
            if (!$aid) throw new CrmUserError('Address not found.');
            $accountId = (int)$aid;
        } else {
            $chk = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
            $chk->execute([$accountId, $companyId]);
            if (!$chk->fetchColumn()) throw new CrmUserError('Account not found.');
        }
        $s = fn($k, $max) => mb_substr(trim((string)(is_scalar($in[$k] ?? null) ? $in[$k] : '')), 0, $max);
        $type = isset(Crm::ADDRESS_TYPES[$in['type'] ?? '']) ? $in['type'] : 'head_office';
        $line1 = $s('line1', 255);
        if ($line1 === '') throw new CrmUserError('Fill in the street address.');
        $country = strtoupper($s('country', 2)) ?: 'ZA';
        if (!preg_match('/^[A-Z]{2}$/', $country)) $country = 'ZA';
        $vals = [$type, $line1, $s('line2', 255) ?: null, $s('city', 120) ?: null, $s('region', 120) ?: null, $s('postal_code', 20) ?: null, $country];
        if ($id > 0) {
            $db->prepare("UPDATE crm_addresses SET type = ?, line1 = ?, line2 = ?, city = ?, region = ?, postal_code = ?, country = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute(array_merge($vals, [$id, $companyId]));
            return $id;
        }
        $db->prepare("INSERT INTO crm_addresses (company_id, account_id, type, line1, line2, city, region, postal_code, country, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
           ->execute(array_merge([$companyId, $accountId], $vals));
        return (int)$db->lastInsertId();
    }

    public static function deleteAddress(PDO $db, int $companyId, int $id): void
    {
        $st = $db->prepare("DELETE FROM crm_addresses WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        if ($st->rowCount() === 0) throw new CrmUserError('Address not found.');
    }

    // ------------------------------------------------------------------ misc

    public static function notify(PDO $db, int $companyId, int $userId, string $type, string $title, string $message, string $url): void
    {
        try {
            if (!function_exists('fw_notify')) require_once __DIR__ . '/../../includes/notify.php';
            fw_notify($db, $companyId, $userId, $type, $title, $message, $url, ['ch' => 'general']);
        } catch (Throwable $e) {
            error_log('[crm] notify: ' . $e->getMessage());
        }
    }
}
