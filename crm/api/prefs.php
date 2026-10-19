<?php
// POST /crm/api/prefs.php — the person's own layout:
//   {density, text, accent, landing, list_view{customers, suppliers}, columns{customers[], suppliers[]},
//    pipeline_view, pipeline_mine} (any subset) · {op: view_add, name, route, query} · {op: view_remove, id}
//   {op: reset}   back to the defaults
require_once __DIR__ . '/_bootstrap.php';

crm_require_post();
$in = crm_input();
$cur = Crm::readPrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID);
switch (crm_op()) {
    case 'reset':
        $DB->prepare("DELETE FROM crm_prefs WHERE company_id = ? AND user_id = ?")->execute([$CRM_COMPANY_ID, $CRM_USER_ID]);
        crm_json(['prefs' => Crm::userPrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID)]);
    case 'view_add':
        $views = (array)($cur['views'] ?? []);
        if (count($views) >= 20) throw new CrmUserError('You can save up to 20 views. Remove one first.');
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 40);
        if ($name === '') throw new CrmUserError('Give the view a name.');
        $views[] = ['id' => bin2hex(random_bytes(4)), 'name' => $name, 'route' => (string)($in['route'] ?? ''), 'query' => mb_substr((string)($in['query'] ?? ''), 0, 500)];
        Crm::writePrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID, ['views' => $views]);
        crm_json(['prefs' => Crm::userPrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID)]);
    case 'view_remove':
        $id = (string)($in['id'] ?? '');
        Crm::writePrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID, ['views' => array_values(array_filter((array)($cur['views'] ?? []), fn($v) => ($v['id'] ?? '') !== $id))]);
        crm_json(['prefs' => Crm::userPrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID)]);
}
$changes = [];
foreach (['density', 'text', 'accent', 'landing', 'pipeline_view'] as $k) if (array_key_exists($k, $in)) $changes[$k] = is_scalar($in[$k]) ? (string)$in[$k] : '';
if (array_key_exists('pipeline_mine', $in)) $changes['pipeline_mine'] = !empty($in['pipeline_mine']);
foreach (['list_view', 'columns'] as $k) {
    if (isset($in[$k]) && is_array($in[$k])) $changes[$k] = array_merge((array)($cur[$k] ?? []), $in[$k]);
}
if (!$changes) throw new CrmUserError('Nothing to save.');
Crm::writePrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $changes);
crm_json(['prefs' => Crm::userPrefs($DB, $CRM_COMPANY_ID, $CRM_USER_ID)]);
