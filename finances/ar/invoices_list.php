<?php
// finances/ar/invoices_list.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#receivables?status=all).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#receivables?status=all');
