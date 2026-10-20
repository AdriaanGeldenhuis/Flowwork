<?php
// /receipts/ajax/ocr_parse.php — retired endpoint (Receipts now uses /receipts/api/read.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/RcRetired.php';
RcRetired::gone('/receipts/api/read.php');
