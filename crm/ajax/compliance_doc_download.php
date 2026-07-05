<?php
// /crm/ajax/compliance_doc_download.php
// Streams a compliance document to the browser. Files live under
// /uploads/compliance/ (blocked from direct web access) and are stored in
// crm_compliance_docs.file_path as a repo-relative path.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

$companyId = $_SESSION['company_id'];
$docId = (int)($_GET['id'] ?? 0);

try {
    if (!$docId) {
        throw new Exception('Document ID is required');
    }

    $stmt = $DB->prepare("
        SELECT cd.file_path, ct.code AS type_code
        FROM crm_compliance_docs cd
        LEFT JOIN crm_compliance_types ct ON ct.id = cd.type_id
        WHERE cd.id = ? AND cd.company_id = ?
    ");
    $stmt->execute([$docId, $companyId]);
    $doc = $stmt->fetch();

    if (!$doc || !$doc['file_path']) {
        throw new Exception('Document not found');
    }

    // Resolve against the app root and refuse anything that escapes the
    // compliance upload directory (path traversal guard).
    $baseDir = realpath(__DIR__ . '/../../uploads/compliance');
    $absPath = realpath(__DIR__ . '/../../' . ltrim($doc['file_path'], '/'));

    if ($baseDir === false || $absPath === false
        || strpos($absPath, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
        throw new Exception('File is missing on the server');
    }

    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $contentTypes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];
    if (!isset($contentTypes[$ext])) {
        throw new Exception('File type not allowed');
    }

    $label = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($doc['type_code'] ?: 'compliance'));
    $downloadName = 'doc_' . $docId . '_' . trim($label, '_') . '.' . $ext;

    header('Content-Type: ' . $contentTypes[$ext]);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($absPath));
    header('X-Content-Type-Options: nosniff');
    readfile($absPath);
    exit;

} catch (Throwable $e) {
    // Direct browser navigation — plain text beats a JSON blob here.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo crm_public_error($e);
}
