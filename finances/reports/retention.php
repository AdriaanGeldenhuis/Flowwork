<?php
// finances/reports/retention.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=retention).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=retention');
