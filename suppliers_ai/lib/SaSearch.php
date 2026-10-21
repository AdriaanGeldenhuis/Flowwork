<?php
// /suppliers_ai/lib/SaSearch.php — find suppliers for a request:
//   1. your suppliers in the CRM whose name, industry, tags or notes match,
//      and those you bought it from before (Finance's bills, Procurement's
//      orders) or who quoted on similar requests here;
//   2. businesses near the place on Google (with the company's Google key);
//   3. ideas from AI — kinds of business and chains to look at, names only.
// Each result is ranked by SaScore with its reasons; documents come from the
// CRM (CrmCompliance), the company's never-suggest and always-ask lists apply.
// A search is kept (ai_queries, ai_candidates) so it can be opened again,
// shortlisted from and turned into a request for quotes.

require_once dirname(__DIR__, 2) . '/crm/lib/CrmAccounts.php';

final class SaSearch
{
    public const MAX_ROWS = 40;
    private const GENERIC = ['supplier', 'contractor', 'service', 'installer', 'company', 'shop', 'store', 'business', 'specialist', 'expert', 'professional', 'local', 'someone'];
    private const ACTIONS = ['call', 'whatsapp', 'email', 'website', 'directions', 'view', 'shortlist'];

    /** The sources a search may use (all by default). */
    public static function sources($in): array
    {
        $in = is_array($in) ? $in : [];
        $on = fn($k) => !array_key_exists($k, $in) || !empty($in[$k]);
        return ['crm' => $on('crm'), 'google' => $on('google'), 'ai' => $on('ai')];
    }

    /**
     * Run a search. $opts: location, radius, sources {crm, google, ai}, compliant_only.
     */
    public static function run(PDO $db, int $companyId, int $userId, string $role, string $text, array $opts = []): array
    {
        if (!SaKit::canSearch($role)) throw new SaForbidden('Viewers can open searches and requests, but not run new searches.');
        $text = SaKit::text($text, 500);
        if (mb_strlen($text) < 2) throw new SaProblem('Say what you need — a trade or a product, and where.', 422);
        $t0 = microtime(true);
        CrmSchema::ensure($db);
        $s = SaKit::settings($db, $companyId);
        $company = SaKit::company($db, $companyId);
        $today = date('Y-m-d');
        $read = SaParse::read($text, $s['rules'], $today);
        $sources = self::sources($opts['sources'] ?? null);
        $radius = isset($opts['radius']) && is_numeric($opts['radius']) ? max(1.0, min(500.0, (float)$opts['radius'])) : $s['radius_km'];
        $compliantOnly = array_key_exists('compliant_only', $opts) ? !empty($opts['compliant_only']) : $s['compliant_only'];
        $notes = [];
        $caps = SaKit::caps($role);

        // Paid look-ups (Google, OpenAI): the company's ceilings and a per-person brake
        $wantGoogle = $sources['google'] && $s['google_key'] !== '';
        $wantAi = $sources['ai'] && SaKit::aiReady($s);
        $quota = SaKit::quota($db, $companyId, $s);
        if (($wantGoogle || $wantAi) && $quota['left'] <= 0) {
            $notes[] = ['tone' => 'warn', 'text' => 'The company’s ' . ($quota['hour'] >= $quota['hour_cap'] ? $quota['hour_cap'] . ' searches an hour' : $quota['day_cap'] . ' searches a day')
                . ' on Google and AI are used up, so only your own suppliers were searched. ' . ($caps['configure'] ? 'The limits are under Settings.' : 'An admin can raise the limits.')];
            $wantGoogle = $wantAi = false;
        }
        if (($wantGoogle || $wantAi) && ($wait = SaKit::rateLimit('ext:' . $companyId . ':' . $userId, 15, 300)) > 0) {
            $notes[] = ['tone' => 'warn', 'text' => 'That was a lot of searches in a few minutes — Google and AI wait ' . ceil($wait / 60) . ' min. Your own suppliers were searched.'];
            $wantGoogle = $wantAi = false;
        }

        $ai = null;
        if ($wantAi) {
            try {
                $ai = SaAi::read($s, $text, $today, (string)$company['town']);
            } catch (SaProblem $e) {
                $notes[] = ['tone' => 'warn', 'text' => 'AI: ' . $e->getMessage()];
            }
        }
        $read = self::merge($read, $ai, $s['rules']);
        $place = SaKit::text($opts['location'] ?? '', 160);
        if ($place === '') $place = (string)($read['location'] ?? '');
        $placeFromCompany = false;
        if ($place === '') { $place = (string)$company['town']; $placeFromCompany = true; }

        // 1. Your suppliers
        $cands = [];
        $terms = self::terms($read, $s['rules']);
        if ($sources['crm'] && $terms['all']) $cands = self::fromCrm($db, $companyId, $terms, $read);
        if ($sources['crm'] && !$terms['all']) $notes[] = ['tone' => 'info', 'text' => 'Say what you need (a trade or a product) to search your suppliers.'];

        // 2. Google
        $center = null;
        $googleRan = false;
        if ($wantGoogle) {
            $query = $ai['search_terms'][0] ?? '';
            if ($query === '' || (!empty($read['trade']) && count($read['keywords']) > 1)) $query = $read['query'] !== '' ? $read['query'] : $query;
            if ($query !== '') {
                try {
                    $g = SaPlaces::search($s['google_key'], $query, $place, $radius);
                    $googleRan = true;
                    $center = $g['center'];
                    self::mergeGoogle($cands, $g['places'], $terms, $read);
                } catch (SaProblem $e) {
                    $notes[] = ['tone' => 'warn', 'text' => 'Google: ' . $e->getMessage()];
                }
            }
        } elseif ($s['google_key'] === '') {
            $notes[] = ['tone' => 'info', 'key' => 'google', 'text' => 'Businesses near ' . ($place ?: 'the site') . ' with their phone numbers and ratings appear here once ' . ($caps['keys'] ? 'you add' : 'an admin adds') . ' a Google key under Settings.'];
        }

        // Documents (from the CRM), the never-suggest and always-ask lists, the score
        $ids = [];
        foreach ($cands as $c) if ($c['account_id']) $ids[(int)$c['account_id']] = 'supplier';
        $states = $ids ? CrmCompliance::states($db, $companyId, $ids) : [];
        $rules = $s['rules'];
        $hidden = 0;
        $ctx = ['radius' => $radius, 'place' => $center['label'] ?? $place, 'books' => $caps['books']];
        foreach ($cands as $k => &$c) {
            if (self::denied($c, $rules['deny_list'])) { unset($cands[$k]); $hidden++; continue; }
            $c['allowed'] = self::allowed($c, $rules['allow_list']);
            $c['compliance'] = $c['account_id'] ? ($states[(int)$c['account_id']] ?? null) : null;
            if ($compliantOnly && $c['source'] === 'crm' && !in_array($c['compliance']['status'] ?? 'none', ['valid', 'expiring'], true)) { unset($cands[$k]); continue; }
            if ($compliantOnly && $c['source'] === 'google') { unset($cands[$k]); continue; }
            [$c['score'], $c['reasons']] = SaScore::score($c, $ctx);
        }
        unset($c);
        if ($hidden) $notes[] = ['tone' => 'info', 'text' => $hidden . ' ' . ($hidden === 1 ? 'result was' : 'results were') . ' left out by your never-suggest list.'];
        if ($compliantOnly) $notes[] = ['tone' => 'info', 'text' => 'Only suppliers whose documents are in order are shown (Google listings have no documents yet).'];
        uasort($cands, fn($a, $b) => $b['score'] <=> $a['score'] ?: (int)!empty($b['preferred']) <=> (int)!empty($a['preferred']) ?: strcmp($a['name'], $b['name']));
        $cands = array_slice($cands, 0, self::MAX_ROWS, true);

        // 3. Ideas (names only — never contact details), without the ones already found
        $ideas = [];
        foreach ($ai['ideas'] ?? [] as $i) {
            $n = CrmAccounts::normName($i['name']);
            $dup = false;
            foreach ($cands as $c) if (CrmAccounts::nameSimilarity($n, CrmAccounts::normName($c['name'])) >= 0.8) { $dup = true; break; }
            if ($dup) continue;
            $ideas[] = $i + ['maps' => self::mapsSearch($i['name'] . ' ' . $place)];
        }

        $external = $googleRan || $ai !== null;
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $filters = ['location' => $place, 'radius' => $radius, 'sources' => $sources, 'compliant_only' => $compliantOnly];
        $meta = ['ideas' => $ideas, 'notes' => $notes, 'place' => $place, 'place_from_company' => $placeFromCompany, 'center' => $center, 'google' => $googleRan, 'ai_summary' => $ai['summary'] ?? ''];
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO ai_queries (company_id, user_id, q_text, location, parsed_json, filters_json, radius_km, took_ms, results, external, ai_used, sources_json, lat, lng, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$companyId, $userId, $text, $place, json_encode($read, JSON_UNESCAPED_UNICODE), json_encode($filters), $radius, $ms, count($cands), $external ? 1 : 0, $ai !== null ? 1 : 0,
                          json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $center['lat'] ?? null, $center['lng'] ?? null]);
            $queryId = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO ai_candidates (company_id, query_id, `rank`, account_id, source, source_ref, name, phone, email, website, address, lat, lng, categories_json,
                                        distance_km, rating, rating_count, maps_url, compliance_state, performance_json, score_final, explanation, reasons_json, contact_name, blocked, created_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $rank = 0;
            foreach ($cands as &$c) {
                $comp = $c['compliance']['status'] ?? null;
                $ins->execute([$companyId, $queryId, ++$rank, $c['account_id'] ?: null, $c['source'], $c['google']['place_id'] ?? null, mb_substr($c['name'], 0, 255),
                               $c['phone'] ?: null, $c['email'] ?: null, $c['website'] ?: null, mb_substr((string)$c['address'], 0, 255) ?: null, $c['lat'] ?? null, $c['lng'] ?? null,
                               json_encode($read['trades']), $c['distance_km'], $c['google']['rating'] ?? null, $c['google']['rating_count'] ?? null, $c['google']['maps_url'] ?? null,
                               in_array($comp, ['valid', 'expiring', 'expired', 'missing'], true) ? $comp : null, json_encode($c['perf'] ?? []), $c['score'],
                               mb_substr((string)($c['reasons'][0]['t'] ?? ''), 0, 1000), json_encode($c['reasons'], JSON_UNESCAPED_UNICODE),
                               mb_substr((string)($c['contact']['name'] ?? ''), 0, 160) ?: null, !empty($c['compliance']['blocking']) ? 1 : 0]);
                $c['id'] = (int)$db->lastInsertId();
            }
            unset($c);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return self::shape($db, $companyId, $role, $queryId, $text, $read, $cands, $meta, $s, $filters, $ms, $userId, date('Y-m-d H:i:s'));
    }

    /** A past search, as it was found (documents and CRM links as they are now). */
    public static function get(PDO $db, int $companyId, int $userId, string $role, int $queryId): array
    {
        $st = $db->prepare("SELECT * FROM ai_queries WHERE id = ? AND company_id = ?");
        $st->execute([$queryId, $companyId]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) throw new SaProblem('That search is not here any more.', 404);
        CrmSchema::ensure($db);
        $s = SaKit::settings($db, $companyId);
        $read = json_decode((string)$q['parsed_json'], true);
        $read = is_array($read) && isset($read['keywords']) ? $read : SaParse::read((string)$q['q_text'], $s['rules'], substr((string)$q['created_at'], 0, 10));
        $meta = json_decode((string)($q['sources_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : ['ideas' => [], 'notes' => [], 'place' => (string)($q['location'] ?? '')];
        $filters = json_decode((string)($q['filters_json'] ?? ''), true) ?: [];
        $st = $db->prepare("SELECT * FROM ai_candidates WHERE company_id = ? AND query_id = ? ORDER BY `rank`, id LIMIT 60");
        $st->execute([$companyId, $queryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $accounts = self::accounts($db, $companyId, array_values(array_filter(array_map(fn($r) => (int)$r['account_id'], $rows))));
        $states = $accounts ? CrmCompliance::states($db, $companyId, array_fill_keys(array_keys($accounts), 'supplier')) : [];
        $legacy = $q['sources_json'] === null;
        $cands = [];
        foreach ($rows as $r) {
            $acc = $r['account_id'] ? ($accounts[(int)$r['account_id']] ?? null) : null;
            $reasons = json_decode((string)($r['reasons_json'] ?? ''), true);
            if (!is_array($reasons)) {
                $reasons = $r['explanation'] ? [['i' => 'i-info', 't' => (string)$r['explanation'], 'tone' => '']] : [];
                if ($legacy && !$r['account_id']) $reasons[] = ['i' => 'i-alert', 't' => 'Found by the old AI search — its phone numbers and emails were often made up. Check before you call.', 'tone' => 'warn'];
            }
            $source = (string)($r['source'] ?: ($r['account_id'] ? 'crm' : 'legacy'));
            $cands[] = [
                'id' => (int)$r['id'], 'source' => $source, 'account_id' => $acc ? (int)$acc['id'] : null,
                'name' => $acc['name'] ?? (string)$r['name'], 'phone' => (string)($acc['phone'] ?? $r['phone'] ?? ''), 'email' => (string)($acc['email'] ?? $r['email'] ?? ''),
                'website' => (string)($acc['website'] ?? $r['website'] ?? ''), 'address' => (string)($acc['address'] ?? $r['address'] ?? ''), 'city' => (string)($acc['city'] ?? ''),
                'industry' => (string)($acc['industry'] ?? ''), 'tags' => $acc['tags'] ?? [], 'status' => (string)($acc['status'] ?? ''), 'preferred' => !empty($acc['preferred']),
                'contact' => $acc['contact'] ?? ['name' => (string)($r['contact_name'] ?? ''), 'email' => '', 'phone' => ''],
                'distance_km' => $r['distance_km'] !== null ? (float)$r['distance_km'] : null, 'lat' => $r['lat'] !== null ? (float)$r['lat'] : null, 'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
                'google' => $r['source_ref'] || $r['rating'] !== null || $r['maps_url'] ? ['place_id' => (string)$r['source_ref'], 'rating' => $r['rating'] !== null ? (float)$r['rating'] : null,
                            'rating_count' => $r['rating_count'] !== null ? (int)$r['rating_count'] : null, 'maps_url' => (string)$r['maps_url']] : null,
                'compliance' => $acc ? ($states[(int)$acc['id']] ?? null) : null,
                'score' => (float)$r['score_final'], 'reasons' => $reasons,
            ];
        }
        $ms = (int)$q['took_ms'];
        return self::shape($db, $companyId, $role, $queryId, (string)$q['q_text'], $read, $cands, $meta, $s, $filters, $ms, (int)$q['user_id'], (string)$q['created_at']) + ['legacy' => $legacy];
    }

    /** Past searches: the company's, newest first. */
    public static function history(PDO $db, int $companyId, int $userId, array $p): array
    {
        $where = ['q.company_id = ?'];
        $args = [$companyId];
        if (!empty($p['mine'])) { $where[] = 'q.user_id = ?'; $args[] = $userId; }
        $qtext = SaKit::text($p['q'] ?? '', 100);
        if ($qtext !== '') { $where[] = 'q.q_text LIKE ?'; $args[] = '%' . SaKit::likeEscape($qtext) . '%'; }
        $page = max(1, (int)($p['page'] ?? 1));
        $per = 25;
        $st = $db->prepare("SELECT COUNT(*) FROM ai_queries q WHERE " . implode(' AND ', $where));
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT q.id, q.user_id, q.q_text, q.location, q.took_ms, q.created_at, q.external, q.ai_used, q.sources_json IS NULL AS legacy,
                                   COALESCE(NULLIF(q.results, 0), (SELECT COUNT(*) FROM ai_candidates c WHERE c.query_id = q.id AND c.company_id = q.company_id)) AS results,
                                   (SELECT COUNT(*) FROM ai_actions a WHERE a.query_id = q.id AND a.company_id = q.company_id) AS actions,
                                   (SELECT COUNT(*) FROM sai_requests r WHERE r.query_id = q.id AND r.company_id = q.company_id AND r.deleted_at IS NULL) AS requests,
                                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS who
                              FROM ai_queries q LEFT JOIN users u ON u.id = q.user_id
                             WHERE " . implode(' AND ', $where) . " ORDER BY q.created_at DESC, q.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($args);
        $rows = array_map(fn($r) => [
            'id' => (int)$r['id'], 'text' => (string)$r['q_text'], 'location' => (string)($r['location'] ?? ''), 'ms' => (int)$r['took_ms'], 'at' => (string)$r['created_at'],
            'results' => (int)$r['results'], 'actions' => (int)$r['actions'], 'requests' => (int)$r['requests'], 'who' => (string)$r['who'], 'mine' => (int)$r['user_id'] === $userId,
            'external' => (bool)$r['external'], 'ai' => (bool)$r['ai_used'], 'legacy' => (bool)$r['legacy'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
    }

    /** The top bar: requests, suppliers and past searches. */
    public static function jump(PDO $db, int $companyId, string $q): array
    {
        $q = SaKit::text($q, 80);
        if (mb_strlen($q) < 2) return ['requests' => [], 'suppliers' => [], 'searches' => []];
        $like = '%' . SaKit::likeEscape($q) . '%';
        $st = $db->prepare("SELECT id, title, status FROM sai_requests WHERE company_id = ? AND deleted_at IS NULL AND (title LIKE ? OR details LIKE ?) ORDER BY created_at DESC LIMIT 6");
        $st->execute([$companyId, $like, $like]);
        $requests = array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'status' => (string)$r['status']], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT id, name, status FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL AND status <> 'banned' AND name LIKE ? ORDER BY preferred DESC, name LIMIT 6");
        $st->execute([$companyId, $like]);
        $suppliers = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'status' => (string)$r['status']], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT MAX(id) id, q_text FROM ai_queries WHERE company_id = ? AND q_text LIKE ? GROUP BY q_text ORDER BY MAX(id) DESC LIMIT 5");
        $st->execute([$companyId, $like]);
        $searches = array_map(fn($r) => ['id' => (int)$r['id'], 'text' => (string)$r['q_text']], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['requests' => $requests, 'suppliers' => $suppliers, 'searches' => $searches];
    }

    /** CRM suppliers by name, for adding to a request by hand. */
    public static function crmLookup(PDO $db, int $companyId, string $q): array
    {
        CrmSchema::ensure($db);
        $q = SaKit::text($q, 80);
        $args = [$companyId];
        $cond = '';
        if ($q !== '') { $cond = ' AND (a.name LIKE ? OR a.email LIKE ?)'; $args[] = '%' . SaKit::likeEscape($q) . '%'; $args[] = '%' . SaKit::likeEscape($q) . '%'; }
        $st = $db->prepare("SELECT a.id FROM crm_accounts a WHERE a.company_id = ? AND a.type = 'supplier' AND a.deleted_at IS NULL AND a.status IN ('active', 'prospect')$cond
                             ORDER BY a.preferred DESC, a.name LIMIT 20");
        $st->execute($args);
        $accounts = self::accounts($db, $companyId, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $states = $accounts ? CrmCompliance::states($db, $companyId, array_fill_keys(array_keys($accounts), 'supplier')) : [];
        $out = [];
        foreach ($accounts as $id => $a) {
            $out[] = ['account_id' => $id, 'name' => $a['name'], 'email' => $a['contact']['email'] ?: $a['email'], 'phone' => $a['contact']['phone'] ?: $a['phone'],
                      'preferred' => $a['preferred'], 'status' => $a['status'], 'compliance' => self::compliance($states[$id] ?? null)];
        }
        return $out;
    }

    /** Record a call, a WhatsApp, a visit to the website… on a result of a search of this company. */
    public static function log(PDO $db, int $companyId, int $userId, int $candidateId, string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) throw new SaProblem('Unknown action.', 422);
        $st = $db->prepare("SELECT query_id FROM ai_candidates WHERE id = ? AND company_id = ?");
        $st->execute([$candidateId, $companyId]);
        $qid = $st->fetchColumn();
        if ($qid === false) throw new SaProblem('That result is not here any more.', 404);
        $db->prepare("INSERT INTO ai_actions (company_id, query_id, candidate_id, action, details_json, user_id, created_at) VALUES (?, ?, ?, ?, NULL, ?, NOW())")
           ->execute([$companyId, (int)$qid, $candidateId, $action, $userId]);
    }

    /**
     * A Google result becomes a supplier in the CRM (a prospect until you buy from them).
     * $link: an existing supplier to link instead; $confirm: add even though it looks like one you have.
     * @return array{account_id?: int, duplicates?: array, url?: string}
     */
    public static function addToCrm(PDO $db, int $companyId, int $userId, string $role, int $candidateId, int $link = 0, bool $confirm = false): array
    {
        if (!SaKit::canWrite($role)) throw new SaForbidden('Viewers cannot add suppliers.');
        CrmSchema::ensure($db);
        $st = $db->prepare("SELECT c.*, q.parsed_json FROM ai_candidates c JOIN ai_queries q ON q.id = c.query_id AND q.company_id = c.company_id WHERE c.id = ? AND c.company_id = ?");
        $st->execute([$candidateId, $companyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new SaProblem('That result is not here any more.', 404);
        if ($c['account_id']) {
            $st = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $st->execute([(int)$c['account_id'], $companyId]);
            if ($st->fetchColumn()) return ['account_id' => (int)$c['account_id'], 'url' => '/crm/account_view.php?id=' . (int)$c['account_id'], 'existing' => true];
        }
        if ($link > 0) {
            $st = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier' AND deleted_at IS NULL");
            $st->execute([$link, $companyId]);
            if (!$st->fetchColumn()) throw new SaProblem('Choose a supplier from your CRM.', 422);
            $accountId = $link;
        } else {
            $read = json_decode((string)$c['parsed_json'], true) ?: [];
            $trade = !empty($read['trades'][0]) ? SaParse::trade((string)$read['trades'][0]) : null;
            $note = 'Found with Suppliers AI on ' . date('j F Y') . ($c['maps_url'] ? ' (Google: ' . $c['maps_url'] . ')' : '') . '.';
            if ($c['rating'] !== null) $note .= ' Rated ' . $c['rating'] . '★ by ' . (int)$c['rating_count'] . ' people on Google at the time.';
            $in = ['type' => 'supplier', 'name' => (string)$c['name'], 'phone' => (string)$c['phone'], 'email' => (string)$c['email'], 'website' => (string)$c['website'],
                   'status' => 'prospect', 'notes' => $note, 'tags' => $trade ? [$trade['label']] : [], 'confirm_duplicate' => $confirm ? 1 : 0];
            if ($c['address']) $in['address'] = ['type' => 'head_office', 'line1' => mb_substr((string)$c['address'], 0, 255)];
            try {
                $res = CrmAccounts::save($db, $companyId, $userId, $role, $in);
            } catch (CrmUserError $e) {
                throw new SaProblem('The CRM could not add it: ' . $e->getMessage(), 422);
            } catch (CrmForbidden $e) {
                throw new SaForbidden($e->getMessage());
            }
            if (!empty($res['duplicates'])) return ['duplicates' => $res['duplicates']];
            $accountId = (int)$res['id'];
        }
        // This result, and the same business in other searches, now point at the supplier
        $db->prepare("UPDATE ai_candidates SET account_id = ? WHERE company_id = ? AND (id = ? OR (source_ref IS NOT NULL AND source_ref <> '' AND source_ref = ?))")
           ->execute([$accountId, $companyId, $candidateId, (string)$c['source_ref']]);
        $db->prepare("INSERT INTO ai_actions (company_id, query_id, candidate_id, action, details_json, user_id, created_at) VALUES (?, ?, ?, 'add_to_crm', ?, ?, NOW())")
           ->execute([$companyId, (int)$c['query_id'], $candidateId, json_encode(['account_id' => $accountId, 'linked' => $link > 0]), $userId]);
        SaKit::audit($db, $companyId, $userId, 'sai_supplier_added', 'crm_account', $accountId, ['candidate_id' => $candidateId, 'linked' => $link > 0]);
        return ['account_id' => $accountId, 'url' => '/crm/account_view.php?id=' . $accountId, 'linked' => $link > 0];
    }

    // --------------------------------------------------------------- internals

    /** Take what AI read where the words alone found nothing. */
    public static function merge(array $read, ?array $ai, array $rules): array
    {
        $read['ai'] = $ai !== null;
        if ($ai === null) return $read;
        if (!$read['trades'] && $ai['category']) {
            $read['trades'] = [$ai['category']];
            $read['trade'] = SaParse::trade($ai['category'], $rules);
        } elseif ($ai['category'] && !in_array($ai['category'], $read['trades'], true)) {
            $read['trades'][] = $ai['category'];
        }
        foreach (['location', 'quantity', 'needed_by', 'budget'] as $k) if (empty($read[$k]) && !empty($ai[$k])) $read[$k] = $ai[$k];
        $have = array_map(fn($r) => mb_strtolower($r['label']), $read['requirements']);
        foreach ($ai['requirements'] as $label) {
            $l = mb_strtolower($label);
            $dup = false;
            foreach ($have as $h) if (str_contains($h, $l) || str_contains($l, $h) || similar_text($h, $l) >= min(mb_strlen($h), mb_strlen($l)) * 0.8) { $dup = true; break; }
            if (!$dup) { $read['requirements'][] = ['key' => 'ai', 'label' => $label]; $have[] = $l; }
        }
        $read['search_terms'] = $ai['search_terms'];
        $read['summary'] = $ai['summary'];
        return $read;
    }

    /** The words to look for: the trades' words (stems) and the words that matter. */
    public static function terms(array $read, array $rules): array
    {
        $ok = fn($w) => mb_strlen($w) >= 4 || in_array($w, ['tlb', 'ppe', 'lpg', 'ibr', 'coc'], true);
        $trades = array_slice($read['trades'] ?? [], 0, 2);
        $first = $trades ? array_values(array_filter(SaParse::tradeWords((string)$trades[0], $rules), $ok)) : [];
        $second = isset($trades[1]) ? array_values(array_filter(SaParse::tradeWords((string)$trades[1], $rules), $ok)) : [];
        $words = array_values(array_filter($read['keywords'] ?? [], fn($w) => mb_strlen($w) >= 3 && !in_array($w, self::GENERIC, true)));
        foreach ($read['search_terms'] ?? [] as $t) foreach (preg_split('/\s+/u', mb_strtolower($t)) as $w) if (mb_strlen($w) >= 4 && !in_array($w, self::GENERIC, true) && !in_array($w, $words, true)) $words[] = $w;
        $trade = array_values(array_unique(array_merge($first, $second)));
        $primary = array_values(array_unique(array_merge($words, $first)));
        $all = array_values(array_unique(array_merge($primary, $second)));
        return ['trade' => $trade, 'words' => $words, 'primary' => $primary, 'all' => array_slice($all, 0, 40)];
    }

    /** CRM suppliers that match, and those with history for it. */
    private static function fromCrm(PDO $db, int $companyId, array $terms, array $read): array
    {
        $likes = [];
        $args = [$companyId];
        foreach ($terms['all'] as $t) { $likes[] = 'hay LIKE ?'; $args[] = '%' . SaKit::likeEscape($t) . '%'; }
        $st = $db->prepare("SELECT id FROM (
                                SELECT a.id, CONCAT_WS(' ', a.name, a.legal_name, a.notes, ind.name, a.website,
                                       (SELECT GROUP_CONCAT(t.name SEPARATOR ' ') FROM crm_account_tags at JOIN crm_tags t ON t.id = at.tag_id WHERE at.account_id = a.id)) AS hay
                                  FROM crm_accounts a LEFT JOIN crm_industries ind ON ind.id = a.industry_id
                                 WHERE a.company_id = ? AND a.type = 'supplier' AND a.deleted_at IS NULL AND a.status IN ('active', 'prospect')
                            ) x WHERE " . implode(' OR ', $likes) . " LIMIT 300");
        $st->execute($args);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        // What was bought: bills (Finance) and orders (Procurement), on the words that matter (or the trade's)
        $lineWords = $terms['words'] ?: $terms['trade'];
        $bills = $pos = $past = [];
        if ($lineWords) {
            $cond = implode(' OR ', array_fill(0, count($lineWords), 'CONVERT(bl.item_description USING utf8mb4) LIKE ?'));
            $largs = array_map(fn($w) => '%' . SaKit::likeEscape($w) . '%', $lineWords);
            try {
                $st = $db->prepare("SELECT b.supplier_id, COUNT(DISTINCT b.id) n, MAX(b.issue_date) last,
                                           SUBSTRING_INDEX(GROUP_CONCAT(CONVERT(bl.item_description USING utf8mb4) ORDER BY b.issue_date DESC SEPARATOR '\\n'), '\\n', 1) descr,
                                           SUBSTRING_INDEX(GROUP_CONCAT(ROUND(bl.unit_price * (1 + COALESCE(bl.tax_rate, 0) / 100), 2) ORDER BY b.issue_date DESC SEPARATOR '|'), '|', 1) price
                                      FROM ap_bill_lines bl JOIN ap_bills b ON b.id = bl.bill_id
                                     WHERE b.company_id = ? AND b.status IN ('approved', 'posted', 'paid') AND ($cond)
                                  GROUP BY b.supplier_id ORDER BY last DESC LIMIT 25");
                $st->execute(array_merge([$companyId], $largs));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $bills[(int)$b['supplier_id']] = ['n' => (int)$b['n'], 'last' => (string)$b['last'], 'desc' => (string)$b['descr'], 'price' => $b['price'] !== null ? (int)round((float)$b['price'] * 100) : null];
                }
            } catch (Throwable $e) {
                error_log('[suppliers_ai] bills: ' . $e->getMessage());
            }
            try {
                $cond = implode(' OR ', array_fill(0, count($lineWords), 'CONVERT(l.description USING utf8mb4) LIKE ?'));
                $st = $db->prepare("SELECT p.supplier_id, COUNT(DISTINCT p.id) n, MAX(p.created_at) last,
                                           SUBSTRING_INDEX(GROUP_CONCAT(CONVERT(l.description USING utf8mb4) ORDER BY p.created_at DESC SEPARATOR '\\n'), '\\n', 1) descr,
                                           SUBSTRING_INDEX(GROUP_CONCAT(p.po_number ORDER BY p.created_at DESC SEPARATOR '|'), '|', 1) po
                                      FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.po_id
                                     WHERE p.company_id = ? AND p.status <> 'cancelled' AND ($cond)
                                  GROUP BY p.supplier_id ORDER BY last DESC LIMIT 25");
                $st->execute(array_merge([$companyId], $largs));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $pos[(int)$p['supplier_id']] = ['n' => (int)$p['n'], 'last' => substr((string)$p['last'], 0, 10), 'desc' => (string)$p['descr'], 'po' => (string)$p['po']];
            } catch (Throwable $e) {
                error_log('[suppliers_ai] orders: ' . $e->getMessage());
            }
        }
        // Who quoted on similar requests here
        $rconds = [];
        $rargs = [$companyId];
        if (!empty($read['trades'])) { $rconds[] = 'r.category IN (' . implode(',', array_fill(0, count(array_slice($read['trades'], 0, 2)), '?')) . ')'; $rargs = array_merge($rargs, array_slice($read['trades'], 0, 2)); }
        foreach (array_slice($terms['words'], 0, 4) as $w) { $rconds[] = 'r.title LIKE ?'; $rargs[] = '%' . SaKit::likeEscape($w) . '%'; }
        if ($rconds) {
            try {
                $st = $db->prepare("SELECT rs.account_id, COUNT(*) asked, SUM(rs.sent_at IS NOT NULL) sent, SUM(rs.replied_at IS NOT NULL OR rs.quote_cents IS NOT NULL) answered,
                                           SUM(rs.quote_cents IS NOT NULL) quoted, SUM(rs.decision = 'awarded') won,
                                           AVG(CASE WHEN rs.replied_at IS NOT NULL AND rs.sent_at IS NOT NULL AND rs.replied_at > rs.sent_at THEN TIMESTAMPDIFF(MINUTE, rs.sent_at, rs.replied_at) END) reply_min,
                                           SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN rs.decision = 'awarded' THEN r.title END ORDER BY r.created_at DESC SEPARATOR '\\n'), '\\n', 1) won_title
                                      FROM sai_request_suppliers rs JOIN sai_requests r ON r.id = rs.request_id AND r.company_id = rs.company_id AND r.deleted_at IS NULL
                                     WHERE rs.company_id = ? AND (" . implode(' OR ', $rconds) . ") GROUP BY rs.account_id LIMIT 50");
                $st->execute($rargs);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $past[(int)$p['account_id']] = $p;
            } catch (Throwable $e) {
                error_log('[suppliers_ai] past requests: ' . $e->getMessage());
            }
        }
        $all = array_values(array_unique(array_merge($ids, array_keys($bills), array_keys($pos), array_keys($past))));
        $accounts = self::accounts($db, $companyId, $all, true);
        $out = [];
        foreach ($accounts as $id => $a) {
            $hits = self::hits($a, $terms);
            $hasHistory = isset($bills[$id]) || isset($pos[$id]) || (isset($past[$id]) && ((int)$past[$id]['quoted'] > 0 || (int)$past[$id]['won'] > 0));
            if (!$hits && !$hasHistory) continue;
            $out['a' . $id] = $a + [
                'key' => 'a' . $id, 'source' => 'crm', 'account_id' => $id, 'hits' => $hits,
                'bills' => $bills[$id] ?? null, 'pos' => $pos[$id] ?? null, 'past' => $past[$id] ?? null, 'google' => null, 'distance_km' => null, 'lat' => null, 'lng' => null,
            ];
        }
        return $out;
    }

    /** Where the terms show on a CRM supplier. */
    private static function hits(array $a, array $terms): array
    {
        $h = [];
        $fields = ['name' => $a['name'] . ' ' . ($a['legal_name'] ?? ''), 'industry' => (string)($a['industry'] ?? ''), 'tags' => implode(' | ', $a['tags'] ?? []), 'notes' => (string)($a['notes'] ?? ''), 'website' => (string)($a['website'] ?? '')];
        foreach ($fields as $f => $text) {
            if ($text === '') continue;
            $lower = mb_strtolower($text);
            foreach ($terms['all'] as $t) {
                if (!SaParse::has($lower, $t)) continue;
                if (in_array($t, $terms['primary'] ?? $terms['all'], true)) $h['strong'] = true;
                if ($f === 'tags') {
                    foreach ($a['tags'] as $tag) if (SaParse::has(mb_strtolower($tag), $t) && !in_array($tag, $h['tags'] ?? [], true)) $h['tags'][] = $tag;
                } else {
                    $w = SaScore::wordFor($text, $t);
                    if (!in_array($w, $h[$f] ?? [], true)) $h[$f][] = $w;
                }
            }
        }
        // Only a second trade's words matched ("contractor" when looking for a plumber): a weak match
        if ($h && empty($h['strong'])) $h['weak'] = true;
        unset($h['strong']);
        return $h;
    }

    /** Google's places join the list — or add their rating to a supplier you already have. */
    private static function mergeGoogle(array &$cands, array $places, array $terms, array $read): void
    {
        $termWords = array_merge($terms['all'], array_map('mb_strtolower', preg_split('/\s+/u', (string)($read['trade']['term'] ?? '')) ?: []));
        foreach ($places as $p) {
            $g = ['place_id' => $p['place_id'], 'rating' => $p['rating'], 'rating_count' => $p['rating_count'], 'maps_url' => $p['maps_url'], 'type' => $p['type'], 'temporarily_closed' => $p['temporarily_closed']];
            $match = null;
            $pk = SaKit::phoneKey($p['phone']);
            $pd = SaKit::domain($p['website']);
            $pn = CrmAccounts::normName($p['name']);
            foreach ($cands as $k => $c) {
                if ($c['source'] !== 'crm') continue;
                if (($pk !== '' && $pk === SaKit::phoneKey((string)$c['phone'])) || ($pd !== '' && !SaKit::freeMail($pd) && $pd === SaKit::domain((string)$c['website'] ?: (string)$c['email']))
                    || CrmAccounts::nameSimilarity($pn, CrmAccounts::normName($c['name'])) >= 0.88) { $match = $k; break; }
            }
            if ($match !== null) {
                $cands[$match]['google'] = $g;
                $cands[$match]['distance_km'] = $p['distance_km'];
                $cands[$match]['lat'] = $p['lat'];
                $cands[$match]['lng'] = $p['lng'];
                continue;
            }
            $hitName = $hitType = [];
            foreach ($termWords as $t) {
                if ($t === '' || mb_strlen($t) < 3) continue;
                if (SaParse::has(mb_strtolower($p['name']), $t)) $hitName[] = $t;
                if ($p['type'] !== '' && SaParse::has(mb_strtolower($p['type']), $t)) $hitType[] = $t;
            }
            $cands['g' . $p['place_id']] = [
                'key' => 'g' . $p['place_id'], 'source' => 'google', 'account_id' => null, 'name' => $p['name'], 'legal_name' => '', 'phone' => $p['phone'], 'phone_intl' => $p['phone_intl'],
                'email' => '', 'website' => $p['website'], 'address' => $p['address'], 'city' => '', 'industry' => '', 'tags' => [], 'status' => '', 'preferred' => false, 'notes' => '',
                'contact' => ['name' => '', 'email' => '', 'phone' => ''], 'perf' => [], 'hits' => array_filter(['name' => $hitName, 'type' => $hitType]),
                'google' => $g, 'distance_km' => $p['distance_km'], 'lat' => $p['lat'], 'lng' => $p['lng'],
            ];
        }
    }

    /** CRM suppliers by id, with industry, tags, address and the main contact. */
    private static function accounts(PDO $db, int $companyId, array $ids, bool $activeOnly = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $st = $db->prepare("SELECT a.id, a.name, a.legal_name, a.phone, a.email, a.website, a.status, a.preferred, a.notes, a.vat_no,
                                   a.on_time_percent, a.avg_response_hours, a.defect_rate_percent, ind.name AS industry,
                                   (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR '|') FROM crm_account_tags at JOIN crm_tags t ON t.id = at.tag_id WHERE at.account_id = a.id) AS tags,
                                   (SELECT CONCAT_WS('|', COALESCE(ad.city, ''), CONCAT_WS(', ', NULLIF(ad.line1, ''), NULLIF(ad.city, ''), NULLIF(ad.region, '')))
                                      FROM crm_addresses ad WHERE ad.account_id = a.id AND ad.company_id = a.company_id ORDER BY ad.type = 'head_office' DESC, ad.id LIMIT 1) AS addr,
                                   (SELECT CONCAT_WS('|', TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), COALESCE(c.email, ''), COALESCE(NULLIF(c.mobile, ''), c.phone, ''))
                                      FROM crm_contacts c WHERE c.account_id = a.id AND c.company_id = a.company_id ORDER BY c.is_primary DESC, (c.email IS NULL OR c.email = ''), c.id LIMIT 1) AS contact
                              FROM crm_accounts a LEFT JOIN crm_industries ind ON ind.id = a.industry_id
                             WHERE a.company_id = ? AND a.type = 'supplier' AND a.deleted_at IS NULL AND a.id IN ($in)" . ($activeOnly ? " AND a.status IN ('active', 'prospect')" : ''));
        $st->execute([$companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            [$city, $address] = array_pad(explode('|', (string)$a['addr'], 2), 2, '');
            [$cn, $ce, $cp] = array_pad(explode('|', (string)$a['contact'], 3), 3, '');
            $out[(int)$a['id']] = [
                'id' => (int)$a['id'], 'name' => (string)$a['name'], 'legal_name' => (string)$a['legal_name'], 'phone' => (string)$a['phone'], 'email' => (string)$a['email'],
                'website' => (string)$a['website'], 'status' => (string)$a['status'], 'preferred' => (bool)$a['preferred'], 'notes' => (string)$a['notes'], 'vat' => trim((string)$a['vat_no']) !== '',
                'industry' => (string)$a['industry'], 'tags' => $a['tags'] !== null && $a['tags'] !== '' ? explode('|', (string)$a['tags']) : [],
                'city' => $city, 'address' => $address, 'contact' => ['name' => trim($cn), 'email' => trim($ce), 'phone' => trim($cp)],
                'perf' => array_filter(['on_time' => $a['on_time_percent'] !== null ? (float)$a['on_time_percent'] : null, 'response_h' => $a['avg_response_hours'] !== null ? (float)$a['avg_response_hours'] : null,
                                        'defect' => $a['defect_rate_percent'] !== null ? (float)$a['defect_rate_percent'] : null], fn($v) => $v !== null),
            ];
        }
        return $out;
    }

    private static function denied(array $c, array $deny): bool
    {
        $pk = SaKit::phoneKey((string)$c['phone']);
        if ($pk !== '' && in_array($pk, $deny['phones'], true)) return true;
        foreach ([(string)$c['website'], (string)$c['email']] as $x) {
            $d = SaKit::domain($x);
            if ($d !== '' && in_array($d, $deny['domains'], true)) return true;
        }
        $n = mb_strtolower($c['name']);
        foreach ($deny['names'] as $name) if ($name !== '' && str_contains($n, $name)) return true;
        return false;
    }

    private static function allowed(array $c, array $allow): bool
    {
        if ($c['account_id'] && in_array((int)$c['account_id'], $allow['ids'], true)) return true;
        $pk = SaKit::phoneKey((string)$c['phone']);
        if ($pk !== '' && in_array($pk, $allow['phones'], true)) return true;
        foreach ([(string)$c['website'], (string)$c['email']] as $x) {
            $d = SaKit::domain($x);
            if ($d !== '' && in_array($d, $allow['domains'], true)) return true;
        }
        $n = mb_strtolower($c['name']);
        foreach ($allow['names'] as $name) if ($name !== '' && str_contains($n, $name)) return true;
        return false;
    }

    /** A compliance state as the page shows it. */
    public static function compliance(?array $s): array
    {
        $status = $s['status'] ?? 'none';
        $labels = ['valid' => 'Documents in order', 'expiring' => 'Expiring soon', 'expired' => 'Documents expired', 'missing' => 'Documents missing', 'none' => 'No documents asked'];
        $detail = array_merge(array_map(fn($x) => 'Expired: ' . $x, $s['expired'] ?? []), array_map(fn($x) => 'Missing: ' . $x, $s['missing'] ?? []), array_map(fn($x) => 'Expiring: ' . $x, $s['expiring'] ?? []));
        return ['status' => $status, 'label' => $labels[$status] ?? $status, 'blocking' => !empty($s['blocking']), 'detail' => $detail];
    }

    public static function mapsSearch(string $q): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($q));
    }

    /** The search as the page needs it. */
    private static function shape(PDO $db, int $companyId, string $role, int $queryId, string $text, array $read, array $cands, array $meta, array $s, array $filters, int $ms, int $byUser, string $at): array
    {
        $place = (string)($meta['place'] ?? '');
        $min = $s['min_score'];
        $rows = [];
        foreach ($cands as $c) {
            $phone = (string)($c['contact']['phone'] ?? '') ?: (string)$c['phone'];
            $email = (string)($c['contact']['email'] ?? '') ?: (string)$c['email'];
            $comp = $c['account_id'] ? self::compliance($c['compliance'] ?? null) : null;
            $dir = $c['lat'] !== null && $c['lng'] !== null ? 'https://www.google.com/maps/dir/?api=1&destination=' . $c['lat'] . ',' . $c['lng']
                 : (($c['address'] ?? '') !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode((string)$c['address']) : '');
            $rows[] = [
                'id' => (int)$c['id'], 'source' => $c['source'], 'account_id' => $c['account_id'] ? (int)$c['account_id'] : null,
                'name' => (string)$c['name'], 'phone' => $phone, 'dial' => SaKit::dialable($phone), 'email' => $email, 'website' => (string)$c['website'],
                'address' => (string)$c['address'], 'city' => (string)($c['city'] ?? ''), 'industry' => (string)($c['industry'] ?? ''), 'tags' => array_slice((array)($c['tags'] ?? []), 0, 4),
                'contact' => (string)($c['contact']['name'] ?? ''), 'preferred' => !empty($c['preferred']), 'prospect' => ($c['status'] ?? '') === 'prospect',
                'distance_km' => $c['distance_km'], 'rating' => $c['google']['rating'] ?? null, 'rating_count' => $c['google']['rating_count'] ?? null,
                'maps' => (string)($c['google']['maps_url'] ?? ''), 'directions' => $dir,
                'score' => (int)round((float)$c['score'] * 100), 'below' => (float)$c['score'] < $min, 'reasons' => array_values((array)$c['reasons']),
                'compliance' => $comp, 'blocked' => !empty($comp['blocking']),
            ];
        }
        $q = $read['query'] ?: $text;
        return [
            'query_id' => $queryId, 'text' => $text, 'at' => $at, 'by' => SaKit::personName($db, $byUser), 'ms' => $ms,
            'read' => self::readView($read), 'place' => $place, 'place_from_company' => !empty($meta['place_from_company']), 'filters' => $filters,
            'rows' => $rows, 'ideas' => array_values((array)($meta['ideas'] ?? [])), 'notes' => array_values((array)($meta['notes'] ?? [])),
            'min_score' => (int)round($min * 100), 'google' => !empty($meta['google']), 'summary' => (string)($meta['ai_summary'] ?? ''),
            'maps' => self::mapsSearch(trim(($read['trade']['term'] ?? $q) . ' near ' . ($place ?: 'me'))),
            'quota' => SaKit::quota($db, $companyId, $s),
        ];
    }

    /** What was read, for the chips under the search box. */
    public static function readView(array $r): array
    {
        return [
            'title' => (string)($r['title'] ?? ''), 'trade' => $r['trade'] ?? null, 'trades' => array_values(array_filter(array_map(fn($k) => SaParse::trade((string)$k), array_slice($r['trades'] ?? [], 1, 3)))),
            'keywords' => array_values($r['keywords'] ?? []), 'location' => $r['location'] ?? null, 'quantity' => $r['quantity'] ?? null,
            'budget' => $r['budget'] ?? null, 'needed_by' => $r['needed_by'] ?? null, 'when' => $r['when'] ?? null, 'urgent' => !empty($r['urgent']),
            'requirements' => array_values(array_map(fn($x) => $x['label'], $r['requirements'] ?? [])), 'ai' => !empty($r['ai']), 'summary' => (string)($r['summary'] ?? ''),
        ];
    }
}
