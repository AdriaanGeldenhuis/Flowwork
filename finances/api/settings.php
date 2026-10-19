<?php
// /finances/api/settings.php — "Make it yours", company side (administrators).
//   GET  → company look, wording, parts, layout, thresholds and rules; for admins also the
//          engine settings, account mappings and exchange rates
//   POST {op: company, labels?, modules?, rules?, thresholds?, accent?, overview?, overview_off?}
//        {op: reset, part: labels|modules|rules|thresholds|accent|overview|all}
//        {op: engine, fiscal_year_start?, vat_basis?, require_sod?}
//        {op: mappings, map: {key: account_id|null}}
//        {op: rate, currency, date, rate}            (bookkeepers too)
// Each person's own look is saved through prefs.php.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinSettings.php';
require_once __DIR__ . '/../lib/FinBoot.php';

$C = $FIN_COMPANY_ID;
if (!fin_is_post()) fin_json(FinSettings::load($DB, $C, fin_is_admin()));

fin_require_writer();
$in = fin_input();
switch (fin_op()) {
    case 'company':
        FinSettings::saveCompany($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        break;
    case 'reset':
        FinSettings::resetCompany($DB, $C, $FIN_USER_ID, $FIN_ROLE, fin_str($in['part'] ?? '', 20));
        break;
    case 'engine':
        FinSettings::saveEngine($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        break;
    case 'mappings':
        FinSettings::saveMappings($DB, $C, $FIN_USER_ID, $FIN_ROLE, (array)($in['map'] ?? []));
        break;
    case 'rate':
        FinSettings::saveRate($DB, $C, $FIN_USER_ID, $FIN_ROLE, $in);
        break;
    default:
        fin_error('Unknown action');
}
Fin::flush();
fin_json(FinSettings::load($DB, $C, fin_is_admin()) + ['boot' => FinBoot::payload($DB, $C, $FIN_USER_ID, $FIN_ROLE)]);
