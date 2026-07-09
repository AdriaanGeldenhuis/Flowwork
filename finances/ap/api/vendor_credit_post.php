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
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../lib/Csrf.php';
require_once __DIR__ . '/../../lib/http.php';
Csrf::validate();

$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    http_response_code(403);
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

// Aggregate requested allocation per bill (guards against the same bill
// appearing more than once in a single request).
$perBill = [];
foreach ($allocs as $a) {
    $bId = isset($a['bill_id']) ? (int)$a['bill_id'] : 0;
    $amt = isset($a['amount']) ? (float)$a['amount'] : 0.0;
    if ($bId <= 0 || $amt <= 0) {
        continue;
    }
    $perBill[$bId] = ($perBill[$bId] ?? 0.0) + $amt;
}
$allocSum = array_sum($perBill);
if (!$perBill || $allocSum <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No valid allocations supplied']);
    exit;
}

$insertedAllocIds = [];
try {
    $DB->beginTransaction();
    // Fetch and lock the vendor credit so concurrent allocations serialise
    $stmt = $DB->prepare("SELECT * FROM vendor_credits WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$creditId, $companyId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credit) {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Vendor credit not found']);
        exit;
    }
    if ($credit['status'] === 'applied') {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Credit already applied']);
        exit;
    }
    $creditSupplierId = (int)$credit['supplier_id'];
    $totalCredit = floatval($credit['total']);
    // Validate against the UNALLOCATED remainder, not the full credit total
    $stmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM vendor_credit_allocations WHERE credit_id = ?");
    $stmt->execute([$creditId]);
    $alreadyAllocated = floatval($stmt->fetchColumn());
    $remainingCredit  = $totalCredit - $alreadyAllocated;
    if ($allocSum > $remainingCredit + 0.01) {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Allocations of R' . number_format($allocSum, 2) . ' exceed the unallocated credit remainder of R' . number_format($remainingCredit, 2)]);
        exit;
    }
    // Validate each bill: must exist in this company, belong to the credit's
    // supplier, and have enough remaining balance. Rows locked FOR UPDATE.
    $stmtBal = $DB->prepare(
        "SELECT b.total, b.supplier_id, b.status, b.journal_id,
                COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = b.id), 0) AS paid,
                COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = b.id), 0) AS credited
         FROM ap_bills b WHERE b.id = ? AND b.company_id = ? FOR UPDATE"
    );
    foreach ($perBill as $bId => $amt) {
        $stmtBal->execute([$bId, $companyId]);
        $row = $stmtBal->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Bill #' . $bId . ' was not found for this company']);
            exit;
        }
        if ((int)$row['supplier_id'] !== $creditSupplierId) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Bill #' . $bId . ' belongs to a different supplier than this credit']);
            exit;
        }
        // The bill must already be posted (Cr AP behind it). Crediting an
        // unposted draft would debit AP with no AP credit behind it, and once
        // the allocation settles the balance the bill flips to 'paid', after
        // which postApBill permanently refuses to post it.
        if (!in_array($row['status'], ['posted', 'paid'], true) || empty($row['journal_id'])) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Bill #' . $bId . ' has not been posted to the GL yet — post the bill before applying a credit to it']);
            exit;
        }
        $remaining = floatval($row['total']) - (floatval($row['paid']) + floatval($row['credited']));
        if ($amt > $remaining + 0.01) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Credit allocation of R' . number_format($amt, 2) . ' exceeds outstanding balance of R' . number_format($remaining, 2) . ' on bill #' . $bId]);
            exit;
        }
    }
    // Insert allocations
    $insAlloc = $DB->prepare(
        "INSERT INTO vendor_credit_allocations (credit_id, bill_id, amount, created_at)
         VALUES (?, ?, ?, NOW())"
    );
    foreach ($perBill as $billId => $amt) {
        $insAlloc->execute([$creditId, $billId, $amt]);
        $insertedAllocIds[] = (int)$DB->lastInsertId();
    }

    // Post credit to GL inside the SAME transaction (withTransaction joins an
    // open one): allocations without a journal can no longer be stranded by a
    // crash between two commits — the previous two-transaction flow
    // compensated with a best-effort DELETE that could itself fail.
    // Note: by GL design the posting debits AP for the FULL credit total (a
    // vendor credit note is a full AP reduction, mirroring the AP subledger
    // which subtracts full vendor_credits.total). Only the status/remainder
    // tracking is adjusted below for partial allocation.
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postVendorCredit($creditId);

    // postVendorCredit() flips status to 'applied' unconditionally. 'applied'
    // must mean fully allocated (the UI blocks further allocation once a
    // credit is 'applied'), so when a remainder is left we revert the status
    // to 'draft' — keeping the remainder allocatable. The journal_id set by
    // posting is preserved either way.
    $stmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM vendor_credit_allocations WHERE credit_id = ?");
    $stmt->execute([$creditId]);
    $allocatedNow = floatval($stmt->fetchColumn());
    if ($totalCredit - $allocatedNow > 0.01) {
        $stmt = $DB->prepare("UPDATE vendor_credits SET status = 'draft' WHERE id = ? AND company_id = ?");
        $stmt->execute([$creditId, $companyId]);
    }
    // Update bill statuses if fully settled
    foreach (array_keys($perBill) as $bId) {
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

    $DB->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Vendor credit post error: ' . $e->getMessage());
    json_exception($e);
    exit;
}