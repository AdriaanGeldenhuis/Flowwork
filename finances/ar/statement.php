<?php
// finances/ar/statement.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#receivables?view=statement).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#receivables?view=statement', ['customer_id' => 'customer']);
