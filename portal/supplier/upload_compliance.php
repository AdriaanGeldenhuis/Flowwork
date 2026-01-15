<?php
// Handle compliance document uploads from the supplier portal. This script
// accepts a POST request containing a supplier ID, token, document type,
// reference number, issue date, expiry date and file. The uploaded file
// is stored in the company's compliance directory and a record is
// created in the crm_compliance_docs table. Access is controlled
// using the same deterministic token mechanism as other portal pages.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../functions.php';

// Ensure POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

// Collect and validate input
$sid   = isset($_POST['sid']) ? (int)$_POST['sid'] : 0;
$token = $_POST['token'] ?? '';
$typeId = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
$referenceNo = trim($_POST['reference_no'] ?? '');
$issueDate   = $_POST['issue_date'] ?? '';
$expiryDate  = $_POST['expiry_date'] ?? '';

// Validate basic fields
if ($sid <= 0 || empty($token) || !verifyPortalToken($sid, $token)) {
    http_response_code(403);
    echo 'Invalid token or supplier ID';
    exit;
}
if ($typeId <= 0 || !$referenceNo || !$issueDate || !$expiryDate) {
    http_response_code(400);
    echo 'Missing required fields';
    exit;
}
// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
    http_response_code(400);
    echo 'Invalid date format';
    exit;
}

// Validate file
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo 'File upload error';
    exit;
}
$uploadedFile = $_FILES['file'];

// Fetch supplier account and company ID
$stmt = $DB->prepare("SELECT company_id FROM crm_accounts WHERE id = ? AND type = 'supplier'");
$stmt->execute([$sid]);
$supplier = $stmt->fetch();
if (!$supplier) {
    http_response_code(404);
    echo 'Supplier not found';
    exit;
}
$companyId = (int)$supplier['company_id'];

// Fetch compliance type and ensure it belongs to this company
$stmt = $DB->prepare("SELECT name FROM crm_compliance_types WHERE id = ? AND company_id = ?");
$stmt->execute([$typeId, $companyId]);
$typeRow = $stmt->fetch();
if (!$typeRow) {
    http_response_code(400);
    echo 'Invalid document type';
    exit;
}

// Determine destination directory for uploads
$destDir = __DIR__ . '/../../../uploads/company/' . $companyId . '/compliance';
if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
    http_response_code(500);
    echo 'Failed to create upload directory';
    exit;
}

// Generate a unique filename based on supplier ID, type ID and timestamp
$ext = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
$timestamp = time();
$safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
$filename = 'doc_' . $sid . '_' . $typeId . '_' . $timestamp . ($safeExt ? '.' . $safeExt : '');
$destPath = $destDir . '/' . $filename;

// Move the uploaded file
if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
    http_response_code(500);
    echo 'Failed to save uploaded file';
    exit;
}

// Record the document in crm_compliance_docs
$stmt = $DB->prepare("INSERT INTO crm_compliance_docs (company_id, account_id, type_id, reference_no, issue_date, expiry_date, file_path, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'valid', NOW())");
$relativePath = '/uploads/company/' . $companyId . '/compliance/' . $filename;
$stmt->execute([$companyId, $sid, $typeId, $referenceNo, $issueDate, $expiryDate, $relativePath]);

// Redirect back to the portal page with success flag to display message
header('Location: index.php?sid=' . $sid . '&token=' . urlencode($token) . '&uploaded=1');
exit;