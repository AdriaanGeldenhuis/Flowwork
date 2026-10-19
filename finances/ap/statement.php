<?php
// finances/ap/statement.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?view=statement).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?view=statement', ['supplier_id' => 'supplier']);
