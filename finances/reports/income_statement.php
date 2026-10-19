<?php
// finances/reports/income_statement.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=income_statement).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=income_statement', ['start_date' => 'from', 'end_date' => 'to']);
