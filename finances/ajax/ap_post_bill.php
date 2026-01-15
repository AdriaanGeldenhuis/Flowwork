<?php
// /finances/ajax/ap_post_bill.php
// Posts an existing AP bill to the general ledger using the PostingService.
// Only finance users (admin or bookkeeper) are permitted to perform this action.

// Dynamically require init and auth. Compute project root two levels above.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}
require_once __DIR__ . '/../lib/PostingService.php';

header('Content-Type: application/json');

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Check that the user has sufficient permissions
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

// Company and user context from session
$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Decode the JSON payload to obtain the bill ID
$input  = json_decode(file_get_contents('php://input'), true);
$billId = isset($input['bill_id']) ? (int)$input['bill_id'] : 0;

if (!$billId) {
    echo json_encode(['ok' => false, 'error' => 'Missing bill_id']);
    exit;
}

try {
    // Post the bill using the central PostingService
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postApBill($billId);
    // Retrieve the journal ID that was created
    $stmt = $DB->prepare("SELECT journal_id FROM ap_bills WHERE id = ? AND company_id = ?");
    $stmt->execute([$billId, $companyId]);
    $journalId = $stmt->fetchColumn();
    echo json_encode(['ok' => true, 'journal_id' => $journalId]);
} catch (Exception $e) {
    // Log the error and return a message
    error_log('AP bill post error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}