<?php
// crm/lib/CrmActivity.php
//
// Activity on accounts and deals: notes, calls, emails, meetings, visits,
// WhatsApps and issues (crm_interactions), and the timeline that weaves them
// together with what happened elsewhere — emails in Mail linked to the
// account, finished tasks, deals opened / won / lost, quotes and invoices,
// supplier bills, compliance documents. Mail is personal: an email from
// someone else's mailbox shows that it happened, not what it said.

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmTasks.php';

final class CrmActivity
{
    public const META = [
        'note'     => ['label' => 'Note',     'verb' => 'added a note'],
        'call'     => ['label' => 'Call',     'verb' => 'logged a call'],
        'email'    => ['label' => 'Email',    'verb' => 'logged an email'],
        'meeting'  => ['label' => 'Meeting',  'verb' => 'logged a meeting'],
        'visit'    => ['label' => 'Visit',    'verb' => 'logged a visit'],
        'whatsapp' => ['label' => 'WhatsApp', 'verb' => 'logged a WhatsApp'],
        'issue'    => ['label' => 'Issue',    'verb' => 'raised an issue'],
    ];

    public static function shape(PDO $db, int $companyId, array $i): array
    {
        return [
            'kind' => 'interaction', 'id' => (int)$i['id'], 'type' => (string)$i['type'],
            'subject' => (string)($i['subject'] ?? ''), 'body' => (string)($i['body'] ?? ''),
            'at' => $i['created_at'], 'pinned' => (bool)($i['pinned'] ?? false), 'via' => (string)($i['via'] ?? 'manual'),
            'by_id' => $i['created_by'] !== null ? (int)$i['created_by'] : null,
            'by' => Crm::personName($db, $companyId, $i['created_by'] !== null ? (int)$i['created_by'] : null),
            'contact' => !empty($i['contact_id']) ? ['id' => (int)$i['contact_id'], 'name' => trim((string)($i['contact_name'] ?? ''))] : null,
            'deal' => !empty($i['opportunity_id']) ? ['id' => (int)$i['opportunity_id'], 'title' => (string)($i['deal_title'] ?? '')] : null,
            'account' => isset($i['account_name']) ? ['id' => (int)$i['account_id'], 'name' => (string)$i['account_name'], 'type' => (string)($i['account_type'] ?? '')] : null,
        ];
    }

    private const SELECT = "SELECT i.*, CONCAT_WS(' ', c.first_name, c.last_name) AS contact_name, o.title AS deal_title,
                                   a.name AS account_name, a.type AS account_type
                              FROM crm_interactions i
                              JOIN crm_accounts a ON a.id = i.account_id AND a.company_id = i.company_id
                         LEFT JOIN crm_contacts c ON c.id = i.contact_id AND c.company_id = i.company_id
                         LEFT JOIN crm_opportunities o ON o.id = i.opportunity_id AND o.company_id = i.company_id";

    public static function get(PDO $db, int $companyId, int $id): ?array
    {
        $st = $db->prepare(self::SELECT . " WHERE i.id = ? AND i.company_id = ?");
        $st->execute([$id, $companyId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::shape($db, $companyId, $r) : null;
    }

    /**
     * Log or edit activity. $in: id, account_id, type, subject, body, contact_id, opportunity_id,
     * at (when it happened), pinned, followup{title, due_date, due_time, assigned_to, remind}.
     */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $st = $db->prepare("SELECT * FROM crm_interactions WHERE id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new CrmUserError('This activity no longer exists.');
            if (!Crm::isAdmin($role) && (int)$cur['created_by'] !== $userId) throw new CrmForbidden('Only the person who logged this, or an admin, can change it.');
        }
        $has = fn($k) => array_key_exists($k, $in);
        $accountId = $cur ? (int)$cur['account_id'] : (int)($in['account_id'] ?? 0);
        $dealId = $has('opportunity_id') ? (int)$in['opportunity_id'] : (int)($cur['opportunity_id'] ?? 0);
        if ($dealId > 0) {
            $st = $db->prepare("SELECT account_id FROM crm_opportunities WHERE id = ? AND company_id = ?");
            $st->execute([$dealId, $companyId]);
            $acc = (int)$st->fetchColumn();
            if (!$acc) throw new CrmUserError('Deal not found.');
            if ($accountId === 0) $accountId = $acc;
            if ($acc !== $accountId) $dealId = 0;
        }
        $chk = $db->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
        $chk->execute([$accountId, $companyId]);
        if (!$chk->fetchColumn()) throw new CrmUserError('Account not found.');

        $cfg = Crm::config($db, $companyId);
        $v = [];
        if (!$cur || $has('type')) {
            $type = (string)($in['type'] ?? 'note');
            if (!isset(self::META[$type])) throw new CrmUserError('Choose what kind of activity this is.');
            if (!$cur && empty($cfg['activity'][$type])) throw new CrmUserError('This kind of activity is switched off in CRM settings.');
            $v['type'] = $type;
        }
        if (!$cur || $has('subject')) $v['subject'] = mb_substr(trim((string)($in['subject'] ?? '')), 0, 190) ?: null;
        if (!$cur || $has('body')) $v['body'] = mb_substr(trim((string)($in['body'] ?? '')), 0, 20000) ?: null;
        if (($v['subject'] ?? $cur['subject'] ?? null) === null && ($v['body'] ?? $cur['body'] ?? null) === null) {
            throw new CrmUserError('Write something about it.');
        }
        if (!$cur || $has('contact_id')) {
            $c = (int)($in['contact_id'] ?? 0);
            if ($c > 0) {
                $st = $db->prepare("SELECT id FROM crm_contacts WHERE id = ? AND company_id = ? AND account_id = ?");
                $st->execute([$c, $companyId, $accountId]);
                if (!$st->fetchColumn()) $c = 0;
            }
            $v['contact_id'] = $c ?: null;
        }
        if (!$cur || $has('opportunity_id')) $v['opportunity_id'] = $dealId ?: null;
        if ($has('pinned')) $v['pinned'] = !empty($in['pinned']) ? 1 : 0;
        if ($has('at') && trim((string)$in['at']) !== '') {
            $at = Crm::datetime($in['at']);
            if ($at === null) throw new CrmUserError('When did it happen? The date is not valid.');
            if ($at > date('Y-m-d H:i:s', time() + 600)) throw new CrmUserError('Activity is logged for something that already happened. Use a task for the future.');
            $v['created_at'] = $at;
        }

        $db->beginTransaction();
        try {
            if ($cur) {
                if ($v) {
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
                    $db->prepare("UPDATE crm_interactions SET $sets, updated_at = NOW() WHERE id = ? AND company_id = ?")
                       ->execute(array_merge(array_values($v), [$id, $companyId]));
                }
            } else {
                $v += ['company_id' => $companyId, 'account_id' => $accountId, 'created_by' => $userId, 'via' => 'manual'];
                if (!isset($v['created_at'])) $v['created_at'] = date('Y-m-d H:i:s');
                $cols = array_keys($v);
                $db->prepare("INSERT INTO crm_interactions (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")
                   ->execute(array_values($v));
                $id = (int)$db->lastInsertId();
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $task = null;
        $f = is_array($in['followup'] ?? null) ? $in['followup'] : null;
        if ($f && trim((string)($f['title'] ?? '')) !== '') {
            $task = CrmTasks::save($db, $companyId, $userId, $role, $f + ['account_id' => $accountId, 'opportunity_id' => $dealId ?: null,
                                                                           'contact_id' => $v['contact_id'] ?? ($cur['contact_id'] ?? null)]);
        }
        return ['activity' => self::get($db, $companyId, $id), 'task' => $task];
    }

    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        $st = $db->prepare("SELECT * FROM crm_interactions WHERE id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) throw new CrmUserError('This activity no longer exists.');
        if (!Crm::isAdmin($role) && (int)$cur['created_by'] !== $userId) throw new CrmForbidden('Only the person who logged this, or an admin, can delete it.');
        $db->prepare("DELETE FROM crm_interactions WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        Crm::audit($db, $companyId, $userId, 'crm_interaction_delete', 'crm_interaction', $id, ['account_id' => (int)$cur['account_id'], 'type' => $cur['type']]);
    }

    /** Company-wide activity feed. $p: type, owner (me|id), q, before, limit */
    public static function feed(PDO $db, int $companyId, int $userId, array $p): array
    {
        $sql = self::SELECT . " WHERE i.company_id = ? AND a.deleted_at IS NULL";
        $params = [$companyId];
        if (isset(self::META[$p['type'] ?? ''])) { $sql .= " AND i.type = ?"; $params[] = $p['type']; }
        $by = (string)($p['by'] ?? '');
        if ($by === 'me') { $sql .= " AND i.created_by = ?"; $params[] = $userId; }
        elseif ((int)$by > 0) { $sql .= " AND i.created_by = ?"; $params[] = (int)$by; }
        if (in_array($p['account_type'] ?? '', Crm::TYPES, true)) { $sql .= " AND a.type = ?"; $params[] = $p['account_type']; }
        $q = mb_substr(trim((string)($p['q'] ?? '')), 0, 100);
        if ($q !== '') {
            $like = '%' . Crm::likeEscape($q) . '%';
            $sql .= " AND (i.subject LIKE ? OR i.body LIKE ? OR a.name LIKE ?)";
            array_push($params, $like, $like, $like);
        }
        if ($before = Crm::datetime($p['before'] ?? '')) { $sql .= " AND i.created_at < ?"; $params[] = $before; }
        $limit = max(10, min(100, (int)($p['limit'] ?? 40)));
        $st = $db->prepare($sql . " ORDER BY i.created_at DESC, i.id DESC LIMIT " . ($limit + 1));
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return ['rows' => array_map(fn($r) => self::shape($db, $companyId, $r), $rows), 'more' => $more];
    }

    /**
     * The account timeline, newest first (pinned notes are returned separately).
     * $p: kind (all|notes|calls|emails|meetings|deals|documents|tasks), before, limit
     */
    public static function timeline(PDO $db, int $companyId, int $userId, array $account, array $p): array
    {
        $id = (int)$account['id'];
        $kind = (string)($p['kind'] ?? 'all');
        $before = Crm::datetime($p['before'] ?? '') ?? '9999-12-31 23:59:59';
        $limit = max(10, min(100, (int)($p['limit'] ?? 30)));
        $want = fn(string $k) => $kind === 'all' || $kind === $k;
        $items = [];

        if ($want('notes') || $want('calls') || $want('meetings') || $want('emails')) {
            $types = $kind === 'notes' ? ['note', 'issue'] : ($kind === 'calls' ? ['call', 'whatsapp'] : ($kind === 'meetings' ? ['meeting', 'visit'] : ($kind === 'emails' ? ['email'] : array_keys(self::META))));
            $in = implode(',', array_map([$db, 'quote'], $types));
            $st = $db->prepare(self::SELECT . " WHERE i.company_id = ? AND i.account_id = ? AND i.created_at < ? AND i.type IN ($in)
                                              ORDER BY i.created_at DESC, i.id DESC LIMIT $limit");
            $st->execute([$companyId, $id, $before]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = self::shape($db, $companyId, $r);
        }
        if ($want('emails')) {
            try {
                $st = $db->prepare("SELECT DISTINCT e.email_id, e.subject, e.sent_at, e.sender, e.sender_name, e.to_recipients, e.direction, e.thread_id,
                                           e.body_text, ea.user_id AS owner_id
                                      FROM email_links l
                                      JOIN emails e ON e.email_id = l.email_id
                                      JOIN email_accounts ea ON ea.account_id = e.account_id
                                     WHERE (l.company_id = ? OR l.company_id IS NULL) AND e.company_id = ? AND l.linked_type IN ('customer','supplier') AND l.linked_id = ?
                                       AND e.sent_at IS NOT NULL AND e.sent_at < ? AND e.folder NOT IN ('Drafts','Trash')
                                  ORDER BY e.sent_at DESC LIMIT $limit");
                $st->execute([$companyId, $companyId, $id, $before]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
                    $mine = (int)$e['owner_id'] === $userId;
                    $out = $e['direction'] === 'outgoing';
                    $who = $out ? Crm::personName($db, $companyId, (int)$e['owner_id']) : (trim((string)$e['sender_name']) ?: (string)$e['sender']);
                    $items[] = [
                        'kind' => 'email', 'id' => (int)$e['email_id'], 'at' => $e['sent_at'], 'outgoing' => $out, 'mine' => $mine,
                        'subject' => $mine ? (string)($e['subject'] ?: '(no subject)') : '',
                        'snippet' => $mine ? mb_substr(trim(preg_replace('/\s+/', ' ', (string)$e['body_text'])), 0, 220) : '',
                        'who' => $who, 'mailbox' => Crm::personName($db, $companyId, (int)$e['owner_id']),
                        'url' => $mine ? '/mail/?open=' . ((int)$e['thread_id'] > 0 ? 't' . (int)$e['thread_id'] : 'e' . (int)$e['email_id']) : null,
                    ];
                }
            } catch (Throwable $e) {
                // Mail not installed
            }
        }
        if ($want('tasks')) {
            $st = $db->prepare("SELECT id, title, done_at, done_by FROM crm_tasks WHERE company_id = ? AND account_id = ? AND done_at IS NOT NULL AND done_at < ?
                                 ORDER BY done_at DESC LIMIT $limit");
            $st->execute([$companyId, $id, $before]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $items[] = ['kind' => 'task', 'id' => (int)$t['id'], 'at' => $t['done_at'], 'title' => (string)$t['title'],
                            'by' => Crm::personName($db, $companyId, $t['done_by'] !== null ? (int)$t['done_by'] : null)];
            }
        }
        if ($want('deals')) {
            $stages = Crm::stageMap($db, $companyId);
            $st = $db->prepare("SELECT id, title, amount, stage, created_at, stage_changed_at, owner_id, loss_reason, created_by FROM crm_opportunities
                                 WHERE company_id = ? AND account_id = ? ORDER BY created_at DESC LIMIT 200");
            $st->execute([$companyId, $id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
                if ($o['created_at'] < $before) {
                    $items[] = ['kind' => 'deal', 'event' => 'opened', 'id' => (int)$o['id'], 'at' => $o['created_at'], 'title' => (string)$o['title'],
                                'amount' => (float)$o['amount'], 'by' => Crm::personName($db, $companyId, (int)($o['created_by'] ?: $o['owner_id']))];
                }
                if (in_array($o['stage'], ['won', 'lost'], true) && $o['stage_changed_at'] && $o['stage_changed_at'] < $before) {
                    $items[] = ['kind' => 'deal', 'event' => $o['stage'], 'id' => (int)$o['id'], 'at' => $o['stage_changed_at'], 'title' => (string)$o['title'],
                                'amount' => (float)$o['amount'], 'label' => $stages[$o['stage']]['label'] ?? ucfirst($o['stage']),
                                'reason' => (string)($o['loss_reason'] ?? ''), 'by' => Crm::personName($db, $companyId, (int)$o['owner_id'])];
                }
            }
        }
        if ($want('documents')) {
            $sym = 'R';
            try {
                $st = $db->prepare("SELECT id, quote_number, total, currency, created_at, accepted_at, status FROM quotes WHERE company_id = ? AND customer_id = ? ORDER BY created_at DESC LIMIT 100");
                $st->execute([$companyId, $id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) {
                    $url = '/qi/view.php?type=quote&id=' . (int)$q['id'];
                    if ($q['created_at'] < $before) $items[] = ['kind' => 'doc', 'doc' => 'quote', 'event' => 'created', 'id' => (int)$q['id'], 'at' => $q['created_at'], 'number' => $q['quote_number'], 'amount' => (float)$q['total'], 'currency' => $q['currency'] ?: 'ZAR', 'url' => $url];
                    if ($q['accepted_at'] && $q['accepted_at'] < $before) $items[] = ['kind' => 'doc', 'doc' => 'quote', 'event' => 'accepted', 'id' => (int)$q['id'], 'at' => $q['accepted_at'], 'number' => $q['quote_number'], 'amount' => (float)$q['total'], 'currency' => $q['currency'] ?: 'ZAR', 'url' => $url];
                }
            } catch (Throwable $e) { }
            try {
                $del = isset(CrmSchema::columns($db, 'invoices')['deleted_at']) ? ' AND deleted_at IS NULL' : '';
                $st = $db->prepare("SELECT id, invoice_number, total, currency, created_at, paid_at, status FROM invoices WHERE company_id = ? AND customer_id = ?$del ORDER BY created_at DESC LIMIT 100");
                $st->execute([$companyId, $id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) {
                    $url = '/qi/view.php?type=invoice&id=' . (int)$q['id'];
                    if ($q['created_at'] < $before) $items[] = ['kind' => 'doc', 'doc' => 'invoice', 'event' => 'created', 'id' => (int)$q['id'], 'at' => $q['created_at'], 'number' => $q['invoice_number'], 'amount' => (float)$q['total'], 'currency' => $q['currency'] ?: 'ZAR', 'url' => $url];
                    if ($q['paid_at'] && $q['status'] === 'paid' && $q['paid_at'] < $before) $items[] = ['kind' => 'doc', 'doc' => 'invoice', 'event' => 'paid', 'id' => (int)$q['id'], 'at' => $q['paid_at'], 'number' => $q['invoice_number'], 'amount' => (float)$q['total'], 'currency' => $q['currency'] ?: 'ZAR', 'url' => $url];
                }
            } catch (Throwable $e) { }
            try {
                $st = $db->prepare("SELECT id, po_number, total, created_at FROM purchase_orders WHERE company_id = ? AND supplier_id = ? AND created_at < ? ORDER BY created_at DESC LIMIT 100");
                $st->execute([$companyId, $id, $before]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) $items[] = ['kind' => 'doc', 'doc' => 'po', 'event' => 'created', 'id' => (int)$q['id'], 'at' => $q['created_at'], 'number' => $q['po_number'], 'amount' => (float)$q['total'], 'currency' => 'ZAR', 'url' => '/procurement/po_view.php?id=' . (int)$q['id']];
            } catch (Throwable $e) { }
            try {
                $st = $db->prepare("SELECT d.id, d.created_at, t.name AS type_name FROM crm_compliance_docs d JOIN crm_compliance_types t ON t.id = d.type_id
                                     WHERE d.company_id = ? AND d.account_id = ? AND d.created_at < ? ORDER BY d.created_at DESC LIMIT 50");
                $st->execute([$companyId, $id, $before]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $items[] = ['kind' => 'doc', 'doc' => 'compliance', 'event' => 'uploaded', 'id' => (int)$d['id'], 'at' => $d['created_at'], 'number' => (string)$d['type_name'], 'amount' => null, 'currency' => 'ZAR', 'url' => null];
            } catch (Throwable $e) { }
        }
        if ($kind === 'all' && $account['created_at'] < $before) {
            $items[] = ['kind' => 'created', 'id' => $id, 'at' => $account['created_at'], 'by' => $account['created_by_name'] ?? ''];
        }
        usort($items, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
        $more = count($items) > $limit;
        $items = array_slice($items, 0, $limit);

        $pinned = [];
        if ($before === '9999-12-31 23:59:59') {
            $st = $db->prepare(self::SELECT . " WHERE i.company_id = ? AND i.account_id = ? AND i.pinned = 1 ORDER BY i.created_at DESC LIMIT 10");
            $st->execute([$companyId, $id]);
            $pinned = array_map(fn($r) => self::shape($db, $companyId, $r), $st->fetchAll(PDO::FETCH_ASSOC));
        }
        return ['items' => $items, 'pinned' => $pinned, 'more' => $more, 'next' => $items ? end($items)['at'] : null];
    }

    /** A deal's activity (interactions logged against it), newest first. */
    public static function forDeal(PDO $db, int $companyId, int $dealId): array
    {
        $st = $db->prepare(self::SELECT . " WHERE i.company_id = ? AND i.opportunity_id = ? ORDER BY i.created_at DESC LIMIT 100");
        $st->execute([$companyId, $dealId]);
        return array_map(fn($r) => self::shape($db, $companyId, $r), $st->fetchAll(PDO::FETCH_ASSOC));
    }
}
