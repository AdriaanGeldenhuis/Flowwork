<?php
// finances/ap/payment_new.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?new=payment).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?new=payment', ['supplier_id' => 'supplier', 'bill_id' => 'bill']);
