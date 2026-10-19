<?php
// finances/fa/api/run_depreciation.php — retired endpoint (Finance now uses /finances/api/assets.php). Answers 410 Gone so the
// old code can no longer change the books.
require_once __DIR__ . '/../../lib/FinRetired.php';
FinRetired::gone('/finances/api/assets.php');
