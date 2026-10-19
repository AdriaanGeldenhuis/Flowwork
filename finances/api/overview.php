<?php
// GET /finances/api/overview.php — the overview panels (see FinOverview).
//   POST {op: snooze, key, days}   hide an attention item for a while (1–90 days)
//   POST {op: unsnooze_all}         show every snoozed item again
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinOverview.php';

if (fin_is_post()) {
    $in = fin_input();
    $prefs = Fin::userPrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID);
    $snoozed = $prefs['snoozed'];
    if (fin_op() === 'snooze') {
        $key = (string)($in['key'] ?? '');
        if (!preg_match('/^[a-z0-9_:.-]{1,80}$/i', $key)) throw new FinUserError('Unknown item.');
        $days = Fin::clampInt($in['days'] ?? 7, 1, 90);
        $snoozed[$key] = date('Y-m-d', strtotime("+$days days"));
        if (count($snoozed) > 100) $snoozed = array_slice($snoozed, -100, null, true);
    } elseif (fin_op() === 'unsnooze_all') {
        $snoozed = [];
    } else {
        fin_error('Unknown action');
    }
    Fin::writePrefs($DB, $FIN_COMPANY_ID, $FIN_USER_ID, ['snoozed' => $snoozed ?: null]);
}
fin_json(FinOverview::build($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE));
