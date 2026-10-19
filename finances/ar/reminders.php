<?php
// finances/ar/reminders.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#receivables?status=overdue).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#receivables?status=overdue');
