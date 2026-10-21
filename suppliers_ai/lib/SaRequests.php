<?php
// /suppliers_ai/lib/SaRequests.php — requests for quotes, from the shortlist to
// the order:
//   make one (what, details, quantity, where, needed by, quotes by, budget —
//   the budget stays inside the company), ask each supplier by email (through
//   Mail), WhatsApp or phone, see who replied (Mail is watched: a reply in the
//   conversation or a new email from the supplier counts), write down each
//   quote (amount, VAT, lead time, valid until), compare them, award one
//   (a prospect becomes an active supplier), thank the others, and make the
//   purchase order (SaPo).
// Who may change a request: whoever made it, admins and bookkeepers. Everyone
// in the company can look. Suppliers your policy blocks (expired or missing
// documents, CRM › Compliance) are not asked and cannot be awarded.

final class SaRequests
{
    public const STATUSES = ['draft' => 'Not sent yet', 'open' => 'Waiting for quotes', 'awarded' => 'Awarded', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];
    public const CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'phone' => 'Phone call', 'other' => 'Another way'];
    public const SUPPLIER_STATUS = ['to_ask' => 'Not asked yet', 'asked' => 'Asked — waiting', 'replied' => 'Replied', 'quoted' => 'Quoted',
                                    'declined' => 'Declined', 'awarded' => 'Awarded', 'not_chosen' => 'Not chosen'];
    public const VAT = ['incl' => 'VAT included', 'excl' => 'Plus VAT', 'none' => 'No VAT (not registered)'];

    // ------------------------------------------------------------------ access

    public static function canManage(array $req, int $userId, string $role): bool
    {
        return SaKit::canWrite($role) && ((int)$req['created_by'] === $userId || SaKit::canOrder($role));
    }

    /** A request of the company (deleted ones only when asked for). */
    public static function row(PDO $db, int $companyId, int $id, bool $withDeleted = false, bool $lock = false): array
    {
        $st = $db->prepare("SELECT * FROM sai_requests WHERE id = ? AND company_id = ?" . ($withDeleted ? '' : ' AND deleted_at IS NULL') . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new SaProblem('That request is not here any more.', 404);
        return $r;
    }

    private static function manageable(PDO $db, int $companyId, int $userId, string $role, int $id, bool $lock = false): array
    {
        $r = self::row($db, $companyId, $id, false, $lock);
        if (!self::canManage($r, $userId, $role)) throw new SaForbidden('Only ' . (SaKit::personName($db, (int)$r['created_by']) ?: 'the person who made it') . ', an admin or a bookkeeper can change this request.');
        return $r;
    }

    private static function recipient(PDO $db, int $companyId, int $recipientId, bool $lock = false): array
    {
        $st = $db->prepare("SELECT * FROM sai_request_suppliers WHERE id = ? AND company_id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$recipientId, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new SaProblem('That supplier is not on the request any more.', 404);
        return $r;
    }

    /** The request's purchase order while it counts: one cancelled (or gone) in Procurement is as if none was made. */
    public static function livePo(PDO $db, int $companyId, array $r, bool $lock = false): ?array
    {
        if (empty($r['po_id'])) return null;
        try {
            $st = $db->prepare("SELECT id, po_number, status, total FROM purchase_orders WHERE id = ? AND company_id = ?" . ($lock ? ' FOR UPDATE' : ''));
            $st->execute([(int)$r['po_id'], $companyId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        return $p && $p['status'] !== 'cancelled' ? $p : null;
    }

    /** Where a supplier stands on a request, from what happened. */
    public static function status(array $r): string
    {
        if (($r['decision'] ?? null) === 'awarded') return 'awarded';
        if (!empty($r['declined_at'])) return 'declined';
        if (($r['decision'] ?? null) === 'not_chosen') return 'not_chosen';
        if (($r['quote_cents'] ?? null) !== null) return 'quoted';
        if (!empty($r['replied_at'])) return 'replied';
        if (!empty($r['sent_at'])) return 'asked';
        return 'to_ask';
    }

    // ------------------------------------------------------------------ create

    /**
     * $in: title, details, quantity, location, category, requirements[], needed_by, quote_by, budget, query_id,
     *      suppliers: [{candidate_id} | {account_id}]
     * @return array{id: int, url: string, added: int, skipped: array}
     */
    public static function create(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        if (!SaKit::canWrite($role)) throw new SaForbidden('Viewers cannot ask for quotes.');
        $v = self::fields($in, true);
        $queryId = (int)($in['query_id'] ?? 0);
        if ($queryId > 0) {
            $st = $db->prepare("SELECT id FROM ai_queries WHERE id = ? AND company_id = ?");
            $st->execute([$queryId, $companyId]);
            if (!$st->fetchColumn()) $queryId = 0;
        }
        // The limit first: nothing is added to the CRM for a request that is refused
        $max = SaKit::settings($db, $companyId)['max_recipients'];
        if (self::offered((array)($in['suppliers'] ?? [])) > $max) throw new SaProblem('Ask at most ' . $max . ' suppliers on one request (Settings › Suppliers per request).', 422);
        $pick = self::resolve($db, $companyId, $userId, $role, (array)($in['suppliers'] ?? []));

        $db->beginTransaction();
        try {
            $cols = array_keys($v);
            $db->prepare("INSERT INTO sai_requests (company_id, created_by, query_id, status, `" . implode('`, `', $cols) . "`, created_at, updated_at) VALUES (?, ?, ?, 'draft'" . str_repeat(', ?', count($cols)) . ", NOW(), NOW())")
               ->execute(array_merge([$companyId, $userId, $queryId ?: null], array_values($v)));
            $id = (int)$db->lastInsertId();
            $added = self::insertSuppliers($db, $companyId, $userId, $id, $pick['ok']);
            self::event($db, $companyId, $id, null, $userId, 'created', ['suppliers' => $added, 'from_search' => $queryId ?: null]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        SaKit::audit($db, $companyId, $userId, 'sai_request_create', 'sai_request', $id, ['title' => $v['title'], 'suppliers' => $added]);
        return ['id' => $id, 'url' => '/suppliers_ai/request.php?id=' . $id, 'added' => $added, 'skipped' => $pick['skipped'], 'new_in_crm' => $pick['created']];
    }

    /** The request's own fields, checked. */
    private static function fields(array $in, bool $creating): array
    {
        $v = [];
        $has = fn($k) => $creating || array_key_exists($k, $in);
        if ($has('title')) {
            $v['title'] = SaKit::text($in['title'] ?? '', 190);
            if ($v['title'] === '') throw new SaProblem('Say in a few words what you need quotes for.', 422);
        }
        if ($has('details')) $v['details'] = SaKit::lines($in['details'] ?? '', 4000) ?: null;
        if ($has('quantity')) $v['quantity'] = SaKit::text($in['quantity'] ?? '', 80) ?: null;
        if ($has('location')) $v['location'] = SaKit::text($in['location'] ?? '', 160) ?: null;
        if ($has('category')) {
            $c = (string)($in['category'] ?? '');
            $v['category'] = isset(SaParse::TRADES[$c]) ? $c : null;
        }
        if ($has('requirements')) {
            $reqs = [];
            foreach ((array)($in['requirements'] ?? []) as $x) { $x = SaKit::text($x, 80); if ($x !== '' && !in_array($x, $reqs, true)) $reqs[] = $x; }
            $v['requirements'] = $reqs ? json_encode(array_slice($reqs, 0, 12), JSON_UNESCAPED_UNICODE) : null;
        }
        if ($has('needed_by')) {
            $raw = trim((string)($in['needed_by'] ?? ''));
            $v['needed_by'] = $raw === '' ? null : (SaKit::ymd($raw) ?? throw new SaProblem('The date it is needed by is not a date.', 422));
        }
        if ($has('quote_by')) {
            $raw = trim((string)($in['quote_by'] ?? ''));
            $q = $raw === '' ? null : (SaKit::ymd($raw) ?? throw new SaProblem('The date for quotes is not a date.', 422));
            if ($q === null && $creating) {
                $q = date('Y-m-d', strtotime('+5 days'));
                if (!empty($v['needed_by']) && $v['needed_by'] <= $q) $q = max(date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime($v['needed_by'] . ' -1 day')));
            }
            if ($q !== null && $creating && $q < date('Y-m-d')) throw new SaProblem('Quotes cannot be due in the past.', 422);
            $v['quote_by'] = $q;
        }
        if ($has('budget')) $v['budget_cents'] = SaKit::amount($in['budget'] ?? null, 'The budget');
        return $v;
    }

    /**
     * Suppliers to add: CRM suppliers by id, or search results (found on Google they are
     * added to the CRM as prospects; one you already have — same phone or email — is used).
     * @return array{ok: array, skipped: array, created: int}
     */
    /** How many different suppliers are offered (a CRM supplier or a search result, each once). */
    private static function offered(array $items): int
    {
        $keys = [];
        foreach (array_slice($items, 0, 60) as $it) {
            if (!is_array($it)) continue;
            if ((int)($it['account_id'] ?? 0) > 0) $keys['a' . (int)$it['account_id']] = 1;
            elseif ((int)($it['candidate_id'] ?? 0) > 0) $keys['c' . (int)$it['candidate_id']] = 1;
        }
        return count($keys);
    }

    private static function resolve(PDO $db, int $companyId, int $userId, string $role, array $items): array
    {
        CrmSchema::ensure($db);
        $ok = $skipped = [];
        $created = 0;
        foreach (array_slice($items, 0, 60) as $it) {
            if (!is_array($it)) continue;
            $accountId = (int)($it['account_id'] ?? 0);
            $candidateId = (int)($it['candidate_id'] ?? 0);
            if ($accountId <= 0 && $candidateId > 0) {
                // One listing that cannot become a supplier (a name clash in the CRM, gone from the search) is left out, not the request
                try {
                    $res = SaSearch::addToCrm($db, $companyId, $userId, $role, $candidateId);
                    if (!empty($res['duplicates'])) {
                        $same = null;
                        foreach ($res['duplicates'] as $d) if (array_intersect($d['why'], ['same email', 'same phone', 'same VAT number', 'same registration number'])) { $same = (int)$d['id']; break; }
                        $res = SaSearch::addToCrm($db, $companyId, $userId, $role, $candidateId, $same ?? 0, $same === null);
                    }
                } catch (SaProblem $e) {
                    $n = $db->prepare("SELECT name FROM ai_candidates WHERE id = ? AND company_id = ?");
                    $n->execute([$candidateId, $companyId]);
                    $skipped[] = ['name' => (string)($n->fetchColumn() ?: 'A search result'), 'why' => $e->getMessage()];
                    continue;
                }
                $accountId = (int)($res['account_id'] ?? 0);
                if ($accountId && empty($res['existing']) && empty($res['linked'])) $created++;
            }
            if ($accountId <= 0) continue;
            $ok[$accountId] = ['account_id' => $accountId, 'candidate_id' => $candidateId ?: null];
        }
        if (!$ok) return ['ok' => [], 'skipped' => $skipped, 'created' => $created];
        $st = $db->prepare("SELECT id, name, status FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL AND id IN (" . implode(',', array_map('intval', array_keys($ok))) . ")");
        $st->execute([$companyId]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $rows[(int)$a['id']] = $a;
        $states = CrmCompliance::states($db, $companyId, array_fill_keys(array_keys($rows), 'supplier'));
        foreach ($ok as $id => $p) {
            $a = $rows[$id] ?? null;
            if (!$a) { unset($ok[$id]); continue; }
            if ($a['status'] === 'banned') { $skipped[] = ['name' => $a['name'], 'why' => 'blocked in the CRM']; unset($ok[$id]); continue; }
            if (!empty($states[$id]['blocking'])) { $skipped[] = ['name' => $a['name'], 'why' => 'documents ' . $states[$id]['status'] . ' — your policy blocks them until renewed']; unset($ok[$id]); continue; }
            $ok[$id]['name'] = (string)$a['name'];
        }
        return ['ok' => array_values($ok), 'skipped' => $skipped, 'created' => $created];
    }

    private static function insertSuppliers(PDO $db, int $companyId, int $userId, int $requestId, array $list): int
    {
        $ins = $db->prepare("INSERT IGNORE INTO sai_request_suppliers (company_id, request_id, account_id, candidate_id, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $act = $db->prepare("INSERT INTO ai_actions (company_id, query_id, candidate_id, request_id, action, details_json, user_id, created_at)
                             SELECT company_id, query_id, id, ?, 'request', NULL, ?, NOW() FROM ai_candidates WHERE id = ? AND company_id = ?");
        $n = 0;
        foreach ($list as $p) {
            $ins->execute([$companyId, $requestId, $p['account_id'], $p['candidate_id'], mb_substr((string)$p['name'], 0, 255)]);
            if ($ins->rowCount() === 1) {
                $n++;
                if ($p['candidate_id']) $act->execute([$requestId, $userId, $p['candidate_id'], $companyId]);
            }
        }
        return $n;
    }

    // -------------------------------------------------------------------- read

    /** Requests for the list page. $p: status (active|open|draft|awarded|closed|all|deleted), mine, q */
    public static function list(PDO $db, int $companyId, int $userId, array $p): array
    {
        $where = ['r.company_id = ?'];
        $args = [$companyId];
        $status = (string)($p['status'] ?? 'active');
        if ($status === 'deleted') $where[] = 'r.deleted_at IS NOT NULL';
        else {
            $where[] = 'r.deleted_at IS NULL';
            if ($status === 'active') $where[] = "r.status IN ('draft', 'open')";
            elseif (isset(self::STATUSES[$status])) { $where[] = 'r.status = ?'; $args[] = $status; }
            elseif ($status === 'done') $where[] = "r.status IN ('awarded', 'closed', 'cancelled')";
        }
        if (!empty($p['mine'])) { $where[] = 'r.created_by = ?'; $args[] = $userId; }
        $q = SaKit::text($p['q'] ?? '', 80);
        if ($q !== '') { $where[] = '(r.title LIKE ? OR r.details LIKE ?)'; $args[] = '%' . SaKit::likeEscape($q) . '%'; $args[] = '%' . SaKit::likeEscape($q) . '%'; }
        $st = $db->prepare("SELECT r.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS who,
                                   COUNT(rs.id) AS suppliers, SUM(rs.sent_at IS NOT NULL) AS asked, SUM(rs.replied_at IS NOT NULL OR rs.quote_cents IS NOT NULL) AS replied,
                                   SUM(rs.quote_cents IS NOT NULL AND rs.declined_at IS NULL) AS quoted, SUM(rs.declined_at IS NOT NULL) AS declined,
                                   MIN(CASE WHEN rs.declined_at IS NOT NULL THEN NULL WHEN rs.quote_vat = 'excl' THEN ROUND(rs.quote_cents * 1.15) ELSE rs.quote_cents END) AS best,
                                   MAX(CASE WHEN rs.decision = 'awarded' THEN rs.name END) AS winner,
                                   MAX(CASE WHEN rs.decision = 'awarded' THEN (CASE WHEN rs.quote_vat = 'excl' THEN ROUND(rs.quote_cents * 1.15) ELSE rs.quote_cents END) END) AS winning
                              FROM sai_requests r LEFT JOIN sai_request_suppliers rs ON rs.request_id = r.id AND rs.company_id = r.company_id
                         LEFT JOIN users u ON u.id = r.created_by
                             WHERE " . implode(' AND ', $where) . "
                          GROUP BY r.id ORDER BY FIELD(r.status, 'open', 'draft', 'awarded', 'closed', 'cancelled'), COALESCE(r.quote_by, '9999-12-31'), r.created_at DESC LIMIT 300");
        $st->execute($args);
        return array_map(fn($r) => self::card($r, $userId), $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function card(array $r, int $userId): array
    {
        $trade = $r['category'] ? SaParse::trade((string)$r['category']) : null;
        return [
            'id' => (int)$r['id'], 'title' => (string)$r['title'], 'status' => (string)$r['status'], 'status_label' => self::STATUSES[$r['status']] ?? $r['status'],
            'location' => (string)($r['location'] ?? ''), 'quantity' => (string)($r['quantity'] ?? ''), 'needed_by' => $r['needed_by'], 'quote_by' => $r['quote_by'],
            'quote_due' => $r['quote_by'] ? SaKit::due((string)$r['quote_by']) : '', 'late' => $r['status'] === 'open' && $r['quote_by'] && $r['quote_by'] < date('Y-m-d'),
            'trade' => $trade, 'who' => (string)$r['who'], 'mine' => (int)$r['created_by'] === $userId, 'created_at' => (string)$r['created_at'],
            'suppliers' => (int)$r['suppliers'], 'asked' => (int)$r['asked'], 'replied' => (int)$r['replied'], 'quoted' => (int)$r['quoted'], 'declined' => (int)$r['declined'],
            'best' => $r['best'] !== null ? (int)$r['best'] : null, 'winner' => $r['winner'], 'winning' => $r['winning'] !== null ? (int)$r['winning'] : null,
            'budget' => $r['budget_cents'] !== null ? (int)$r['budget_cents'] : null, 'po_id' => $r['po_id'] ? (int)$r['po_id'] : null, 'deleted' => $r['deleted_at'] !== null,
            'url' => '/suppliers_ai/request.php?id=' . (int)$r['id'],
        ];
    }

    /** One request with its suppliers, quotes and what happened. */
    public static function get(PDO $db, int $companyId, int $userId, string $role, int $id): array
    {
        $r = self::row($db, $companyId, $id, true);
        if ($r['deleted_at'] === null && in_array($r['status'], ['draft', 'open'], true)) {
            try {
                self::seen($db, $r, $userId, self::refresh($db, $companyId, $id));
            } catch (Throwable $e) {
                error_log('[suppliers_ai] refresh: ' . $e->getMessage());
            }
            $r = self::row($db, $companyId, $id, true);
        }
        CrmSchema::ensure($db);
        $st = $db->prepare("SELECT rs.*, a.name AS account_name, a.phone AS account_phone, a.email AS account_email, a.status AS account_status, a.vat_no, a.deleted_at AS account_deleted,
                                   (SELECT CONCAT_WS('|', COALESCE(c.first_name, ''), TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), COALESCE(c.email, ''), COALESCE(NULLIF(c.mobile, ''), c.phone, ''))
                                      FROM crm_contacts c WHERE c.account_id = rs.account_id AND c.company_id = rs.company_id ORDER BY c.is_primary DESC, (c.email IS NULL OR c.email = ''), c.id LIMIT 1) AS contact,
                                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS sent_by_name
                              FROM sai_request_suppliers rs LEFT JOIN crm_accounts a ON a.id = rs.account_id AND a.company_id = rs.company_id
                         LEFT JOIN users u ON u.id = rs.sent_by
                             WHERE rs.request_id = ? AND rs.company_id = ? ORDER BY rs.quote_cents IS NULL, rs.quote_cents, rs.id");
        $st->execute([$id, $companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $states = $rows ? CrmCompliance::states($db, $companyId, array_fill_keys(array_map(fn($x) => (int)$x['account_id'], $rows), 'supplier')) : [];
        // Quotes that stand: one from a supplier who then declined is not compared
        $quotes = array_values(array_filter(array_map(fn($x) => $x['quote_cents'] !== null && $x['declined_at'] === null ? self::inclVat((int)$x['quote_cents'], (string)$x['quote_vat']) : null, $rows), fn($x) => $x !== null));
        $best = $quotes ? min($quotes) : null;
        $sups = [];
        foreach ($rows as $x) {
            [$first, $cname, $cemail, $cphone] = array_pad(explode('|', (string)$x['contact'], 4), 4, '');
            $phone = $cphone !== '' ? $cphone : (string)$x['account_phone'];
            $emails = array_values(array_unique(array_filter([strtolower(trim($cemail)), strtolower(trim((string)$x['account_email']))])));
            $status = self::status($x);
            $sups[] = [
                'id' => (int)$x['id'], 'account_id' => (int)$x['account_id'], 'name' => (string)($x['account_name'] ?? $x['name']), 'in_crm' => $x['account_name'] !== null && $x['account_deleted'] === null,
                'prospect' => $x['account_status'] === 'prospect', 'first_name' => trim($first), 'contact' => trim($cname), 'emails' => $emails, 'phone' => $phone, 'dial' => SaKit::dialable($phone),
                'vat' => trim((string)$x['vat_no']) !== '',
                'status' => $status, 'status_label' => self::SUPPLIER_STATUS[$status],
                'channel' => $x['channel'], 'channel_label' => $x['channel'] ? (self::CHANNELS[$x['channel']] ?? $x['channel']) : '', 'sent_at' => $x['sent_at'], 'sent_by' => (string)$x['sent_by_name'],
                'sent_to' => (string)($x['sent_to'] ?? ''), 'mail' => $x['thread_id'] && (int)$x['sent_by'] === $userId ? '/mail/?open=t' . (int)$x['thread_id'] : '',
                'reply_mail' => $x['reply_email_id'] && (int)$x['sent_by'] === $userId ? '/mail/?open=e' . (int)$x['reply_email_id'] : '',
                'replied_at' => $x['replied_at'], 'declined_at' => $x['declined_at'], 'reminded_at' => $x['reminded_at'],
                'quote' => $x['quote_cents'] !== null ? (int)$x['quote_cents'] : null, 'quote_vat' => $x['quote_vat'], 'quote_incl' => $x['quote_cents'] !== null ? self::inclVat((int)$x['quote_cents'], (string)$x['quote_vat']) : null,
                'lead_days' => $x['lead_days'] !== null ? (int)$x['lead_days'] : null, 'valid_until' => $x['valid_until'], 'quote_notes' => (string)($x['quote_notes'] ?? ''), 'quoted_at' => $x['quoted_at'],
                'best' => $best !== null && $x['quote_cents'] !== null && $x['declined_at'] === null && self::inclVat((int)$x['quote_cents'], (string)$x['quote_vat']) === $best && count($quotes) > 1,
                'over_budget' => $r['budget_cents'] !== null && $x['quote_cents'] !== null && self::inclVat((int)$x['quote_cents'], (string)$x['quote_vat']) > (int)$r['budget_cents'],
                'expired_quote' => $x['valid_until'] && $x['valid_until'] < date('Y-m-d'),
                'compliance' => SaSearch::compliance($states[(int)$x['account_id']] ?? null),
            ];
        }
        // Quotes first, cheapest (with VAT) first; then the rest as they were added
        usort($sups, fn($a, $b) => ($a['quote_incl'] === null) <=> ($b['quote_incl'] === null) ?: ($a['quote_incl'] ?? 0) <=> ($b['quote_incl'] ?? 0) ?: $a['id'] <=> $b['id']);
        $st = $db->prepare("SELECT e.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS who, rs.name AS supplier
                              FROM sai_events e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN sai_request_suppliers rs ON rs.id = e.recipient_id
                             WHERE e.request_id = ? AND e.company_id = ? ORDER BY e.id DESC LIMIT 60");
        $st->execute([$id, $companyId]);
        $events = array_map(fn($e) => ['action' => (string)$e['action'], 'who' => (string)$e['who'], 'supplier' => (string)($e['supplier'] ?? ''), 'at' => (string)$e['created_at'],
                                       'data' => json_decode((string)$e['payload_json'], true) ?: []], $st->fetchAll(PDO::FETCH_ASSOC));
        $po = null;
        if ($p = self::livePo($db, $companyId, $r)) {
            $po = ['id' => (int)$p['id'], 'number' => (string)$p['po_number'], 'status' => (string)$p['status'], 'total' => (int)round((float)$p['total'] * 100), 'url' => '/procurement/po_view.php?id=' . (int)$p['id']];
        }
        $trade = $r['category'] ? SaParse::trade((string)$r['category']) : null;
        $settings = SaKit::settings($db, $companyId);
        return [
            'request' => [
                'id' => (int)$r['id'], 'title' => (string)$r['title'], 'details' => (string)($r['details'] ?? ''), 'quantity' => (string)($r['quantity'] ?? ''),
                'location' => (string)($r['location'] ?? ''), 'category' => $r['category'], 'trade' => $trade, 'requirements' => json_decode((string)($r['requirements'] ?? ''), true) ?: [],
                'needed_by' => $r['needed_by'], 'quote_by' => $r['quote_by'], 'quote_due' => $r['quote_by'] ? SaKit::due((string)$r['quote_by']) : '',
                'budget' => $r['budget_cents'] !== null ? (int)$r['budget_cents'] : null, 'status' => (string)$r['status'], 'status_label' => self::STATUSES[$r['status']] ?? $r['status'],
                'query_id' => $r['query_id'] ? (int)$r['query_id'] : null, 'awarded_id' => $r['awarded_id'] ? (int)$r['awarded_id'] : null, 'po' => $po,
                'who' => SaKit::personName($db, (int)$r['created_by']), 'mine' => (int)$r['created_by'] === $userId, 'created_at' => (string)$r['created_at'],
                'closed_at' => $r['closed_at'], 'deleted' => $r['deleted_at'] !== null,
            ],
            'suppliers' => $sups,
            'events' => $events,
            'summary' => [
                'suppliers' => count($sups), 'asked' => count(array_filter($sups, fn($x) => $x['sent_at'] !== null)),
                'replied' => count(array_filter($sups, fn($x) => $x['replied_at'] !== null || $x['quote'] !== null)), 'quoted' => count($quotes),
                'best' => $best, 'highest' => $quotes ? max($quotes) : null, 'average' => $quotes ? (int)round(array_sum($quotes) / count($quotes)) : null,
            ],
            'can' => ['manage' => self::canManage($r, $userId, $role), 'order' => SaKit::canOrder($role), 'max' => $settings['max_recipients'], 'ai' => SaKit::aiReady($settings)],
        ];
    }

    /** A quote with VAT, for comparing like with like. */
    public static function inclVat(int $cents, string $vat): int
    {
        return $vat === 'excl' ? (int)round($cents * 1.15) : $cents;
    }

    // ----------------------------------------------------------------- change

    public static function update(PDO $db, int $companyId, int $userId, string $role, int $id, array $in): array
    {
        $r = self::manageable($db, $companyId, $userId, $role, $id);
        $v = self::fields(array_intersect_key($in, array_flip(['title', 'details', 'quantity', 'location', 'category', 'requirements', 'needed_by', 'quote_by', 'budget'])), false);
        if (!$v) return self::get($db, $companyId, $userId, $role, $id);
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
        $db->prepare("UPDATE sai_requests SET $sets, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute(array_merge(array_values($v), [$id, $companyId]));
        $changed = [];
        foreach ($v as $k => $val) if ((string)($r[$k] ?? '') !== (string)($val ?? '')) $changed[] = $k;
        if ($changed) self::event($db, $companyId, $id, null, $userId, 'edited', ['fields' => $changed]);
        return self::get($db, $companyId, $userId, $role, $id);
    }

    public static function addSuppliers(PDO $db, int $companyId, int $userId, string $role, int $id, array $items): array
    {
        $r = self::manageable($db, $companyId, $userId, $role, $id);
        if (in_array($r['status'], ['closed', 'cancelled'], true)) throw new SaProblem('Open the request again first.', 409);
        $st = $db->prepare("SELECT COUNT(*) FROM sai_request_suppliers WHERE request_id = ?");
        $st->execute([$id]);
        $have = (int)$st->fetchColumn();
        $max = SaKit::settings($db, $companyId)['max_recipients'];
        $tooMany = fn(int $n) => $have + $n > $max ? throw new SaProblem('A request can go to at most ' . $max . ' suppliers (Settings › Suppliers per request).', 422) : null;
        $tooMany(self::offered($items));
        $pick = self::resolve($db, $companyId, $userId, $role, $items);
        $tooMany(count($pick['ok']));
        $added = self::insertSuppliers($db, $companyId, $userId, $id, $pick['ok']);
        if ($added) {
            self::event($db, $companyId, $id, null, $userId, 'suppliers_added', ['count' => $added, 'names' => array_slice(array_column($pick['ok'], 'name'), 0, 8)]);
            $db->prepare("UPDATE sai_requests SET updated_at = NOW() WHERE id = ?")->execute([$id]);
        }
        return ['added' => $added, 'skipped' => $pick['skipped'], 'new_in_crm' => $pick['created']] + self::get($db, $companyId, $userId, $role, $id);
    }

    public static function removeSupplier(PDO $db, int $companyId, int $userId, string $role, int $recipientId): array
    {
        $x = self::recipient($db, $companyId, $recipientId);
        $r = self::manageable($db, $companyId, $userId, $role, (int)$x['request_id']);
        if ($x['decision'] === 'awarded') throw new SaProblem('Undo the award before taking this supplier off.', 409);
        $db->prepare("DELETE FROM sai_request_suppliers WHERE id = ?")->execute([$recipientId]);
        self::event($db, $companyId, (int)$r['id'], null, $userId, 'supplier_removed', ['name' => $x['name'], 'had_quote' => $x['quote_cents'] !== null]);
        return self::get($db, $companyId, $userId, $role, (int)$r['id']);
    }

    // -------------------------------------------------------------- asking

    /** What the email to each supplier says (placeholders per supplier filled in by send). */
    public static function compose(PDO $db, int $companyId, int $userId, string $role, int $id): array
    {
        $r = self::row($db, $companyId, $id);
        $t = SaKit::template($db, $companyId);
        $vars = self::vars($db, $companyId, $userId, $r, null);
        $keep = ['first_name' => '{first_name}', 'supplier' => '{supplier}'];
        return [
            'subject' => self::fill($t['subject'], $keep + $vars),
            'body' => self::fill($t['body'], $keep + $vars),
            'thanks' => [
                'subject' => 'Re: ' . self::fill($t['subject'], $keep + $vars),
                'body' => "Hi {first_name},\n\nThank you for your quotation for “" . $r['title'] . "”. We have gone with another supplier this time, but we appreciate your time and hope to work with you on the next one.\n\nKind regards\n" . $vars['sender'] . "\n" . $vars['company'],
            ],
            'reminder' => "Hi {first_name},\n\nJust a friendly reminder about our request for a quotation for “" . $r['title'] . "”" . ($r['quote_by'] ? ', which we need by ' . date('l j F', strtotime($r['quote_by'])) : '') . ". If you cannot help with this one, a short reply to say so is appreciated.\n\nKind regards\n" . $vars['sender'],
            'whatsapp' => self::whatsapp($r, $vars),
            'mailboxes' => SaMail::mailboxes($db, $companyId, $userId),
            'placeholders' => SaKit::PLACEHOLDERS,
            'custom' => $t['custom'],
            'ai' => SaKit::aiReady(SaKit::settings($db, $companyId)),
        ];
    }

    /** A request-for-quote email written by AI from the request (the budget is never given to it). */
    public static function draft(PDO $db, int $companyId, int $userId, string $role, int $id): array
    {
        // Only those who may send it, and within the company's ceiling on paid look-ups (each draft counts as one)
        $r = self::manageable($db, $companyId, $userId, $role, $id);
        $s = SaKit::settings($db, $companyId);
        if (!SaKit::aiReady($s)) throw new SaProblem('AI writing is switched off. An admin can switch it on under Settings.', 409);
        if (SaKit::quota($db, $companyId, $s)['left'] <= 0) throw new SaProblem('The company’s AI limit for now is used up — use the email as it is, or try again later.', 429);
        if (($wait = SaKit::rateLimit('draft:' . $userId, 10, 600)) > 0) throw new SaProblem('Wait ' . ceil($wait / 60) . ' min before asking the AI again.', 429);
        $req = ['title' => $r['title'], 'details' => (string)$r['details'], 'quantity' => (string)$r['quantity'], 'location' => (string)$r['location'],
                'needed_by' => $r['needed_by'], 'quote_by' => $r['quote_by'], 'requirements' => json_decode((string)($r['requirements'] ?? ''), true) ?: []];
        $db->prepare("INSERT INTO ai_actions (company_id, query_id, candidate_id, request_id, action, details_json, user_id, created_at) VALUES (?, 0, 0, ?, 'ai_draft', NULL, ?, NOW())")
           ->execute([$companyId, $id, $userId]);
        return SaAi::draft($s, $req, (string)SaKit::company($db, $companyId)['name'], SaKit::personName($db, $userId));
    }

    /**
     * Email one supplier. $in: mailbox_id, to (list), subject, body, kind (ask | reminder | thanks)
     */
    public static function send(PDO $db, int $companyId, int $userId, string $role, int $recipientId, array $in): array
    {
        $x = self::recipient($db, $companyId, $recipientId);
        $r = self::manageable($db, $companyId, $userId, $role, (int)$x['request_id']);
        $kind = in_array($in['kind'] ?? 'ask', ['ask', 'reminder', 'thanks'], true) ? (string)($in['kind'] ?? 'ask') : 'ask';
        if ($kind !== 'thanks' && in_array($r['status'], ['closed', 'cancelled', 'awarded'], true)) throw new SaProblem('This request is ' . strtolower(self::STATUSES[$r['status']]) . ' — open it again to ask more suppliers.', 409);
        if ($kind === 'ask') {
            $state = CrmCompliance::states($db, $companyId, [(int)$x['account_id'] => 'supplier'])[(int)$x['account_id']] ?? null;
            if (!empty($state['blocking'])) throw new SaProblem($x['name'] . '’s documents are ' . $state['status'] . ' — your company’s policy blocks requests to them until they are renewed in the CRM.', 409);
        }
        if (($wait = SaKit::rateLimit('send:' . $userId, 60, 600)) > 0) throw new SaProblem('That is a lot of email in a few minutes — wait ' . ceil($wait / 60) . ' min.', 429);
        $vars = self::vars($db, $companyId, $userId, $r, $x);
        $subject = self::fill(SaKit::text($in['subject'] ?? '', 300), $vars);
        $body = self::fill(SaKit::lines($in['body'] ?? '', 20000), $vars);
        $to = is_array($in['to'] ?? null) ? $in['to'] : preg_split('/[,;\s]+/', (string)($in['to'] ?? ''));
        $to = array_slice(array_values(array_filter(array_map('trim', $to ?: []))), 0, 5);
        if (!$to) $to = self::emailsFor($db, $companyId, (int)$x['account_id']);
        // A reminder or a thank-you goes from the mailbox the request went out from (when it is still yours), into that conversation
        $mailbox = (int)($in['mailbox_id'] ?? 0);
        if ($kind !== 'ask' && $x['email_id']) {
            $st = $db->prepare("SELECT account_id FROM emails WHERE email_id = ? AND company_id = ?");
            $st->execute([(int)$x['email_id'], $companyId]);
            $from = (int)$st->fetchColumn();
            if ($from && in_array($from, array_column(SaMail::mailboxes($db, $companyId, $userId), 'id'), true)) $mailbox = $from;
        }
        $res = SaMail::send($db, $companyId, $userId, (int)$x['account_id'], (int)$r['id'], [
            'mailbox_id' => $mailbox, 'to' => $to, 'subject' => $subject, 'body' => $body,
            'reply_to_email_id' => $kind === 'ask' ? 0 : (int)$x['email_id'],
        ]);
        if ($kind === 'ask') {
            $db->prepare("UPDATE sai_request_suppliers SET sent_at = COALESCE(sent_at, NOW()), channel = 'email', sent_by = ?, sent_to = ?, email_id = ?, thread_id = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$userId, mb_substr(implode(', ', $to), 0, 500), $res['email_id'], $res['thread_id'], $recipientId]);
            if ($r['status'] === 'draft') $db->prepare("UPDATE sai_requests SET status = 'open', updated_at = NOW() WHERE id = ? AND status = 'draft'")->execute([(int)$r['id']]);
        } elseif ($kind === 'reminder') {
            $db->prepare("UPDATE sai_request_suppliers SET reminded_at = NOW(), updated_at = NOW()" . (!$x['thread_id'] && $res['thread_id'] ? ', thread_id = ' . (int)$res['thread_id'] : '') . " WHERE id = ?")->execute([$recipientId]);
        }
        self::event($db, $companyId, (int)$r['id'], $recipientId, $userId, ['ask' => 'asked', 'reminder' => 'reminded', 'thanks' => 'thanked'][$kind], ['channel' => 'email', 'to' => implode(', ', $to)]);
        return ['sent' => true, 'to' => $to, 'from' => $res['from']];
    }

    /** Asked another way (WhatsApp, a call), declined, or undo. how: whatsapp | phone | other | declined | undeclined | unasked */
    public static function mark(PDO $db, int $companyId, int $userId, string $role, int $recipientId, string $how): array
    {
        $x = self::recipient($db, $companyId, $recipientId);
        $r = self::manageable($db, $companyId, $userId, $role, (int)$x['request_id']);
        if (in_array($how, ['whatsapp', 'phone', 'other'], true)) {
            if (in_array($r['status'], ['closed', 'cancelled'], true)) throw new SaProblem('Open the request again first.', 409);
            $state = CrmCompliance::states($db, $companyId, [(int)$x['account_id'] => 'supplier'])[(int)$x['account_id']] ?? null;
            if (!empty($state['blocking'])) throw new SaProblem($x['name'] . '’s documents are ' . $state['status'] . ' — your company’s policy blocks requests to them until they are renewed.', 409);
            $db->prepare("UPDATE sai_request_suppliers SET sent_at = COALESCE(sent_at, NOW()), channel = COALESCE(channel, ?), sent_by = COALESCE(sent_by, ?), updated_at = NOW() WHERE id = ?")->execute([$how, $userId, $recipientId]);
            if ($r['status'] === 'draft') $db->prepare("UPDATE sai_requests SET status = 'open', updated_at = NOW() WHERE id = ?")->execute([(int)$r['id']]);
            self::event($db, $companyId, (int)$r['id'], $recipientId, $userId, 'asked', ['channel' => $how]);
            if ($how !== 'other') {
                try {
                    $db->prepare("INSERT INTO crm_interactions (company_id, account_id, type, subject, body, via, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'manual', ?, NOW())")
                       ->execute([$companyId, (int)$x['account_id'], $how === 'whatsapp' ? 'whatsapp' : 'call', mb_substr('Asked for a quote: ' . $r['title'], 0, 190), 'Request for quotes in Suppliers AI: /suppliers_ai/request.php?id=' . (int)$r['id'], $userId]);
                } catch (Throwable $e) {
                    error_log('[suppliers_ai] crm activity: ' . $e->getMessage());
                }
            }
        } elseif ($how === 'declined') {
            if ($x['decision'] === 'awarded') throw new SaProblem('Undo the choice first — then mark ' . $x['name'] . ' as declined.', 409);
            $db->prepare("UPDATE sai_request_suppliers SET declined_at = NOW(), decision = NULL, updated_at = NOW() WHERE id = ?")->execute([$recipientId]);
            self::event($db, $companyId, (int)$r['id'], $recipientId, $userId, 'declined', []);
        } elseif ($how === 'undeclined') {
            $db->prepare("UPDATE sai_request_suppliers SET declined_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$recipientId]);
        } elseif ($how === 'unasked') {
            if ($x['channel'] === 'email') throw new SaProblem('The email was sent — it cannot be unsent.', 409);
            $db->prepare("UPDATE sai_request_suppliers SET sent_at = NULL, channel = NULL, sent_by = NULL, updated_at = NOW() WHERE id = ?")->execute([$recipientId]);
        } else {
            throw new SaProblem('Unknown change.', 422);
        }
        $db->prepare("UPDATE sai_requests SET updated_at = NOW() WHERE id = ?")->execute([(int)$r['id']]);
        return self::get($db, $companyId, $userId, $role, (int)$r['id']);
    }

    /** Write down a quote. $in: amount, vat (incl|excl|none), lead_days, valid_until, notes; amount '' clears it. */
    public static function quote(PDO $db, int $companyId, int $userId, string $role, int $recipientId, array $in): array
    {
        $x = self::recipient($db, $companyId, $recipientId);
        $r = self::manageable($db, $companyId, $userId, $role, (int)$x['request_id']);
        if ($r['status'] === 'cancelled') throw new SaProblem('Open the request again first.', 409);
        $amount = SaKit::amount($in['amount'] ?? null, 'The quote');
        if ($amount === null) {
            if ($x['decision'] === 'awarded') throw new SaProblem('Undo the award before clearing the winning quote.', 409);
            $db->prepare("UPDATE sai_request_suppliers SET quote_cents = NULL, quote_vat = NULL, lead_days = NULL, valid_until = NULL, quote_notes = NULL, quoted_at = NULL, quoted_by = NULL, updated_at = NOW() WHERE id = ?")->execute([$recipientId]);
            self::event($db, $companyId, (int)$r['id'], $recipientId, $userId, 'quote_cleared', []);
            return self::get($db, $companyId, $userId, $role, (int)$r['id']);
        }
        if ($amount <= 0) throw new SaProblem('A quote is more than R 0.', 422);
        $vat = isset(self::VAT[$in['vat'] ?? '']) ? (string)$in['vat'] : 'incl';
        $lead = trim((string)($in['lead_days'] ?? ''));
        if ($lead !== '' && (!ctype_digit($lead) || (int)$lead > 730)) throw new SaProblem('The lead time is a number of days.', 422);
        $valid = trim((string)($in['valid_until'] ?? ''));
        $validYmd = $valid === '' ? null : (SaKit::ymd($valid) ?? throw new SaProblem('“Valid until” is not a date.', 422));
        $notes = SaKit::text($in['notes'] ?? '', 500) ?: null;
        $db->prepare("UPDATE sai_request_suppliers SET quote_cents = ?, quote_vat = ?, lead_days = ?, valid_until = ?, quote_notes = ?, quoted_at = NOW(), quoted_by = ?, declined_at = NULL,
                             replied_at = COALESCE(replied_at, NOW()), sent_at = COALESCE(sent_at, NOW()), channel = COALESCE(channel, 'other'), updated_at = NOW() WHERE id = ?")
           ->execute([$amount, $vat, $lead === '' ? null : (int)$lead, $validYmd, $notes, $userId, $recipientId]);
        if ($r['status'] === 'draft') $db->prepare("UPDATE sai_requests SET status = 'open', updated_at = NOW() WHERE id = ?")->execute([(int)$r['id']]);
        $db->prepare("UPDATE sai_requests SET updated_at = NOW() WHERE id = ?")->execute([(int)$r['id']]);
        self::event($db, $companyId, (int)$r['id'], $recipientId, $userId, 'quoted', ['amount' => $amount, 'vat' => $vat, 'lead_days' => $lead === '' ? null : (int)$lead]);
        return self::get($db, $companyId, $userId, $role, (int)$r['id']);
    }

    /** Choose the supplier. The others are "not chosen"; a prospect becomes an active supplier. */
    public static function award(PDO $db, int $companyId, int $userId, string $role, int $recipientId): array
    {
        $x = self::recipient($db, $companyId, $recipientId);
        $db->beginTransaction();
        try {
            $r = self::manageable($db, $companyId, $userId, $role, (int)$x['request_id'], true);
            if ($r['status'] === 'cancelled') throw new SaProblem('Open the request again first.', 409);
            if ($po = self::livePo($db, $companyId, $r)) throw new SaProblem('Purchase order ' . $po['po_number'] . ' was made for this request already.', 409);
            if ($x['declined_at']) throw new SaProblem($x['name'] . ' declined this one.', 409);
            $state = CrmCompliance::states($db, $companyId, [(int)$x['account_id'] => 'supplier'])[(int)$x['account_id']] ?? null;
            if (!empty($state['blocking'])) throw new SaProblem($x['name'] . '’s documents are ' . $state['status'] . ' — your company’s policy blocks them until they are renewed in the CRM.', 409);
            $db->prepare("UPDATE sai_request_suppliers SET decision = CASE WHEN id = ? THEN 'awarded' WHEN declined_at IS NULL THEN 'not_chosen' ELSE NULL END, decided_at = NOW(), updated_at = NOW() WHERE request_id = ?")
               ->execute([$recipientId, (int)$r['id']]);
            $db->prepare("UPDATE sai_requests SET status = 'awarded', awarded_id = ?, closed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$recipientId, (int)$r['id']]);
            $db->prepare("UPDATE crm_accounts SET status = 'active', updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'prospect'")->execute([(int)$x['account_id'], $companyId]);
            self::event($db, $companyId, (int)$r['id'], $recipientId, $userId, 'awarded', ['amount' => $x['quote_cents'] !== null ? (int)$x['quote_cents'] : null]);
            try {
                $db->prepare("INSERT INTO crm_interactions (company_id, account_id, type, subject, body, via, created_by, created_at) VALUES (?, ?, 'note', ?, ?, 'manual', ?, NOW())")
                   ->execute([$companyId, (int)$x['account_id'], mb_substr('Chosen for “' . $r['title'] . '”', 0, 190),
                              ($x['quote_cents'] !== null ? 'Quote ' . SaKit::money((int)$x['quote_cents']) . ' (' . (self::VAT[$x['quote_vat']] ?? '') . '). ' : '') . 'Suppliers AI: /suppliers_ai/request.php?id=' . (int)$r['id'], $userId]);
            } catch (Throwable $e) {
                error_log('[suppliers_ai] crm note: ' . $e->getMessage());
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        SaKit::audit($db, $companyId, $userId, 'sai_request_award', 'sai_request', (int)$r['id'], ['supplier' => $x['name'], 'account_id' => (int)$x['account_id'], 'quote_cents' => $x['quote_cents']]);
        if ((int)$r['created_by'] !== $userId) SaKit::notify($db, $companyId, (int)$r['created_by'], $userId, SaKit::personName($db, $userId) . ' chose ' . $x['name'], 'For “' . $r['title'] . '”.', '/suppliers_ai/request.php?id=' . (int)$r['id']);
        return self::get($db, $companyId, $userId, $role, (int)$r['id']);
    }

    /**
     * Undo the choice. A purchase order still in draft (nothing received on it) is cancelled with it;
     * one that was approved or received on stays, and so does the choice.
     */
    public static function unaward(PDO $db, int $companyId, int $userId, string $role, int $id): array
    {
        $cancelled = null;
        $db->beginTransaction();
        try {
            $r = self::manageable($db, $companyId, $userId, $role, $id, true);
            if ($r['status'] !== 'awarded') {
                $db->commit();
                return self::get($db, $companyId, $userId, $role, $id);
            }
            if ($po = self::livePo($db, $companyId, $r, true)) {
                $st = $db->prepare("SELECT COUNT(*) FROM goods_received_notes WHERE po_id = ? AND status <> 'cancelled'");
                try { $st->execute([(int)$po['id']]); $received = (int)$st->fetchColumn(); } catch (Throwable $e) { $received = 0; }
                if ($po['status'] !== 'draft' || $received > 0) {
                    throw new SaProblem('Purchase order ' . $po['po_number'] . ' is ' . ($received > 0 ? 'being received' : $po['status']) . ' in Procurement, so the choice stays.', 409);
                }
                $db->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE id = ? AND company_id = ?")->execute([(int)$po['id'], $companyId]);
                $cancelled = $po;
            }
            $db->prepare("UPDATE sai_request_suppliers SET decision = NULL, decided_at = NULL, updated_at = NOW() WHERE request_id = ?")->execute([$id]);
            $db->prepare("UPDATE sai_requests SET status = 'open', awarded_id = NULL, po_id = NULL, closed_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
            self::event($db, $companyId, $id, null, $userId, 'unawarded', $cancelled ? ['po_cancelled' => (string)$cancelled['po_number']] : []);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if ($cancelled) SaKit::audit($db, $companyId, $userId, 'sai_request_po_cancel', 'purchase_order', (int)$cancelled['id'], ['request_id' => $id, 'po_number' => $cancelled['po_number']]);
        return self::get($db, $companyId, $userId, $role, $id);
    }

    /** close | cancel | reopen | delete | restore */
    public static function setState(PDO $db, int $companyId, int $userId, string $role, int $id, string $to): array
    {
        $r = self::row($db, $companyId, $id, true);
        if (!self::canManage($r, $userId, $role)) throw new SaForbidden('Only ' . (SaKit::personName($db, (int)$r['created_by']) ?: 'the person who made it') . ', an admin or a bookkeeper can change this request.');
        if ($to === 'restore') {
            $db->prepare("UPDATE sai_requests SET deleted_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
            self::event($db, $companyId, $id, null, $userId, 'restored', []);
            return self::get($db, $companyId, $userId, $role, $id);
        }
        if ($r['deleted_at'] !== null) throw new SaProblem('Bring the request back first.', 409);
        if ($to === 'delete') {
            $db->prepare("UPDATE sai_requests SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$id]);
            self::event($db, $companyId, $id, null, $userId, 'deleted', []);
            SaKit::audit($db, $companyId, $userId, 'sai_request_delete', 'sai_request', $id, ['title' => $r['title']]);
            return ['deleted' => true, 'id' => $id];
        }
        if ($to === 'close' || $to === 'cancel') {
            if ($r['status'] === 'awarded') throw new SaProblem('This request was awarded — undo the award first.', 409);
            $db->prepare("UPDATE sai_requests SET status = ?, closed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$to === 'close' ? 'closed' : 'cancelled', $id]);
            self::event($db, $companyId, $id, null, $userId, $to === 'close' ? 'closed' : 'cancelled', []);
        } elseif ($to === 'reopen') {
            if ($r['status'] === 'awarded') return self::unaward($db, $companyId, $userId, $role, $id);
            $st = $db->prepare("SELECT COUNT(*) FROM sai_request_suppliers WHERE request_id = ? AND sent_at IS NOT NULL");
            $st->execute([$id]);
            $db->prepare("UPDATE sai_requests SET status = ?, closed_at = NULL, updated_at = NOW() WHERE id = ?")->execute([(int)$st->fetchColumn() > 0 ? 'open' : 'draft', $id]);
            self::event($db, $companyId, $id, null, $userId, 'reopened', []);
        } else {
            throw new SaProblem('Unknown change.', 422);
        }
        return self::get($db, $companyId, $userId, $role, $id);
    }

    // ------------------------------------------------------------- replies

    /**
     * Who replied: an incoming email in the request's conversation, or a new
     * email from the supplier's address after they were asked (60 days).
     * @return array the recipients that replied just now [{id, name, request_id, email_id}]
     */
    public static function refresh(PDO $db, int $companyId, int $requestId): array
    {
        // An email that waited in the Outbox (the server was down) and went later still counts as asking
        try {
            $st = $db->prepare("SELECT rs.id, rs.account_id FROM sai_request_suppliers rs WHERE rs.company_id = ? AND rs.request_id = ? AND rs.sent_at IS NULL");
            $st->execute([$companyId, $requestId]);
            $later = $db->prepare("SELECT e.email_id, e.thread_id, e.sent_at, e.to_recipients, a.user_id FROM email_links l1
                                     JOIN email_links l2 ON l2.email_id = l1.email_id AND l2.linked_type = 'supplier' AND l2.linked_id = ?
                                     JOIN emails e ON e.email_id = l1.email_id AND e.company_id = ? AND e.folder = 'Sent'
                                     JOIN email_accounts a ON a.account_id = e.account_id
                                    WHERE l1.linked_type = 'rfq' AND l1.linked_id = ? ORDER BY e.email_id LIMIT 1");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $later->execute([(int)$x['account_id'], $companyId, $requestId]);
                if (!($e = $later->fetch(PDO::FETCH_ASSOC))) continue;
                $db->prepare("UPDATE sai_request_suppliers SET sent_at = ?, channel = 'email', sent_by = ?, sent_to = ?, email_id = ?, thread_id = ?, updated_at = NOW() WHERE id = ? AND sent_at IS NULL")
                   ->execute([$e['sent_at'] ?: date('Y-m-d H:i:s'), (int)$e['user_id'], mb_substr(implode(', ', array_column(MimeParser::parseAddressList((string)$e['to_recipients']), 'email')), 0, 500), (int)$e['email_id'], $e['thread_id'] ? (int)$e['thread_id'] : null, (int)$x['id']]);
                $db->prepare("UPDATE sai_requests SET status = 'open', updated_at = NOW() WHERE id = ? AND status = 'draft'")->execute([$requestId]);
                self::event($db, $companyId, $requestId, (int)$x['id'], (int)$e['user_id'], 'asked', ['channel' => 'email', 'from_outbox' => true]);
            }
        } catch (Throwable $e) {
            error_log('[suppliers_ai] outbox check: ' . $e->getMessage());
        }
        $st = $db->prepare("SELECT rs.id, rs.name, rs.thread_id, rs.sent_at, rs.sent_to, rs.account_id FROM sai_request_suppliers rs
                             WHERE rs.company_id = ? AND rs.request_id = ? AND rs.sent_at IS NOT NULL AND rs.replied_at IS NULL AND rs.declined_at IS NULL
                               AND rs.sent_at >= NOW() - INTERVAL 60 DAY");
        $st->execute([$companyId, $requestId]);
        $found = [];
        $inThread = $db->prepare("SELECT email_id, COALESCE(sent_at, created_at) at FROM emails WHERE company_id = ? AND thread_id = ? AND direction = 'incoming' AND COALESCE(sent_at, created_at) >= ? ORDER BY at LIMIT 1");
        $set = $db->prepare("UPDATE sai_request_suppliers SET replied_at = ?, reply_email_id = ?, updated_at = NOW() WHERE id = ? AND replied_at IS NULL");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $hit = null;
            if ($x['thread_id']) {
                $inThread->execute([$companyId, (int)$x['thread_id'], $x['sent_at']]);
                $hit = $inThread->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$hit) {
                // A new email from the supplier's address — but not one that answers another request (its conversation, its
                // link) or was already taken as the reply on another request: one email is one reply, on one request
                $addrs = array_values(array_filter(array_map(fn($a) => strtolower(trim($a)), explode(',', (string)$x['sent_to'])), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
                if (!$addrs) $addrs = self::emailsFor($db, $companyId, (int)$x['account_id']);
                if ($addrs) {
                    $q = $db->prepare("SELECT e.email_id, COALESCE(e.sent_at, e.created_at) at FROM emails e
                                        WHERE e.company_id = ? AND e.direction = 'incoming' AND e.sender IN (" . implode(',', array_fill(0, count($addrs), '?')) . ")
                                          AND COALESCE(e.sent_at, e.created_at) >= ?
                                          AND (e.thread_id IS NULL OR NOT EXISTS (SELECT 1 FROM sai_request_suppliers o WHERE o.company_id = e.company_id AND o.thread_id = e.thread_id AND o.id <> ?))
                                          AND NOT EXISTS (SELECT 1 FROM sai_request_suppliers o WHERE o.company_id = e.company_id AND o.reply_email_id = e.email_id AND o.id <> ?)
                                          AND NOT EXISTS (SELECT 1 FROM email_links l WHERE l.email_id = e.email_id AND l.linked_type = 'rfq' AND l.linked_id <> ?)
                                     ORDER BY at LIMIT 1");
                    $q->execute(array_merge([$companyId], $addrs, [$x['sent_at'], (int)$x['id'], (int)$x['id'], $requestId]));
                    $hit = $q->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
            if ($hit) {
                $set->execute([$hit['at'], (int)$hit['email_id'], (int)$x['id']]);
                if ($set->rowCount() === 1) {
                    self::event($db, $companyId, $requestId, (int)$x['id'], null, 'replied', ['email_id' => (int)$hit['email_id']]);
                    $found[] = ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'request_id' => $requestId, 'email_id' => (int)$hit['email_id']];
                }
            }
        }
        return $found;
    }

    /** Replies just found while the owner looks at the request: the bell need not tell them again (anyone else's look leaves it to the tick). */
    public static function seen(PDO $db, array $r, int $userId, array $found): void
    {
        if (!$found || (int)$r['created_by'] !== $userId) return;
        $db->prepare("UPDATE sai_request_suppliers SET reply_notified_at = NOW() WHERE reply_notified_at IS NULL AND id IN (" . implode(',', array_map(fn($f) => (int)$f['id'], $found)) . ")")->execute();
    }

    /**
     * The background tick (notifications/api/tick.php): replies on open requests (and drafts whose email went
     * later from the Outbox), and quotes that are due — each told to the person who made the request once.
     * Requests are looked at in turn, the longest unchecked first, so every one is reached however many there are.
     */
    public static function tick(PDO $db, float $deadline): array
    {
        $out = ['replies' => 0, 'due' => 0];
        $st = $db->query("SELECT r.id, r.company_id FROM sai_requests r
                           WHERE r.status IN ('draft', 'open') AND r.deleted_at IS NULL
                             AND (EXISTS (SELECT 1 FROM sai_request_suppliers rs WHERE rs.request_id = r.id AND rs.sent_at IS NOT NULL AND rs.replied_at IS NULL
                                                                                    AND rs.declined_at IS NULL AND rs.sent_at >= NOW() - INTERVAL 60 DAY)
                                  OR EXISTS (SELECT 1 FROM sai_request_suppliers rs WHERE rs.request_id = r.id AND rs.sent_at IS NULL)
                                     AND r.created_at >= NOW() - INTERVAL 60 DAY)
                        ORDER BY r.replies_checked_at IS NOT NULL, r.replies_checked_at, r.id LIMIT 200");
        $checked = $db->prepare("UPDATE sai_requests SET replies_checked_at = NOW() WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (microtime(true) > $deadline) break;
            self::refresh($db, (int)$r['company_id'], (int)$r['id']);
            $checked->execute([(int)$r['id']]);
        }
        // Every reply noticed — here or by whoever opened the request — that its owner has not seen yet
        $st = $db->query("SELECT rs.id, rs.name, r.id AS request_id, r.company_id, r.created_by, r.title FROM sai_request_suppliers rs JOIN sai_requests r ON r.id = rs.request_id
                           WHERE rs.reply_email_id IS NOT NULL AND rs.reply_notified_at IS NULL AND rs.replied_at >= NOW() - INTERVAL 7 DAY
                             AND r.deleted_at IS NULL AND r.status IN ('draft', 'open') ORDER BY rs.id LIMIT 200");
        $mark = $db->prepare("UPDATE sai_request_suppliers SET reply_notified_at = NOW() WHERE id = ? AND reply_notified_at IS NULL");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $mark->execute([(int)$f['id']]);
            if ($mark->rowCount() !== 1) continue;
            $out['replies']++;
            SaKit::notify($db, (int)$f['company_id'], (int)$f['created_by'], 0, $f['name'] . ' replied', 'To your request for quotes “' . $f['title'] . '”.', '/suppliers_ai/request.php?id=' . (int)$f['request_id']);
        }
        $st = $db->query("SELECT r.id, r.company_id, r.created_by, r.title, COUNT(rs.id) n, SUM(rs.replied_at IS NOT NULL OR rs.quote_cents IS NOT NULL) replied, SUM(rs.quote_cents IS NOT NULL AND rs.declined_at IS NULL) quoted
                            FROM sai_requests r LEFT JOIN sai_request_suppliers rs ON rs.request_id = r.id AND rs.sent_at IS NOT NULL
                           WHERE r.status = 'open' AND r.deleted_at IS NULL AND r.due_notified_at IS NULL AND r.quote_by IS NOT NULL AND r.quote_by <= CURDATE()
                        GROUP BY r.id LIMIT 200");
        $done = $db->prepare("UPDATE sai_requests SET due_notified_at = NOW() WHERE id = ? AND due_notified_at IS NULL");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (microtime(true) > $deadline) break;
            $done->execute([(int)$r['id']]);
            if ($done->rowCount() !== 1) continue;
            $out['due']++;
            $n = (int)$r['n'];
            SaKit::notify($db, (int)$r['company_id'], (int)$r['created_by'], 0, 'Quotes for “' . mb_strimwidth((string)$r['title'], 0, 60, '…') . '” are due',
                          (int)$r['quoted'] . ' of ' . $n . ' ' . ($n === 1 ? 'supplier has' : 'suppliers have') . ' quoted' . ((int)$r['replied'] > (int)$r['quoted'] ? ', ' . ((int)$r['replied'] - (int)$r['quoted']) . ' more replied' : '') . '.',
                          '/suppliers_ai/request.php?id=' . (int)$r['id']);
        }
        return $out;
    }

    /** The side bar and the Home tile. */
    public static function counts(PDO $db, int $companyId, int $userId): array
    {
        try {
            $st = $db->prepare("SELECT SUM(r.status IN ('draft', 'open')) active, SUM(r.status = 'draft') drafts, SUM(r.status = 'open') open,
                                       SUM(r.status = 'open' AND EXISTS (SELECT 1 FROM sai_request_suppliers rs WHERE rs.request_id = r.id AND rs.quote_cents IS NOT NULL AND rs.declined_at IS NULL)) to_compare,
                                       SUM(r.status = 'open' AND r.quote_by < CURDATE()) late,
                                       SUM(r.status IN ('draft', 'open') AND r.created_by = ?) mine
                                  FROM sai_requests r WHERE r.company_id = ? AND r.deleted_at IS NULL");
            $st->execute([$userId, $companyId]);
            $c = array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            $c = [];
        }
        return $c + ['active' => 0, 'drafts' => 0, 'open' => 0, 'to_compare' => 0, 'late' => 0, 'mine' => 0];
    }

    // ------------------------------------------------------------- helpers

    /** The supplier's email addresses: the main contact's, then the account's. */
    public static function emailsFor(PDO $db, int $companyId, int $accountId): array
    {
        $st = $db->prepare("SELECT LOWER(TRIM(c.email)) FROM crm_contacts c WHERE c.company_id = ? AND c.account_id = ? AND c.email IS NOT NULL AND c.email <> '' ORDER BY c.is_primary DESC, c.id LIMIT 1");
        $st->execute([$companyId, $accountId]);
        $out = array_filter([(string)$st->fetchColumn()]);
        $st = $db->prepare("SELECT LOWER(TRIM(email)) FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$accountId, $companyId]);
        $out[] = (string)$st->fetchColumn();
        return array_values(array_unique(array_filter($out, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
    }

    /** The words that fill the placeholders (never the budget). */
    public static function vars(PDO $db, int $companyId, int $userId, array $r, ?array $x): array
    {
        $first = '';
        if ($x) {
            $st = $db->prepare("SELECT first_name FROM crm_contacts WHERE company_id = ? AND account_id = ? ORDER BY is_primary DESC, (email IS NULL OR email = ''), id LIMIT 1");
            $st->execute([$companyId, (int)$x['account_id']]);
            $first = trim((string)$st->fetchColumn());
        }
        $reqs = json_decode((string)($r['requirements'] ?? ''), true) ?: [];
        $details = trim((string)($r['details'] ?? ''));
        if ($details === '') $details = (string)$r['title'];
        $lines = ['- ' . str_replace("\n", "\n  ", $details)];
        if (trim((string)$r['quantity']) !== '' && stripos($details, (string)$r['quantity']) === false) $lines[] = '- Quantity: ' . $r['quantity'];
        if ($reqs) $lines[] = '- Please include: ' . implode(', ', $reqs);
        return [
            'first_name' => $first !== '' ? $first : 'there', 'supplier' => $x ? (string)$x['name'] : '{supplier}',
            'what' => (string)$r['title'], 'details' => implode("\n", $lines), 'quantity' => (string)($r['quantity'] ?: '—'),
            'where' => trim((string)$r['location']) !== '' ? (string)$r['location'] : 'to be confirmed',
            'needed_by' => $r['needed_by'] ? date('l j F Y', strtotime($r['needed_by'])) : 'as soon as it can be arranged',
            'quote_by' => $r['quote_by'] ? date('l j F Y', strtotime($r['quote_by'])) : 'as soon as you can',
            'company' => (string)SaKit::company($db, $companyId)['name'], 'sender' => SaKit::personName($db, $userId),
        ];
    }

    public static function fill(string $s, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/i', fn($m) => array_key_exists(strtolower($m[1]), $vars) ? (string)$vars[strtolower($m[1])] : $m[0], $s) ?? $s;
    }

    /** The same request as a WhatsApp message (placeholders kept for each supplier). */
    private static function whatsapp(array $r, array $vars): string
    {
        $t = "Hi {first_name}, this is " . $vars['sender'] . ' from ' . $vars['company'] . ". Could you please send us a quotation for:\n" . $vars['details'];
        if (trim((string)$r['location']) !== '') $t .= "\nWhere: " . $r['location'];
        if ($r['needed_by']) $t .= "\nNeeded by: " . date('j F', strtotime($r['needed_by']));
        if ($r['quote_by']) $t .= "\nWe need quotes by " . date('l j F', strtotime($r['quote_by'])) . '.';
        return $t . "\nThank you!";
    }

    public static function event(PDO $db, int $companyId, int $requestId, ?int $recipientId, ?int $userId, string $action, array $data): void
    {
        try {
            $db->prepare("INSERT INTO sai_events (company_id, request_id, recipient_id, user_id, action, payload_json, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $requestId, $recipientId, $userId ?: null, $action, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null]);
        } catch (Throwable $e) {
            error_log('[suppliers_ai] event ' . $action . ': ' . $e->getMessage());
        }
    }
}
