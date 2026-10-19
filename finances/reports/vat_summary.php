<?php
// finances/reports/vat_summary.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=vat_summary).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=vat_summary', ['start_date' => 'from', 'end_date' => 'to']);
