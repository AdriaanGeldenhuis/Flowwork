<?php
// download.php
//
// Authenticated proxy for files under /storage (payslips, invoice PDFs, etc.).
// storage/.htaccess denies direct web access, so documents must be fetched
// through here. This enforces: a valid session, that the file lives under
// storage/, no path traversal, and that the file belongs to the caller's
// company. Payroll documents additionally require a privileged role.
//
// Usage:  /download.php?file=storage/payroll/12/run_5/payslip_87.pdf
// Update the download links in payslips.php / invoice views to point here
// (see SECURITY-REMEDIATION.md).

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/auth_gate.php';

$companyId = (int)($_SESSION['company_id'] ?? 0);
$role      = $_SESSION['role'] ?? 'viewer';

$rel = ltrim((string)($_GET['file'] ?? ''), '/');

// Must be a storage path.
if ($rel === '' || strpos($rel, 'storage/') !== 0 || strpos($rel, "\0") !== false) {
    http_response_code(400);
    exit('Bad request');
}

// Resolve against the storage root and reject anything that escapes it.
$storageRoot = realpath(__DIR__ . '/storage');
$full        = realpath(__DIR__ . '/' . $rel);
if ($storageRoot === false || $full === false ||
    strpos($full, $storageRoot . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($full)) {
    http_response_code(404);
    exit('Not found');
}

// Path convention: storage/{module}/{company_id}/...
$parts     = explode('/', $rel);
$module    = $parts[1] ?? '';
$pathCompany = (int)($parts[2] ?? 0);

// Tenant isolation: the document's company segment must match the session.
if ($pathCompany === 0 || $pathCompany !== $companyId) {
    http_response_code(403);
    exit('Forbidden');
}

// Payroll documents contain sensitive PII — restrict to privileged roles.
if ($module === 'payroll' &&
    !in_array($role, ['admin', 'owner', 'manager', 'bookkeeper'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

// Stream the file.
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $detected = finfo_file($fi, $full);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
        finfo_close($fi);
    }
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($full) . '"');
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($full);
exit;
