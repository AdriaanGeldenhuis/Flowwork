<?php
// finances/export/vat_audit_file.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (/finances/api/vat.php?op=audit&format=csv).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('/finances/api/vat.php?op=audit&format=csv', ['period_id' => 'id']);
