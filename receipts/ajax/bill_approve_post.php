<?php
// /receipts/ajax/bill_approve_post.php — retired endpoint (Receipts now uses /receipts/api/bill.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/RcRetired.php';
RcRetired::gone('/receipts/api/bill.php');
