<?php
// finances/ap/aging.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?view=aging).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?view=aging', ['date' => 'as_of']);
