<?php
// finances/reports/ap_tieout.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=tieouts).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=tieouts', ['as_of' => 'as_of']);
