<?php
// finances/ajax/report_ar_aging.php — retired endpoint (Finance now uses /finances/api/reports.php). Answers 410 Gone so the
// old code can no longer change the books.
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::gone('/finances/api/reports.php');
