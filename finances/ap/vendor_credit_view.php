<?php
// finances/ap/vendor_credit_view.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#payables?view=credits).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#payables?view=credits', ['id' => 'credit']);
