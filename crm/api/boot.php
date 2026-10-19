<?php
// GET /crm/api/boot.php — the page boot payload (config, prefs, stages, people, tags, lookups, fields, counts).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmBoot.php';

crm_json(['boot' => CrmBoot::payload($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE)]);
