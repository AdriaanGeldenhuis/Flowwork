<?php
// POST /finances/api/prefs.php — the person's own layout:
//   {density, text, accent, landing, money, period, overview_off[]} (any subset)
//   {op: view_add, name, route, query} · {op: view_remove, id} · {op: recent, code} · {op: recent_report, key} · {op: reset}
require_once __DIR__ . '/_bootstrap.php';

fin_require_post();
$in = fin_input();
$cur = Fin::readPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID);
switch (fin_op()) {
    case 'reset':
        $DB->prepare("DELETE FROM fin_prefs WHERE company_id = ? AND user_id = ?")->execute([$FIN_COMPANY_ID, $FIN_USER_ID]);
        fin_json(['prefs' => Fin::userPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID)]);
    case 'view_add':
        $views = (array)($cur['views'] ?? []);
        if (count($views) >= 20) throw new FinUserError('You can save up to 20 views. Remove one first.');
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 40);
        if ($name === '') throw new FinUserError('Give the view a name.');
        $route = (string)($in['route'] ?? '');
        if (!isset(Fin::ROUTES[$route])) throw new FinUserError('That page cannot be saved as a view.');
        $views[] = ['id' => bin2hex(random_bytes(4)), 'name' => $name, 'route' => $route, 'query' => mb_substr((string)($in['query'] ?? ''), 0, 500)];
        Fin::writePrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID, ['views' => $views]);
        fin_json(['prefs' => Fin::userPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID)]);
    case 'view_remove':
        $id = (string)($in['id'] ?? '');
        Fin::writePrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID, ['views' => array_values(array_filter((array)($cur['views'] ?? []), fn($v) => ($v['id'] ?? '') !== $id))]);
        fin_json(['prefs' => Fin::userPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID)]);
    case 'recent':
        $code = (string)($in['code'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $code)) throw new FinUserError('Unknown account.');
        $recent = array_values(array_diff((array)($cur['recent'] ?? []), [$code]));
        array_unshift($recent, $code);
        Fin::writePrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID, ['recent' => array_slice($recent, 0, 8)]);
        fin_json(['prefs' => Fin::userPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID)]);
    case 'recent_report':
        $key = (string)($in['key'] ?? '');
        if (!preg_match('/^[a-z_]{1,40}$/', $key)) throw new FinUserError('Unknown report.');
        $list = array_values(array_diff((array)($cur['recent_reports'] ?? []), [$key]));
        array_unshift($list, $key);
        Fin::writePrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID, ['recent_reports' => array_slice($list, 0, 6)]);
        fin_json(['prefs' => Fin::userPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID)]);
}
$changes = [];
foreach (['density', 'text', 'accent', 'landing', 'money', 'period'] as $k) if (array_key_exists($k, $in)) $changes[$k] = is_scalar($in[$k]) ? (string)$in[$k] : '';
if (array_key_exists('overview_off', $in)) $changes['overview_off'] = array_values(array_intersect((array)$in['overview_off'], array_keys(Fin::OVERVIEW_PANELS)));
if (!$changes) throw new FinUserError('Nothing to save.');
Fin::writePrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $changes);
fin_json(['prefs' => Fin::userPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID)]);
