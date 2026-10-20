<?php
// /receipts/ajax/upload_file.php — retired endpoint (Receipts now uses /receipts/api/upload.php). Answers 410 Gone.
require_once __DIR__ . '/../lib/RcRetired.php';
RcRetired::gone('/receipts/api/upload.php');
