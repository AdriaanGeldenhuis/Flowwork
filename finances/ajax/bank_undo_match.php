<?php
// finances/ajax/bank_undo_match.php — retired endpoint (Finance now uses /finances/api/bank.php). Answers 410 Gone so the
// old code can no longer change the books.
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::gone('/finances/api/bank.php');
