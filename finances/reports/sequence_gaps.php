<?php
// finances/reports/sequence_gaps.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#reports?r=sequence_gaps).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#reports?r=sequence_gaps', ['year' => 'year']);
