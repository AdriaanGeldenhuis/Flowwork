<?php
// finances/reports/cashflow_indirect.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=cashflow).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=cashflow', ['start_date' => 'from', 'end_date' => 'to']);
