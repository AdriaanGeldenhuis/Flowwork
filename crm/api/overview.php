<?php
// GET /crm/api/overview.php — everything on the CRM overview (only the panels the company switched on).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/CrmOverview.php';

crm_json(CrmOverview::build($DB, $CRM_COMPANY_ID, $CRM_USER_ID, $CRM_ROLE));
