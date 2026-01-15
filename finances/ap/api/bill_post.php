<?php
// /finances/ap/api/bill_post.php
// Posts an AP bill to the GL using the PostingService. Only
// permitted users (admin/bookkeeper) can post. Returns journal id
// on success.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';
require_once __DIR__ . '/../../../finances/lib/PostingService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

$input  = json_decode(file_get_contents('php://input'), true);
$billId = isset($input['bill_id']) ? (int)$input['bill_id'] : 0;

if (!$billId) {
    echo json_encode(['ok' => false, 'error' => 'Missing bill_id']);
    exit;
}

try {
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postApBill($billId);
    // Get journal id for the bill
    $stmt = $DB->prepare("SELECT journal_id FROM ap_bills WHERE id = ? AND company_id = ?");
    $stmt->execute([$billId, $companyId]);
    $jid = $stmt->fetchColumn();
    echo json_encode(['ok' => true, 'journal_id' => $jid]);
} catch (Exception $e) {
    error_log('AP bill post error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}