<?php
// finances/ajax/year_end_close.php — retired endpoint (Finance now uses /finances/api/close.php). Answers 410 Gone so the
// old code can no longer change the books.
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::gone('/finances/api/close.php');
