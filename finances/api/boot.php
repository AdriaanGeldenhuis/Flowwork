<?php
// GET /finances/api/boot.php — the page boot payload (config, prefs, routes, chart, tax codes, banks, counts).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinBoot.php';

FinanceBootstrap::ensure($DB, $FIN_COMPANY_ID);
fin_json(['boot' => FinBoot::payload($DB, $FIN_COMPANY_ID, $FIN_USER_ID, $FIN_ROLE)]);
