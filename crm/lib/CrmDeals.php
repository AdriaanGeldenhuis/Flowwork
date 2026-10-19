<?php
// crm/lib/CrmDeals.php
//
// Deals (crm_opportunities) on the company's own pipeline:
//   - the board (one column per open stage, plus won / lost) and the list,
//   - create / update with the stage's default probability, a stage-change
//     stamp, the loss reason, card order within a column, custom fields,
//   - who may change a deal: admins and the deal's owner (anyone who can write
//     may create deals and take unowned ones),
//   - stage management for settings (rename, recolour, reorder, add, remove
//     with the deals moved to another stage),
//   - turning a won deal into a project (Projects engine), once.

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmFields.php';

final class CrmDeals
{
    public static function shape(PDO $db, int $companyId, array $o): array
    {
        $stages = Crm::stageMap($db, $companyId);
        $s = $stages[$o['stage']] ?? ['key' => (string)$o['stage'], 'label' => ucfirst((string)$o['stage']), 'color' => '#85766b', 'kind' => 'open', 'probability' => 0];
        $changed = $o['stage_changed_at'] ?: $o['created_at'];
        return [
            'id' => (int)$o['id'], 'title' => (string)$o['title'], 'amount' => (float)$o['amount'],
            'stage' => (string)$o['stage'], 'stage_label' => $s['label'], 'stage_color' => $s['color'], 'kind' => $s['kind'],
            'probability' => (float)$o['probability'], 'close_date' => $o['close_date'] ?: null,
            'owner_id' => (int)$o['owner_id'], 'owner' => Crm::personName($db, $companyId, (int)$o['owner_id']),
            'account_id' => (int)$o['account_id'], 'account' => (string)($o['account_name'] ?? ''),
            'contact_id' => isset($o['contact_id']) && $o['contact_id'] !== null ? (int)$o['contact_id'] : null,
            'contact' => isset($o['contact_name']) ? trim((string)$o['contact_name']) : '',
            'notes' => (string)($o['notes'] ?? ''), 'loss_reason' => (string)($o['loss_reason'] ?? ''),
            'days_in_stage' => $changed ? max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime(substr((string)$changed, 0, 10))) / 86400)) : 0,
            'stage_changed_at' => $o['stage_changed_at'], 'created_at' => $o['created_at'], 'updated_at' => $o['updated_at'],
            'sort_order' => (int)($o['sort_order'] ?? 0),
        ];
    }

    private const SELECT = "SELECT o.*, a.name AS account_name, CONCAT_WS(' ', c.first_name, c.last_name) AS contact_name
                              FROM crm_opportunities o
                              JOIN crm_accounts a ON a.id = o.account_id AND a.company_id = o.company_id
                         LEFT JOIN crm_contacts c ON c.id = o.contact_id AND c.company_id = o.company_id";

    /**
     * Deals for the board / list. $p: q, owner (me|id), account, close (overdue|month|quarter|none),
     * include ('open' | 'all' | 'won' | 'lost'), closed_days (how far back won/lost go on the board).
     */
    public static function list(PDO $db, int $companyId, int $userId, array $p): array
    {
        $sql = self::SELECT . " WHERE o.company_id = ? AND a.deleted_at IS NULL";
        $params = [$companyId];
        $q = mb_substr(trim((string)($p['q'] ?? '')), 0, 100);
        if ($q !== '') {
            $like = '%' . Crm::likeEscape($q) . '%';
            $sql .= " AND (o.title LIKE ? OR a.name LIKE ?)";
            array_push($params, $like, $like);
        }
        $owner = (string)($p['owner'] ?? '');
        if ($owner === 'me') { $sql .= " AND o.owner_id = ?"; $params[] = $userId; }
        elseif ((int)$owner > 0) { $sql .= " AND o.owner_id = ?"; $params[] = (int)$owner; }
        if ((int)($p['account'] ?? 0) > 0) { $sql .= " AND o.account_id = ?"; $params[] = (int)$p['account']; }
        switch ((string)($p['close'] ?? '')) {
            case 'overdue': $sql .= " AND o.close_date < CURDATE()"; break;
            case 'month': $sql .= " AND o.close_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())"; break;
            case 'quarter': $sql .= " AND o.close_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 3 MONTH"; break;
            case 'none': $sql .= " AND o.close_date IS NULL"; break;
        }
        $include = (string)($p['include'] ?? 'board');
        $closedDays = max(7, min(3650, (int)($p['closed_days'] ?? 30)));
        if ($include === 'open') $sql .= " AND o.stage NOT IN ('won','lost')";
        elseif ($include === 'won') $sql .= " AND o.stage = 'won'";
        elseif ($include === 'lost') $sql .= " AND o.stage = 'lost'";
        elseif ($include === 'board') $sql .= " AND (o.stage NOT IN ('won','lost') OR COALESCE(o.stage_changed_at, o.updated_at) >= NOW() - INTERVAL $closedDays DAY)";
        $st = $db->prepare($sql . " ORDER BY o.sort_order, (o.close_date IS NULL), o.close_date, o.id DESC LIMIT 2000");
        $st->execute($params);
        $rows = array_map(fn($o) => self::shape($db, $companyId, $o), $st->fetchAll(PDO::FETCH_ASSOC));

        $stages = Crm::stages($db, $companyId);
        $byStage = [];
        foreach ($stages as $s) $byStage[$s['key']] = ['count' => 0, 'value' => 0.0, 'weighted' => 0.0];
        foreach ($rows as $r) {
            if (!isset($byStage[$r['stage']])) $byStage[$r['stage']] = ['count' => 0, 'value' => 0.0, 'weighted' => 0.0];
            $byStage[$r['stage']]['count']++;
            $byStage[$r['stage']]['value'] += $r['amount'];
            $byStage[$r['stage']]['weighted'] += $r['amount'] * $r['probability'] / 100;
        }
        // Deals on a stage the company removed show in the first open column.
        $known = array_column($stages, 'key');
        $first = $stages[0]['key'] ?? 'prospect';
        foreach ($rows as &$r) if (!in_array($r['stage'], $known, true)) $r['column'] = $first; else $r['column'] = $r['stage'];
        unset($r);
        return ['stages' => $stages, 'deals' => $rows, 'totals' => $byStage];
    }

    public static function get(PDO $db, int $companyId, int $id): ?array
    {
        $st = $db->prepare(self::SELECT . " WHERE o.id = ? AND o.company_id = ?");
        $st->execute([$id, $companyId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        return $o ? self::shape($db, $companyId, $o) : null;
    }

    public static function canEdit(array $deal, int $userId, string $role): bool
    {
        return Crm::isAdmin($role) || (int)$deal['owner_id'] === $userId || (int)$deal['owner_id'] === 0;
    }

    /**
     * Create or update. $in: id, account_id, title, amount, stage, probability, close_date, owner_id,
     * contact_id, notes, loss_reason, custom{}. Returns the deal.
     */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $st = $db->prepare("SELECT * FROM crm_opportunities WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new CrmUserError('Deal not found.');
            if (!self::canEdit($cur, $userId, $role)) throw new CrmForbidden('Only an admin or the deal owner can change this deal.');
        }
        $creating = !$cur;
        $has = fn($k) => array_key_exists($k, $in);
        $stages = Crm::stageMap($db, $companyId);
        $v = [];
        if ($creating || $has('account_id')) {
            $aid = (int)($in['account_id'] ?? 0);
            $chk = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ? AND deleted_at IS NULL");
            $chk->execute([$aid, $companyId]);
            if (!$chk->fetchColumn()) throw new CrmUserError('Choose who the deal is with.');
            $v['account_id'] = $aid;
        }
        if ($creating || $has('title')) {
            $t = mb_substr(trim((string)($in['title'] ?? '')), 0, 190);
            if ($t === '') throw new CrmUserError('Give the deal a name.');
            $v['title'] = $t;
        }
        if ($creating || $has('amount')) {
            $raw = str_replace([' ', ',', 'R'], '', (string)($in['amount'] ?? '0'));
            if ($raw !== '' && !is_numeric($raw)) throw new CrmUserError('The value must be a number.');
            $amt = round((float)$raw, 2);
            if ($amt < 0) throw new CrmUserError('The value cannot be negative.');
            if ($amt > 9999999999999) throw new CrmUserError('The value is too large.');
            $v['amount'] = $amt;
        }
        $stageChanged = false;
        if ($creating || $has('stage')) {
            $s = (string)($in['stage'] ?? '');
            if ($s === '') $s = Crm::openStageKeys($db, $companyId)[0] ?? 'prospect';
            if (!isset($stages[$s])) throw new CrmUserError('Choose a stage from the pipeline.');
            $v['stage'] = $s;
            $stageChanged = $creating || $s !== $cur['stage'];
            if ($stageChanged) {
                $v['stage_changed_at'] = date('Y-m-d H:i:s');
                if (!$has('probability')) $v['probability'] = (float)$stages[$s]['probability'];
                if ($s !== 'lost' && $cur && $cur['stage'] === 'lost' && !$has('loss_reason')) $v['loss_reason'] = null;
            }
        }
        if ($has('probability')) {
            $pr = (float)$in['probability'];
            if ($pr < 0 || $pr > 100) throw new CrmUserError('Probability must be between 0 and 100.');
            $v['probability'] = round($pr, 2);
        }
        if ($creating || $has('close_date')) {
            $raw = trim((string)($in['close_date'] ?? ''));
            $d = $raw === '' ? null : Crm::ymd($raw);
            if ($raw !== '' && $d === null) throw new CrmUserError('The expected close date is not a valid date.');
            $v['close_date'] = $d;
        }
        if ($creating || $has('owner_id')) {
            $o = (int)($in['owner_id'] ?? 0) ?: $userId;
            if (!isset(Crm::people($db, $companyId)[$o]) && $o !== $userId) throw new CrmUserError('The owner must be someone in your company.');
            if ($cur && $o !== (int)$cur['owner_id'] && !Crm::isAdmin($role) && (int)$cur['owner_id'] !== $userId) {
                throw new CrmForbidden('Only an admin or the deal owner can hand the deal to someone else.');
            }
            $v['owner_id'] = $o;
        }
        if ($creating || $has('contact_id')) {
            $c = (int)($in['contact_id'] ?? 0);
            $aid = $v['account_id'] ?? (int)$cur['account_id'];
            if ($c > 0) {
                $chk = $db->prepare("SELECT id FROM crm_contacts WHERE id = ? AND company_id = ? AND account_id = ?");
                $chk->execute([$c, $companyId, $aid]);
                if (!$chk->fetchColumn()) $c = 0;
            }
            $v['contact_id'] = $c > 0 ? $c : null;
        } elseif (isset($v['account_id']) && $cur && (int)$v['account_id'] !== (int)$cur['account_id']) {
            $v['contact_id'] = null; // the contact belonged to the other account
        }
        if ($creating || $has('notes')) $v['notes'] = mb_substr(trim((string)($in['notes'] ?? '')), 0, 10000) ?: null;
        if ($has('loss_reason')) $v['loss_reason'] = mb_substr(trim((string)$in['loss_reason']), 0, 255) ?: null;
        $custom = CrmFields::validate(CrmFields::defs($db, $companyId, 'deal'), is_array($in['custom'] ?? null) ? $in['custom'] : [], $creating);

        $db->beginTransaction();
        try {
            if ($creating) {
                $v += ['company_id' => $companyId, 'created_by' => $userId, 'probability' => (float)($stages[$v['stage']]['probability'] ?? 0)];
                $min = $db->prepare("SELECT COALESCE(MIN(sort_order), 0) FROM crm_opportunities WHERE company_id = ? AND stage = ?");
                $min->execute([$companyId, $v['stage']]);
                $v['sort_order'] = (int)$min->fetchColumn() - 10; // new deals go to the top of their column
                $cols = array_keys($v);
                $db->prepare("INSERT INTO crm_opportunities (`" . implode('`, `', $cols) . "`, created_at, updated_at) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", NOW(), NOW())")
                   ->execute(array_values($v));
                $id = (int)$db->lastInsertId();
                Crm::audit($db, $companyId, $userId, 'create', 'crm_opportunity', $id, ['title' => $v['title'], 'amount' => $v['amount'], 'stage' => $v['stage']]);
            } elseif ($v) {
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
                $db->prepare("UPDATE crm_opportunities SET $sets, updated_at = NOW() WHERE id = ? AND company_id = ?")
                   ->execute(array_merge(array_values($v), [$id, $companyId]));
                $changes = [];
                foreach ($v as $k => $val) if ($k !== 'stage_changed_at' && (string)($cur[$k] ?? '') !== (string)($val ?? '')) $changes[$k] = $val;
                if ($changes) Crm::audit($db, $companyId, $userId, 'update', 'crm_opportunity', $id, $changes + ($stageChanged ? ['from_stage' => $cur['stage']] : []));
            }
            CrmFields::save($db, $companyId, $id, $custom);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $deal = self::get($db, $companyId, $id);
        // Tell the new owner (not yourself)
        $owner = (int)($v['owner_id'] ?? 0);
        if ($owner > 0 && $owner !== $userId && ($creating || $owner !== (int)$cur['owner_id'])) {
            require_once __DIR__ . '/CrmAccounts.php';
            CrmAccounts::notify($db, $companyId, $owner, 'crm_deal_assigned', Crm::personName($db, $companyId, $userId) . ' gave you a deal: ' . $deal['title'],
                                $deal['account'] . ' · R ' . number_format($deal['amount'], 2, '.', ','), '/crm/opp_view.php?id=' . $id);
        }
        return $deal;
    }

    /** Drag on the board: new stage and the ids of that column in their new order. */
    public static function move(PDO $db, int $companyId, int $userId, string $role, int $id, string $stage, array $order, ?string $lossReason = null): array
    {
        $deal = self::save($db, $companyId, $userId, $role, ['id' => $id, 'stage' => $stage] + ($lossReason !== null ? ['loss_reason' => $lossReason] : []));
        $order = array_values(array_unique(array_filter(array_map('intval', $order))));
        if ($order) {
            $st = $db->prepare("UPDATE crm_opportunities SET sort_order = ?, updated_at = updated_at WHERE id = ? AND company_id = ? AND stage = ?");
            foreach (array_slice($order, 0, 500) as $i => $oid) $st->execute([($i + 1) * 10, $oid, $companyId, $stage]);
        }
        return $deal;
    }

    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        $st = $db->prepare("SELECT * FROM crm_opportunities WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) throw new CrmUserError('Deal not found.');
        if (!self::canEdit($cur, $userId, $role)) throw new CrmForbidden('Only an admin or the deal owner can delete this deal.');
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE crm_tasks SET opportunity_id = NULL WHERE company_id = ? AND opportunity_id = ?")->execute([$companyId, $id]);
            $db->prepare("UPDATE crm_interactions SET opportunity_id = NULL WHERE company_id = ? AND opportunity_id = ?")->execute([$companyId, $id]);
            CrmFields::forget($db, $companyId, 'deal', $id);
            $db->prepare("DELETE FROM crm_opportunities WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            Crm::audit($db, $companyId, $userId, 'delete', 'crm_opportunity', $id, ['title' => $cur['title'], 'amount' => (float)$cur['amount'], 'account_id' => (int)$cur['account_id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** The deal's history from the audit log (stage moves, value changes), newest first. */
    public static function history(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT action, details, user_id, created_at FROM audit_log
                             WHERE company_id = ? AND entity_type = 'crm_opportunity' AND entity_id = ? ORDER BY id DESC LIMIT 100");
        $st->execute([$companyId, $id]);
        $stages = Crm::stageMap($db, $companyId);
        $label = fn($k) => $stages[$k]['label'] ?? ucfirst((string)$k);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['details'], true) ?: [];
            $who = Crm::personName($db, $companyId, (int)$r['user_id']);
            $lines = [];
            if ($r['action'] === 'create') $lines[] = 'created the deal';
            elseif ($r['action'] === 'convert') $lines[] = 'turned it into a project';
            else {
                if (isset($d['stage'])) $lines[] = 'moved it ' . (isset($d['from_stage']) ? 'from ' . $label($d['from_stage']) . ' ' : '') . 'to ' . $label($d['stage']);
                if (isset($d['amount'])) $lines[] = 'set the value to R ' . number_format((float)$d['amount'], 2, '.', ',');
                if (array_key_exists('close_date', $d)) $lines[] = $d['close_date'] ? 'expects it to close on ' . date('j M Y', strtotime((string)$d['close_date'])) : 'cleared the close date';
                if (isset($d['owner_id'])) $lines[] = 'gave it to ' . Crm::personName($db, $companyId, (int)$d['owner_id']);
                if (isset($d['title'])) $lines[] = 'renamed it “' . $d['title'] . '”';
                if (!empty($d['loss_reason'])) $lines[] = 'noted why it was lost: ' . $d['loss_reason'];
                if (isset($d['probability']) && !isset($d['stage'])) $lines[] = 'set the probability to ' . (float)$d['probability'] . '%';
                if (!$lines && array_key_exists('notes', $d)) $lines[] = 'updated the notes';
            }
            if (!$lines) continue;
            $out[] = ['who' => $who, 'text' => implode(', ', $lines), 'at' => $r['created_at']];
        }
        return $out;
    }

    // --------------------------------------------------------------- stages

    /**
     * Replace the company's stages. $list: [{key?, label, color, probability, kind}] in board order;
     * 'won' and 'lost' keep their keys. Removed open stages hand their deals to $moveTo (or the first open stage).
     */
    public static function saveStages(PDO $db, int $companyId, int $userId, array $list): array
    {
        $open = [];
        $terminal = [];
        $seen = [];
        foreach ($list as $s) {
            if (!is_array($s)) continue;
            $kind = in_array($s['kind'] ?? 'open', ['open', 'won', 'lost'], true) ? ($s['kind'] ?? 'open') : 'open';
            $label = mb_substr(trim((string)($s['label'] ?? '')), 0, 60);
            if ($label === '') throw new CrmUserError('Every stage needs a name.');
            $color = Crm::color($s['color'] ?? '') ?: '#85766b';
            $prob = max(0, min(100, (int)($s['probability'] ?? 0)));
            if ($kind !== 'open') {
                $terminal[$kind] = ['key' => $kind, 'label' => $label, 'color' => $color, 'probability' => $kind === 'won' ? 100 : 0, 'kind' => $kind];
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($s['key'] ?? '')));
            if ($key === '' || in_array($key, ['won', 'lost'], true) || isset($seen[$key])) {
                $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: 'stage')), '_') ?: 'stage';
                $base = substr($base, 0, 30);
                $key = $base;
                for ($i = 2; isset($seen[$key]) || in_array($key, ['won', 'lost'], true); $i++) $key = $base . '_' . $i;
            }
            $seen[$key] = true;
            $open[] = ['key' => $key, 'label' => $label, 'color' => $color, 'probability' => $prob, 'kind' => 'open'];
        }
        if (!$open) throw new CrmUserError('Keep at least one open stage.');
        if (count($open) > 15) throw new CrmUserError('Use at most 15 open stages.');
        foreach (['won', 'lost'] as $k) {
            if (!isset($terminal[$k])) foreach (Crm::DEFAULT_STAGES as $d) if ($d['key'] === $k) $terminal[$k] = $d;
        }
        $all = array_merge($open, [$terminal['won'], $terminal['lost']]);
        $keys = array_column($all, 'key');

        $db->beginTransaction();
        try {
            // Deals on removed stages go to the first open stage.
            $in = implode(',', array_map([$db, 'quote'], $keys));
            $st = $db->prepare("UPDATE crm_opportunities SET stage = ?, stage_changed_at = NOW(), updated_at = NOW() WHERE company_id = ? AND stage NOT IN ($in)");
            $st->execute([$open[0]['key'], $companyId]);
            $moved = $st->rowCount();
            $db->prepare("DELETE FROM crm_pipeline_stages WHERE company_id = ?")->execute([$companyId]);
            $ins = $db->prepare("INSERT INTO crm_pipeline_stages (company_id, stage_key, label, color, probability, kind, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            foreach ($all as $i => $s) $ins->execute([$companyId, $s['key'], $s['label'], $s['color'], $s['probability'], $s['kind'], ($i + 1) * 10]);
            Crm::audit($db, $companyId, $userId, 'update', 'crm_pipeline', $companyId, ['stages' => $keys, 'moved' => $moved]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        Crm::flush();
        return ['stages' => Crm::stages($db, $companyId), 'moved' => $moved];
    }

    // -------------------------------------------------------------- convert

    /** The project a deal became (audit_log 'convert'), or null. */
    public static function project(PDO $db, int $companyId, int $oppId): ?array
    {
        $st = $db->prepare("SELECT details FROM audit_log WHERE company_id = ? AND action = 'convert' AND entity_type = 'crm_opportunity' AND entity_id = ? ORDER BY id DESC LIMIT 10");
        $st->execute([$companyId, $oppId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $details) {
            $pid = (int)(json_decode((string)$details, true)['project_id'] ?? 0);
            if ($pid <= 0) continue;
            try {
                $f = $db->prepare("SELECT project_id, project_number, name FROM projects WHERE project_id = ? AND company_id = ?");
                $f->execute([$pid, $companyId]);
                if ($r = $f->fetch(PDO::FETCH_ASSOC)) {
                    require_once __DIR__ . '/../../projects/lib/autoload.php';
                    return ['id' => (int)$r['project_id'], 'number' => (string)$r['project_number'], 'name' => (string)$r['name'], 'url' => ProjectLink::projectUrl((int)$r['project_id'])];
                }
            } catch (Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /** Project templates offered when converting. */
    public static function templates(): array
    {
        try {
            require_once __DIR__ . '/../../projects/lib/autoload.php';
            $out = [];
            foreach (Templates::projects() as $key => $t) $out[] = ['key' => (string)$key, 'name' => (string)($t['label'] ?? ucfirst(str_replace('_', ' ', (string)$key))), 'hint' => (string)($t['hint'] ?? '')];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Turn a won deal into a project (once). Returns ['project' => [...], 'existing' => bool]. */
    public static function convert(PDO $db, int $companyId, int $userId, string $role, int $id, array $in): array
    {
        require_once __DIR__ . '/../../projects/lib/autoload.php';
        $lock = 'fwcrm:opp-convert:' . $companyId . ':' . $id;
        if (!(bool)$db->query("SELECT GET_LOCK(" . $db->quote($lock) . ", 5)")->fetchColumn()) {
            throw new CrmUserError('This deal is already being converted. Try again in a moment.');
        }
        $repo = null;
        $projectId = 0;
        try {
            $st = $db->prepare("SELECT * FROM crm_opportunities WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $opp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$opp) throw new CrmUserError('Deal not found.');
            if (!self::canEdit($opp, $userId, $role)) throw new CrmForbidden('Only an admin or the deal owner can convert this deal.');
            if ($existing = self::project($db, $companyId, $id)) return ['project' => $existing, 'existing' => true];
            if ($opp['stage'] !== 'won') throw new CrmUserError('Mark the deal as won first.');
            $template = (string)($in['template'] ?? 'construction_standard');
            if (!isset(Templates::projects()[$template])) throw new CrmUserError('Choose a project template.');
            $repo = ProjectLink::repo($db, $companyId, $userId, $role);
            $fields = [
                'name' => mb_substr(trim((string)($in['name'] ?? '')) ?: (string)$opp['title'], 0, 255),
                'client_id' => (int)$opp['account_id'],
                'budget' => max(0, (float)$opp['amount']),
                'start_date' => Crm::ymd($in['start_date'] ?? '') ?? date('Y-m-d'),
                'template' => $template,
            ];
            if ($end = Crm::ymd($in['end_date'] ?? '')) $fields['end_date'] = $end;
            if (isset($repo->people()[$userId])) $fields['manager_id'] = $userId;
            try {
                $projectId = $repo->createProject($fields);
            } catch (ProjectUserError $e) {
                throw new CrmUserError($e->getMessage());
            }
            $owner = (int)$opp['owner_id'];
            if ($owner > 0 && $owner !== $userId && isset($repo->people()[$owner])) $repo->setMember($projectId, $owner, 'owner');
            Crm::audit($db, $companyId, $userId, 'convert', 'crm_opportunity', $id, ['project_id' => $projectId, 'template' => $template]);
            Crm::audit($db, $companyId, $userId, 'create', 'project', $projectId, ['from_opportunity' => $id]);
            return ['project' => self::project($db, $companyId, $id) ?? ['id' => $projectId, 'url' => ProjectLink::projectUrl($projectId)], 'existing' => false];
        } catch (Throwable $e) {
            if ($projectId > 0 && $repo) {
                try { $repo->deleteProject($projectId); } catch (Throwable $e2) { error_log('[crm] convert cleanup: ' . $e2->getMessage()); }
            }
            throw $e;
        } finally {
            try { $db->query("SELECT RELEASE_LOCK(" . $db->quote($lock) . ")"); } catch (Throwable $e) { }
        }
    }
}
