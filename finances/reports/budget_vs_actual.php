<?php
// finances/reports/budget_vs_actual.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=budget_vs_actual).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=budget_vs_actual');
