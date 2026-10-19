<?php
// /finances/api/journals.php — the journal register and manual journals.
//   GET  ?status=&source=&from=&to=&q=&account=&min=&max=&page=&sort=&dir=   the register
//   GET  ?id=                                                               one journal (lines, trail, files, what you may do)
//   GET  …&format=csv                                                       the register as CSV
//   GET  ?op=duplicate&id=[&swap=1]                                         lines to start a new draft from
//   POST {op: save, id?, date, reference?, description, lines[]}            create or edit a draft
//   POST {op: approve|unapprove|post|delete, id}
//   POST {op: reverse, id, reason, date?}
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinJournals.php';

if (!fin_is_post()) {
    if (($_GET['op'] ?? '') === 'duplicate') fin_json(FinJournals::duplicateLines($DB, $FIN_COMPANY_ID, fin_int($_GET['id'] ?? 0), !empty($_GET['swap'])));
    if (isset($_GET['id'])) fin_json(FinJournals::get($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, fin_int($_GET['id'])));
    if (($_GET['format'] ?? '') === 'csv') {
        require_once __DIR__ . '/../lib/FinExport.php';
        $rows = [];
        $f = $_GET;
        $f['per'] = 200;
        for ($page = 1; $page <= 250; $page++) {
            $f['page'] = $page;
            $d = FinJournals::list($DB, $FIN_COMPANY_ID, $f);
            foreach ($d['rows'] as $r) $rows[] = [$r['date'], $r['reference'], $r['description'], FinJournals::SOURCES[$r['source']] ?? $r['source'], $r['status'],
                                                 number_format($r['total'], 2, '.', ''), implode(' ', $r['codes']), $r['created_by'], $r['id']];
            if ($page * 200 >= $d['total']) break;
        }
        FinExport::csv('journals-' . date('Y-m-d') . '.csv', ['Date', 'Reference', 'Description', 'Source', 'Status', 'Amount', 'Accounts', 'Prepared by', 'Journal'], $rows);
    }
    fin_json(FinJournals::list($DB, $FIN_COMPANY_ID, $_GET));
}

fin_require_writer();
$in = fin_input();
$id = fin_int($in['id'] ?? 0);
switch (fin_op()) {
    case 'save':
        $id = FinJournals::save($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $in);
        $then = (string)($in['then'] ?? '');
        $FIN_THEN_VERB = $then === 'approve' ? 'approved' : 'posted';
        if ($then === 'approve') fin_then($id, fn() => FinJournals::approve($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id));
        if ($then === 'post') fin_then($id, fn() => FinJournals::post($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id));
        break;
    case 'approve':
        FinJournals::approve($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id);
        break;
    case 'unapprove':
        FinJournals::unapprove($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id);
        break;
    case 'post':
        FinJournals::post($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id);
        break;
    case 'delete':
        FinJournals::delete($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id);
        fin_json(['deleted' => $id]);
    case 'reverse':
        $rev = FinJournals::reverse($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id, (string)($in['reason'] ?? ''), fin_str($in['date'] ?? '', 10) ?: null);
        fin_json(['reversal_id' => $rev] + FinJournals::get($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id));
    default:
        fin_error('Unknown action');
}
fin_json(FinJournals::get($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE, $id));
