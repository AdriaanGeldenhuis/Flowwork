<?php
// finances/vat.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#vat).
require_once __DIR__ . '/lib/FinRetired.php';
FinRetired::redirect('#vat', ['period_id' => 'id']);
