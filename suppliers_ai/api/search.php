<?php
// /suppliers_ai/api/search.php — finding suppliers.
//   GET  op=parse&text=          what the words say (no AI, nothing saved) — for the chips as you type
//   POST op=run {text, location, radius, sources{crm,google,ai}, compliant_only}   search and keep it
//   GET  op=get&id=              a past search, as found
//   GET  op=history&mine=&q=&page=
//   GET  op=jump&q=              the top bar: requests, suppliers, searches
require_once __DIR__ . '/_bootstrap.php';

switch (sa_op()) {
    case 'parse':
        $s = SaKit::settings($DB, $SA_COMPANY_ID);
        $read = SaParse::read((string)($_GET['text'] ?? ''), $s['rules']);
        sa_json(['read' => SaSearch::readView($read), 'town' => (string)SaKit::company($DB, $SA_COMPANY_ID)['town']]);
    case 'run':
        sa_post_only();
        sa_rate_limit('sa-search', 40, 300);
        $in = sa_input();
        sa_json(SaSearch::run($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, (string)($in['text'] ?? ''), [
            'location' => $in['location'] ?? '', 'radius' => $in['radius'] ?? null, 'sources' => is_array($in['sources'] ?? null) ? $in['sources'] : null,
        ] + (array_key_exists('compliant_only', $in) ? ['compliant_only' => !empty($in['compliant_only'])] : [])));
    case 'get':
        sa_json(SaSearch::get($DB, $SA_COMPANY_ID, $SA_USER_ID, $SA_ROLE, sa_int($_GET['id'] ?? 0)));
    case 'history':
        sa_json(SaSearch::history($DB, $SA_COMPANY_ID, $SA_USER_ID, ['mine' => !empty($_GET['mine']), 'q' => $_GET['q'] ?? '', 'page' => sa_int($_GET['page'] ?? 1)]));
    case 'jump':
        sa_json(SaSearch::jump($DB, $SA_COMPANY_ID, (string)($_GET['q'] ?? '')));
}
sa_error('Unknown request.', 404);
