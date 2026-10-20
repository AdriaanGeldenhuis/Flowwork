<?php
// /receipts/ajax/list_inbox.php — retired endpoint (Receipts now uses /receipts/api/receipts.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/RcRetired.php';
RcRetired::gone('/receipts/api/receipts.php');
