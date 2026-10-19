<?php
// finances/ajax/tax_code_list.php — retired endpoint (Finance now uses /finances/api/). Answers 410 Gone so the
// old code can no longer change the books.
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::gone('/finances/api/');
