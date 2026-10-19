<?php
// finances/reports/capital_goods_vat.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=capital_goods_vat).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=capital_goods_vat', ['start' => 'from', 'end' => 'to']);
