<?php
// finances/exchange_rates.php — retired: Finance is one page now. This address sends the browser
// to the matching screen (/finances/settings.php#fx).
require_once __DIR__ . '/lib/FinRetired.php';
FinRetired::redirect('/finances/settings.php#fx');
