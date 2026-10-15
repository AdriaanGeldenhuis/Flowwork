<?php
// /mail/api/rule.php — inbox rules.
//   GET  → {rules:[{rule_id,name,priority,is_active,stop_processing,shared,match,items,actions}]}
//   POST {op:"save", rule_id?, name, priority, is_active, stop_processing, match, items:[{field,operator,value}], actions:[{type,value}]}
//   POST {op:"delete", rule_id}
//   POST {op:"run", rule_id?}   apply to the last 30 days of Inbox
//   POST {op:"toggle", rule_id, is_active}
require_once __DIR__ . '/_bootstrap.php';

$list = function () use ($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID): array {
    $st = $DB->prepare("SELECT * FROM email_rules WHERE company_id = ? AND (user_id = ? OR user_id IS NULL) ORDER BY priority, rule_id");
    $st->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = RulesEngine::decode($r);
        $out[] = ['rule_id' => (int)$r['rule_id'], 'name' => $r['name'], 'priority' => (int)$r['priority'],
                  'is_active' => (int)$r['is_active'] === 1, 'stop_processing' => (int)$r['stop_processing'] === 1,
                  'shared' => $r['user_id'] === null, 'match' => $d['match'], 'items' => $d['items'], 'actions' => $d['actions']];
    }
    return $out;
};
$owned = function (int $id) use ($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID, $MAIL_ROLE): array {
    $st = $DB->prepare("SELECT * FROM email_rules WHERE rule_id = ? AND company_id = ? AND (user_id = ? OR user_id IS NULL)");
    $st->execute([$id, $MAIL_COMPANY_ID, $MAIL_USER_ID]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) mail_error('Rule not found', 404);
    if ($r['user_id'] === null && !in_array($MAIL_ROLE, ['admin', 'owner'], true)) mail_error('Only an admin can change a company-wide rule', 403);
    return $r;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') mail_json(['rules' => $list()]);

mail_require_post();
$in = mail_input();
$op = mail_str($in, 'op', 10);

switch ($op) {
    case 'save':
        $name = mail_str($in, 'name', 120);
        if ($name === '') mail_error('Give the rule a name');
        $items = [];
        foreach ((array)($in['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $f = (string)($it['field'] ?? '');
            $o = (string)($it['operator'] ?? '');
            $v = mb_substr(trim((string)($it['value'] ?? '')), 0, 200);
            if (!in_array($f, RulesEngine::FIELDS, true) || !in_array($o, RulesEngine::OPERATORS, true) || $v === '') continue;
            $items[] = ['field' => $f, 'operator' => $o, 'value' => $v];
        }
        if (!$items) mail_error('Add at least one condition');
        $actions = [];
        foreach ((array)($in['actions'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $t = (string)($a['type'] ?? '');
            $v = mb_substr(trim((string)($a['value'] ?? '')), 0, 64);
            if (!in_array($t, RulesEngine::ACTION_TYPES, true)) continue;
            if ($t === 'move' && !in_array($v, RulesEngine::MOVE_TARGETS, true)) mail_error('Choose a folder to move to');
            if ($t === 'label') {
                if ($v === '') mail_error('Choose a label');
                if (ctype_digit($v) && !MailRepo::labelAllowed($DB, $MAIL_COMPANY_ID, (int)$v)) mail_error('Label not found');
            }
            $actions[] = ['type' => $t, 'value' => $v];
        }
        if (!$actions) mail_error('Add at least one action');
        $match = ($in['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $priority = max(1, min(999, (int)($in['priority'] ?? 100)));
        $cond = json_encode(['match' => $match, 'items' => $items]);
        $act = json_encode($actions);
        $id = (int)($in['rule_id'] ?? 0);
        if ($id) {
            $owned($id);
            $DB->prepare("UPDATE email_rules SET name = ?, priority = ?, conditions_json = ?, actions_json = ?, stop_processing = ?,
                                 is_active = ?, updated_at = NOW() WHERE rule_id = ? AND company_id = ?")
               ->execute([$name, $priority, $cond, $act, mail_bool($in, 'stop_processing') ? 1 : 0, mail_bool($in, 'is_active', true) ? 1 : 0, $id, $MAIL_COMPANY_ID]);
        } else {
            $DB->prepare("INSERT INTO email_rules (company_id, user_id, name, priority, conditions_json, actions_json, stop_processing, is_active, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
               ->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID, $name, $priority, $cond, $act, mail_bool($in, 'stop_processing') ? 1 : 0, mail_bool($in, 'is_active', true) ? 1 : 0]);
        }
        mail_json(['rules' => $list()]);

    case 'toggle':
        $r = $owned((int)($in['rule_id'] ?? 0));
        $DB->prepare("UPDATE email_rules SET is_active = ? WHERE rule_id = ?")->execute([mail_bool($in, 'is_active') ? 1 : 0, (int)$r['rule_id']]);
        mail_json(['rules' => $list()]);

    case 'delete':
        $r = $owned((int)($in['rule_id'] ?? 0));
        $DB->prepare("DELETE FROM email_rules WHERE rule_id = ? AND company_id = ?")->execute([(int)$r['rule_id'], $MAIL_COMPANY_ID]);
        mail_json(['rules' => $list()]);

    case 'run':
        mail_rate_limit('rules_run', 6, 300);
        $rules = RulesEngine::loadRules($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID);
        if (!empty($in['rule_id'])) {
            $rules = array_values(array_filter($rules, fn($r) => (int)$r['rule_id'] === (int)$in['rule_id']));
            if (!$rules) mail_error('Rule not found or inactive', 404);
        }
        $st = $DB->prepare("SELECT e.email_id FROM emails e JOIN email_accounts a ON a.account_id = e.account_id
                             WHERE a.company_id = ? AND a.user_id = ? AND e.folder = 'INBOX' AND e.direction = 'incoming'
                               AND e.sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          ORDER BY e.email_id DESC LIMIT 1000");
        $st->execute([$MAIL_COMPANY_ID, $MAIL_USER_ID]);
        $matched = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (RulesEngine::applyToEmail($DB, (int)$id, $rules, false)) $matched++;
        }
        mail_json(['matched' => $matched, 'counts' => MailRepo::counts($DB, $MAIL_COMPANY_ID, $MAIL_USER_ID)]);
}
mail_error('Unknown operation');
