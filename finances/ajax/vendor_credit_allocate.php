<?php
// /finances/ajax/vendor_credit_allocate.php
// Records allocations of a vendor credit to one or more AP bills. Unlike
// vendor_credit_post.php, this endpoint does not post the credit to the
// general ledger. It simply inserts allocation records and updates bill
// statuses if fully settled. Expects JSON POST with {credit_id:int,
// allocations:[{bill_id:int, amount:float}, ...]}.

// Dynamically load init and auth for installations with or without an /app directory.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../permissions.php';

require_method('POST');
Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit;
}

// Decode input
$input   = json_decode(file_get_contents('php://input'), true);
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

try {
    $DB->beginTransaction();
    // Fetch and lock the credit so concurrent allocations serialise and
    // repeated calls cannot over-allocate.
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
    // Validate against the UNALLOCATED remainder (total - prior allocations)
    $stmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM vendor_credit_allocations WHERE credit_id = ?");
    $stmt->execute([$creditId]);
    $alreadyAllocated = floatval($stmt->fetchColumn());
    $remainingCredit  = $totalCredit - $alreadyAllocated;
    if ($allocSum > $remainingCredit + 0.01) {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Allocations of R' . number_format($allocSum, 2) . ' exceed the unallocated credit remainder of R' . number_format($remainingCredit, 2)]);
        exit;
    }
    // Validate each target bill: must exist in this company, belong to the
    // credit's supplier and have enough remaining balance. Locked FOR UPDATE.
    $stmtChk = $DB->prepare(
        "SELECT b.total, b.supplier_id,
                COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = b.id), 0) AS paid,
                COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = b.id), 0) AS credited
         FROM ap_bills b WHERE b.id = ? AND b.company_id = ? FOR UPDATE"
    );
    foreach ($perBill as $bId => $amt) {
        $stmtChk->execute([$bId, $companyId]);
        $row = $stmtChk->fetch(PDO::FETCH_ASSOC);
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
        $remaining = floatval($row['total']) - (floatval($row['paid']) + floatval($row['credited']));
        if ($amt > $remaining + 0.01) {
            $DB->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Credit allocation of R' . number_format($amt, 2) . ' exceeds outstanding balance of R' . number_format($remaining, 2) . ' on bill #' . $bId]);
            exit;
        }
    }
    // Insert allocations
    $insAlloc = $DB->prepare(
        "INSERT INTO vendor_credit_allocations (credit_id, bill_id, amount, created_at) VALUES (?, ?, ?, NOW())"
    );
    foreach ($perBill as $billId => $amt) {
        $insAlloc->execute([$creditId, $billId, $amt]);
    }
    // Update affected bills' statuses if they are fully paid/credited
    // (inside the same transaction so allocation + status commit atomically)
    $billIds = array_keys($perBill);
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
    $DB->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if ($DB->inTransaction()) { $DB->rollBack(); }
    error_log('Vendor credit allocation error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to allocate credit']);
}