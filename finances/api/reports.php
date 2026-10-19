<?php
// /finances/api/reports.php — every Finance report in one shape (see FinReports).
//   GET ?op=catalog
//   GET ?op=run&key=&as_of=&from=&to=&compare=&fy=&year=[&format=csv]
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinReports.php';

$C = $FIN_COMPANY_ID;
if (fin_is_post()) fin_error('Method not allowed', 405);
switch ($_GET['op'] ?? 'catalog') {
    case 'catalog':
        fin_json(['groups' => FinReports::catalog($FIN_CONFIG['modules'] ?? [])]);
    case 'run':
        fin_rate_limit('reports', 120, 60);
        $r = FinReports::run($DB, $C, fin_str($_GET['key'] ?? '', 40), $_GET, $FIN_CONFIG['modules'] ?? []);
        if (($_GET['format'] ?? '') === 'csv') FinReports::csv($r);
        fin_json(['report' => $r]);
}
fin_error('Unknown action');
