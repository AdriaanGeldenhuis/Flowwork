<?php
// finances/budgets/api/save.php — retired endpoint (Finance now uses /finances/api/budgets.php). Answers 410 Gone so the
// old code can no longer change the books.
require_once __DIR__ . '/../../lib/FinRetired.php';
FinRetired::gone('/finances/api/budgets.php');
