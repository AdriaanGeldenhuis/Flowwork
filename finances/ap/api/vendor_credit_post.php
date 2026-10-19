<?php
// finances/ap/api/vendor_credit_post.php — retired endpoint (Finance now uses /finances/api/payables.php). Answers 410 Gone so the
// old code can no longer change the books.
require_once __DIR__ . '/../../lib/FinRetired.php';
FinRetired::gone('/finances/api/payables.php');
