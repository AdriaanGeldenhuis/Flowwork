<?php
// finances/reports/vat_reconciliation.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=vat_recon).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=vat_recon');
