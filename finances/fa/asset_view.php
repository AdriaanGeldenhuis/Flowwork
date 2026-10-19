<?php
// finances/fa/asset_view.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (#assets).
require_once __DIR__ . '/../lib/FinRetired.php';
FinRetired::redirect('#assets', ['aid' => 'id']);
