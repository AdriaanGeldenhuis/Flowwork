<?php
// finances/ap/bill_new.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?new=bill).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?new=bill');
