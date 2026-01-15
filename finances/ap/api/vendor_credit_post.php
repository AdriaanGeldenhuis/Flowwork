<?php
// /finances/ap/api/vendor_credit_post.php
// Applies a vendor credit to one or more AP bills and posts the credit
// to the general ledger via PostingService. Expects JSON POST with
// {credit_id: int, allocations: [{bill_id:int, amount:float}, ...]}.

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

$input = json_decode(file_get_contents('php://input'), true);
$creditId = isset($input['credit_id']) ? (int)$input['credit_id'] : 0;
$allocs   = $input['allocations'] ?? [];

if (!$creditId || !$allocs || !is_array($allocs)) {
    echo json_encode(['ok' => false, 'error' => 'Missing credit_id or allocations']);
    exit;
}

try {
    // Fetch vendor credit
    $stmt = $DB->prepare("SELECT * FROM vendor_credits WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$creditId, $companyId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credit) {
        echo json_encode(['ok' => false, 'error' => 'Vendor credit not found']);
        exit;
    }
    if ($credit['status'] === 'applied') {
        echo json_encode(['ok' => false, 'error' => 'Credit already applied']);
        exit;
    }
    $totalCredit = floatval($credit['total']);
    // Sum allocations
    $allocSum = 0.0;
    foreach ($allocs as $a) {
        $amt = isset($a['amount']) ? (float)$a['amount'] : 0.0;
        if ($amt > 0) $allocSum += $amt;
    }
    if ($allocSum <= 0 || $allocSum > $totalCredit + 0.0001) {
        echo json_encode(['ok' => false, 'error' => 'Allocations exceed credit total']);
        exit;
    }
    // Begin DB transaction
    $DB->beginTransaction();
    // Insert allocations
    $insAlloc = $DB->prepare(
        "INSERT INTO vendor_credit_allocations (credit_id, bill_id, amount, created_at)
         VALUES (?, ?, ?, NOW())"
    );
    foreach ($allocs as $a) {
        $billId = isset($a['bill_id']) ? (int)$a['bill_id'] : 0;
        $amt    = isset($a['amount']) ? (float)$a['amount'] : 0.0;
        if ($billId && $amt > 0) {
            $insAlloc->execute([$creditId, $billId, $amt]);
        }
    }
    $DB->commit();
    // Post credit to GL
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postVendorCredit($creditId);
    // After posting, update bill statuses if fully settled
    // Fetch affected bill IDs
    $stmtBillIds = $DB->prepare(
        "SELECT DISTINCT bill_id FROM vendor_credit_allocations WHERE credit_id = ?"
    );
    $stmtBillIds->execute([$creditId]);
    $billIds = $stmtBillIds->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach ($billIds as $bId) {
        $stmtBal = $DB->prepare(
            "SELECT total,
                    COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = ?),0) AS paid,
                    COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = ?),0) AS credited
             FROM ap_bills WHERE id = ? AND company_id = ?"
        );
        $stmtBal->execute([$bId, $bId, $bId, $companyId]);
        $row = $stmtBal->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $balance = floatval($row['total']) - (floatval($row['paid']) + floatval($row['credited']));
            if ($balance <= 0.0001) {
                $stmtUpd = $DB->prepare(
                    "UPDATE ap_bills SET status = 'paid' WHERE id = ? AND company_id = ?"
                );
                $stmtUpd->execute([$bId, $companyId]);
            }
        }
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Vendor credit post error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}