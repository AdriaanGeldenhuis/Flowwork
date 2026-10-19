<?php
// /crm/api/settings.php — "Make it yours": the company's CRM settings (admin to change).
//   GET   everything the settings page shows
//   POST  {op: look, accent}                         · {op: labels, labels{}}           · {op: modules, modules{}}
//         {op: statuses, statuses{key:{label,color}}} · {op: activity, activity{}}       · {op: layout, overview[], overview_off[], account_tabs[], account_tabs_off[]}
//         {op: thresholds, cold_days, stale_days, loss_reasons[]}
//         {op: stages, stages[]}                     · {op: rules, …}                   · {op: templates, templates{key:{subject, body}}}
//         {op: field_save, …} · {op: field_delete, id, values} · {op: field_restore, id} · {op: fields_order, ids[]}
//         {op: lookup_save, list: industries|regions, id?, name} · {op: lookup_delete, list, id}
//         {op: reset, section}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmBoot.php';
require_once __DIR__ . '/../lib/CrmDeals.php';
require_once __DIR__ . '/../lib/CrmCompliance.php';
require_once __DIR__ . '/../lib/CrmMail.php';
require_once __DIR__ . '/../../qi/lib/Currencies.php';

$payload = function () use ($DB, $CRM_COMPANY_ID): array {
    Crm::flush();
    CrmFields::flush();
    $usage = [];
    foreach (['industries' => 'industry_id', 'regions' => 'region_id'] as $list => $col) {
        $st = $DB->prepare("SELECT $col AS id, COUNT(*) n FROM crm_accounts WHERE company_id = ? AND $col IS NOT NULL AND deleted_at IS NULL GROUP BY $col");
        $st->execute([$CRM_COMPANY_ID]);
        $usage[$list] = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }
    $deals = [];
    $st = $DB->prepare("SELECT stage, COUNT(*) n FROM crm_opportunities WHERE company_id = ? GROUP BY stage");
    $st->execute([$CRM_COMPANY_ID]);
    $deals = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    $fieldUse = [];
    $st = $DB->prepare("SELECT field_id, COUNT(*) n FROM crm_custom_values WHERE company_id = ? GROUP BY field_id");
    $st->execute([$CRM_COMPANY_ID]);
    $fieldUse = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    $rules = Crm::rules($DB, $CRM_COMPANY_ID);
    return [
        'config' => Crm::config($DB, $CRM_COMPANY_ID),
        'defaults' => Crm::COMPANY_DEFAULTS,
        'rules' => $rules,
        'stages' => Crm::stages($DB, $CRM_COMPANY_ID),
        'stage_deals' => $deals,
        'fields' => CrmFields::defs($DB, $CRM_COMPANY_ID, null, true),
        'field_use' => $fieldUse,
        'field_types' => CrmFields::TYPES,
        'lookups' => CrmAccounts::lookups($DB, $CRM_COMPANY_ID),
        'lookup_use' => $usage,
        'tags' => CrmAccounts::allTags($DB, $CRM_COMPANY_ID),
        'compliance' => ['types' => CrmCompliance::types($DB, $CRM_COMPANY_ID), 'policy' => CrmCompliance::policy($DB, $CRM_COMPANY_ID)],
        'templates' => array_values(CrmMail::builtins($DB, $CRM_COMPANY_ID)),
        'template_defaults' => CrmMail::BUILTIN,
        'currencies' => array_map(fn($c) => $c['name'], Currencies::all()),
        'options' => ['overview' => Crm::OVERVIEW_PANELS, 'account_tabs' => Crm::ACCOUNT_TABS, 'requirable' => Crm::REQUIRABLE, 'activity' => CrmActivity::META],
    ];
};

if (!crm_is_post()) crm_json($payload());

crm_require_admin();
$in = crm_input();
$op = crm_op();
$save = function (array $changes) use ($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $op) {
    Crm::writePrefs($DB, $CRM_COMPANY_ID, 0, $changes);
    Crm::audit($DB, $CRM_COMPANY_ID, $CRM_USER_ID, 'update', 'crm_settings', $CRM_COMPANY_ID, ['section' => $op, 'keys' => array_keys($changes)]);
};

switch ($op) {
    case 'look':
        $accent = Crm::color($in['accent'] ?? '');
        $save(['accent' => $accent ?: null]);
        break;
    case 'labels':
        $labels = [];
        foreach (Crm::COMPANY_DEFAULTS['labels'] as $k => $def) {
            $v = mb_substr(trim((string)($in['labels'][$k] ?? '')), 0, 30);
            if ($v !== '' && $v !== $def) $labels[$k] = $v;
        }
        $save(['labels' => $labels ?: null]);
        break;
    case 'modules':
        $mods = [];
        foreach (Crm::COMPANY_DEFAULTS['modules'] as $k => $def) $mods[$k] = !empty($in['modules'][$k]);
        if (!$mods['customers'] && !$mods['suppliers']) throw new CrmUserError('Keep customers or suppliers switched on.');
        $save(['modules' => $mods]);
        break;
    case 'statuses':
        $out = [];
        foreach (Crm::COMPANY_DEFAULTS['statuses'] as $k => $def) {
            $s = (array)($in['statuses'][$k] ?? []);
            $out[$k] = ['label' => mb_substr(trim((string)($s['label'] ?? '')), 0, 30) ?: $def['label'], 'color' => Crm::color($s['color'] ?? '') ?: $def['color']];
        }
        $save(['statuses' => $out]);
        break;
    case 'activity':
        $act = [];
        foreach (Crm::COMPANY_DEFAULTS['activity'] as $k => $def) $act[$k] = !empty($in['activity'][$k]);
        if (!array_filter($act)) throw new CrmUserError('Keep at least one kind of activity.');
        $save(['activity' => $act]);
        break;
    case 'layout':
        $save([
            'overview' => array_values(array_intersect((array)($in['overview'] ?? []), array_keys(Crm::OVERVIEW_PANELS))),
            'overview_off' => array_values(array_intersect((array)($in['overview_off'] ?? []), array_keys(Crm::OVERVIEW_PANELS))),
            'account_tabs' => array_values(array_intersect((array)($in['account_tabs'] ?? []), array_keys(Crm::ACCOUNT_TABS))),
            'account_tabs_off' => array_values(array_diff(array_intersect((array)($in['account_tabs_off'] ?? []), array_keys(Crm::ACCOUNT_TABS)), ['activity'])),
        ]);
        break;
    case 'thresholds':
        $reasons = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 60), (array)($in['loss_reasons'] ?? [])))));
        $save(['cold_days' => max(7, min(365, (int)($in['cold_days'] ?? 60))), 'stale_days' => max(3, min(180, (int)($in['stale_days'] ?? 21))),
               'loss_reasons' => array_slice($reasons, 0, 20) ?: null]);
        break;
    case 'stages':
        CrmDeals::saveStages($DB, $CRM_COMPANY_ID, $CRM_USER_ID, (array)($in['stages'] ?? []));
        break;
    case 'rules':
        $req = array_values(array_intersect((array)($in['required'] ?? []), array_keys(Crm::REQUIRABLE)));
        $save(['required' => $req]);
        Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_require_vat_number', in_array('vat_no', $req, true) ? '1' : '0');
        Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_require_reg_number', in_array('reg_no', $req, true) ? '1' : '0');
        foreach (['customer', 'supplier'] as $t) {
            if (isset($in['default_status'][$t])) {
                if (!in_array($in['default_status'][$t], Crm::STATUSES, true)) throw new CrmUserError('Choose a default status.');
                Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_default_' . $t . '_status', (string)$in['default_status'][$t]);
            }
        }
        if (array_key_exists('duplicate_check', $in)) Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_enable_duplicate_check', !empty($in['duplicate_check']) ? '1' : '0');
        if (array_key_exists('duplicate_threshold', $in)) {
            $t = (float)$in['duplicate_threshold'];
            if ($t < 0.6 || $t > 0.98) throw new CrmUserError('The duplicate sensitivity must be between 60% and 98%.');
            Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_duplicate_threshold', number_format($t, 2, '.', ''));
        }
        if (array_key_exists('tags', $in)) Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_enable_tags', !empty($in['tags']) ? '1' : '0');
        if (array_key_exists('phone_format', $in)) Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_phone_format', $in['phone_format'] === 'national' ? 'national' : 'international');
        if (array_key_exists('default_currency', $in)) {
            $c = strtoupper(trim((string)$in['default_currency']));
            if ($c !== '' && !Currencies::isValid($c)) throw new CrmUserError('Choose a currency from the list.');
            Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_default_currency', $c);
        }
        Crm::audit($DB, $CRM_COMPANY_ID, $CRM_USER_ID, 'update', 'crm_settings', $CRM_COMPANY_ID, ['section' => 'rules']);
        break;
    case 'templates':
        foreach (CrmMail::BUILTIN as $k => $def) {
            if (!isset($in['templates'][$k])) continue;
            $t = (array)$in['templates'][$k];
            $subject = mb_substr(trim((string)($t['subject'] ?? '')), 0, 200);
            $body = mb_substr(trim((string)($t['body'] ?? '')), 0, 10000);
            $same = ($subject === '' || $subject === $def['subject']) && ($body === '' || $body === $def['body']);
            Crm::setRule($DB, $CRM_COMPANY_ID, 'crm_email_template_' . $k, $same ? '' : json_encode(['subject' => $subject, 'body' => $body], JSON_UNESCAPED_UNICODE));
        }
        break;
    case 'field_save':
        CrmFields::saveDef($DB, $CRM_COMPANY_ID, $in);
        break;
    case 'field_delete':
        CrmFields::removeDef($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0), !empty($in['values']));
        break;
    case 'field_restore':
        CrmFields::restoreDef($DB, $CRM_COMPANY_ID, crm_int($in['id'] ?? 0));
        break;
    case 'fields_order':
        CrmFields::reorder($DB, $CRM_COMPANY_ID, (array)($in['ids'] ?? []));
        break;
    case 'lookup_save':
    case 'lookup_delete':
        $table = ['industries' => 'crm_industries', 'regions' => 'crm_regions'][$in['list'] ?? ''] ?? null;
        if (!$table) throw new CrmUserError('Unknown list.');
        $col = $table === 'crm_industries' ? 'industry_id' : 'region_id';
        $id = crm_int($in['id'] ?? 0);
        if ($id > 0) {
            $st = $DB->prepare("SELECT company_id FROM `$table` WHERE id = ?");
            $st->execute([$id]);
            $owner = $st->fetchColumn();
            if ($owner === false) throw new CrmUserError('Not found.');
            if ((int)$owner !== $CRM_COMPANY_ID) throw new CrmUserError('Built-in entries cannot be changed; add your own instead.');
        }
        if ($op === 'lookup_delete') {
            $n = $DB->prepare("SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND $col = ?");
            $n->execute([$CRM_COMPANY_ID, $id]);
            if ((int)$n->fetchColumn() > 0) throw new CrmUserError('Accounts still use this. Change them first.');
            $DB->prepare("DELETE FROM `$table` WHERE id = ? AND company_id = ?")->execute([$id, $CRM_COMPANY_ID]);
            break;
        }
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
        if ($name === '') throw new CrmUserError('Give it a name.');
        $dup = $DB->prepare("SELECT id FROM `$table` WHERE name = ? AND (company_id IS NULL OR company_id = ?) AND id <> ?");
        $dup->execute([$name, $CRM_COMPANY_ID, $id]);
        if ($dup->fetchColumn()) throw new CrmUserError('That is already on the list.');
        if ($id > 0) $DB->prepare("UPDATE `$table` SET name = ? WHERE id = ? AND company_id = ?")->execute([$name, $id, $CRM_COMPANY_ID]);
        else $DB->prepare("INSERT INTO `$table` (name, company_id) VALUES (?, ?)")->execute([$name, $CRM_COMPANY_ID]);
        break;
    case 'reset':
        $section = (string)($in['section'] ?? '');
        $map = ['look' => ['accent'], 'labels' => ['labels'], 'modules' => ['modules'], 'statuses' => ['statuses'], 'activity' => ['activity'],
                'layout' => ['overview', 'overview_off', 'account_tabs', 'account_tabs_off'], 'thresholds' => ['cold_days', 'stale_days', 'loss_reasons']];
        if ($section === 'stages') {
            $DB->prepare("DELETE FROM crm_pipeline_stages WHERE company_id = ?")->execute([$CRM_COMPANY_ID]);
            // deals on custom stages go to the first built-in stage
            $keys = implode(',', array_map([$DB, 'quote'], array_column(Crm::DEFAULT_STAGES, 'key')));
            $DB->prepare("UPDATE crm_opportunities SET stage = 'prospect', stage_changed_at = NOW() WHERE company_id = ? AND stage NOT IN ($keys)")->execute([$CRM_COMPANY_ID]);
        } elseif (isset($map[$section])) {
            $save(array_fill_keys($map[$section], null));
        } else {
            throw new CrmUserError('Unknown section.');
        }
        break;
    default:
        crm_error('Unknown action');
}
crm_json($payload());
