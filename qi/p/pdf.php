<?php
// /qi/p/pdf.php — the PDF behind a customer link (?type=quote|invoice&token=).
// Drawn on request by the same renderer as the emailed copy; nothing is stored.
require_once __DIR__ . '/_public.php';
require_once __DIR__ . '/../services/DocumentPdfService.php';

$type = ($_GET['type'] ?? '') === 'invoice' ? 'invoice' : 'quote';
if (!qi_pub_allow('pdf', 40, 600)) qi_pub_stop(429, 'Slow down a little', 'Too many downloads from your network. Please wait a few minutes.');
[$row] = qi_pub_load($DB, $type);
try {
    $bytes = DocumentPdfService::bytes($DB, (int)$row['company_id'], $type, (int)$row['id']);
} catch (Throwable $e) {
    error_log('[qi/p/pdf] ' . $e->getMessage());
    $bytes = null;
}
if (!$bytes) qi_pub_stop(500, 'PDF not available', 'The PDF could not be produced right now. Please try again in a moment.');
$name = DocumentPdfService::safeCode(QiDocument::numberOf($type, $row)) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (($_GET['dl'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
