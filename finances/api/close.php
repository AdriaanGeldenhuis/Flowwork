<?php
// /finances/api/close.php — period locks, the year-end close and the health check.
//   GET  ?op=locks · ?op=years · ?op=preview&start= · ?op=health
//   POST {op: lock, date, reason} · {op: unlock, id, why} (admin)
//        {op: close_year, start} (admin) · {op: reopen_year, start, why} (admin)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinClose.php';

$C = $FIN_COMPANY_ID;
if (!fin_is_post()) {
    switch ($_GET['op'] ?? 'locks') {
        case 'locks': fin_json(FinClose::locks($DB, $C));
        case 'years': fin_json(['years' => FinClose::years($DB, $C)]);
        case 'preview': fin_json(FinClose::preview($DB, $C, fin_str($_GET['start'] ?? '', 10)));
        case 'health':
            fin_rate_limit('health', 30, 60);
            fin_json(FinClose::health($DB, $C));
    }
    fin_error('Unknown action');
}

fin_require_writer();
$in = fin_input();
switch (fin_op()) {
    case 'lock':
        FinClose::lock($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_str($in['date'] ?? '', 10), (string)($in['reason'] ?? ''));
        fin_json(FinClose::locks($DB, $C));
    case 'unlock':
        FinClose::unlock($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_int($in['id'] ?? 0), (string)($in['why'] ?? ''));
        fin_json(FinClose::locks($DB, $C));
    case 'close_year':
        fin_rate_limit('year_end', 5, 300);
        $jid = FinClose::closeYear($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_str($in['start'] ?? '', 10));
        fin_json(['journal_id' => $jid, 'years' => FinClose::years($DB, $C)]);
    case 'reopen_year':
        fin_rate_limit('year_end', 5, 300);
        $rid = FinClose::reopenYear($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_str($in['start'] ?? '', 10), (string)($in['why'] ?? ''));
        fin_json(['reversal_id' => $rid, 'years' => FinClose::years($DB, $C)]);
}
fin_error('Unknown action');
