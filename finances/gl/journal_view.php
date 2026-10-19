<?php
// finances/gl/journal_view.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#journals).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#journals', ['id' => 'id']);
