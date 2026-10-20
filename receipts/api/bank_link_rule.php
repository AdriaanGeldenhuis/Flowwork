<?php
// /receipts/api/bank_link_rule.php — retired endpoint (Receipts now uses /receipts/api/upload.php; bank rules live in Finance → Bank). Answers 410 Gone.
require_once __DIR__ . '/../lib/RcRetired.php';
RcRetired::gone('/receipts/api/upload.php');
