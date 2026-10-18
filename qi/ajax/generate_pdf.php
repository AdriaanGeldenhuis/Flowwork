<?php
// /qi/ajax/generate_pdf.php — the printable page is the PDF itself now: one
// renderer (DocPdf) draws the downloaded, emailed, Drive and printed copy, so
// this opens it inline where the browser's viewer prints it as designed.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$type = in_array($_GET['type'] ?? '', ['invoice', 'quote', 'credit_note'], true) ? $_GET['type'] : 'quote';
$id   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo 'Invalid document ID';
    exit;
}
header('Location: /qi/ajax/download_pdf.php?type=' . $type . '&id=' . (int)$id . '&inline=1', true, 302);
