<?php
// finances/reports/trial_balance.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=trial_balance).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=trial_balance', ['date' => 'as_of']);
