<?php
// finances/ap/payment_list.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?view=payments).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?view=payments');
