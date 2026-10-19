<?php
// finances/ap/bill_view.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?status=all).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?status=all', ['id' => 'id']);
