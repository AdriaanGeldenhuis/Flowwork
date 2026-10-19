<?php
// finances/reports/ar_aging.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=ar_aging).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=ar_aging', ['date' => 'as_of', 'as_of' => 'as_of']);
