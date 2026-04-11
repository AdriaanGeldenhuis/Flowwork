<?php
// /finances/ajax/bank_import_parse_pdf.php
// Parses a PDF bank statement and returns extracted transactions for preview.
// Does NOT insert into the database — that happens via bank_import_confirm.php
// after the user reviews and confirms the extracted data.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/BankStatementParser.php';
require_once __DIR__ . '/../permissions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit;
}

// Validate file upload
if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['pdf_file'];
// Use finfo for more reliable MIME detection; fall back to mime_content_type
$mimeType = null;
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
}
if ($mimeType === null && function_exists('mime_content_type')) {
    $mimeType = mime_content_type($file['tmp_name']);
}

if ($mimeType !== 'application/pdf') {
    echo json_encode(['ok' => false, 'error' => 'File must be a PDF']);
    exit;
}

// Max 10MB
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'File too large (max 10MB)']);
    exit;
}

try {
    $parser = new BankStatementParser();
    $result = $parser->parsePDF($file['tmp_name']);

    if (!$result['ok']) {
        echo json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Failed to parse PDF',
            'warnings' => $result['warnings'] ?? [],
            'bank' => $result['bank'] ?? 'unknown'
        ]);
        exit;
    }

    // Filter out transactions with null dates
    $validTx = array_filter($result['transactions'], function($tx) {
        return !empty($tx['tx_date']);
    });
    $validTx = array_values($validTx);

    echo json_encode([
        'ok' => true,
        'bank' => $result['bank'],
        'count' => count($validTx),
        'transactions' => $validTx,
        'warnings' => $result['warnings'] ?? []
    ]);

} catch (Exception $e) {
    error_log('PDF parse error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to parse PDF'
    ]);
}
