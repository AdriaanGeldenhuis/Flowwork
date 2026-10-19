<?php
// finances/ap/three_way_match.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?view=match).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?view=match', ['supplier_id' => 'supplier']);
