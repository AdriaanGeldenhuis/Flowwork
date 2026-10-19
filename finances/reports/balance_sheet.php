<?php
// finances/reports/balance_sheet.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=balance_sheet).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=balance_sheet', ['date' => 'as_of']);
