<?php
// GET /finances/api/search.php?q= — Ctrl K search (journals, invoices, bills, customers, suppliers, bank lines, assets).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/FinSearch.php';

fin_rate_limit('search', 120, 60);
fin_json(['results' => FinSearch::run($DB, $FIN_COMPANY_ID, mb_substr((string)($_GET['q'] ?? ''), 0, 80), $FIN_CONFIG['modules'])]);
