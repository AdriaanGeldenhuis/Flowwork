<?php
// Posts an AP bill (created from a receipt) to the general ledger, then
// writes the net line costs back to the linked project boards.
//
// GL work goes through PostingService::postApBill — the previous version
// hand-rolled the journal here: no period-lock check, no row lock (two
// concurrent posts created two journals), hardcoded fallback account codes,
// line discounts ignored, and the journal was inserted without a status so
// it defaulted to 'draft' and never appeared in any posted-only report.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/lib/PostingService.php';

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Permission gating: only allow admin or bookkeeper roles to post bills
$userRole = $_SESSION['role'] ?? 'member';
if (!in_array($userRole, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Posting to the GL is a financial mutation — validate CSRF. The receipts review
// page injects X-CSRF-Token (see receipts/review.php + review.js).
require_once __DIR__ . '/../../finances/lib/Csrf.php';
Csrf::validate();

$input = json_decode(file_get_contents('php://input'), true);
$billId = isset($input['bill_id']) ? (int)$input['bill_id'] : 0;

if (!$billId) {
    echo json_encode(['ok' => false, 'error' => 'Missing bill_id']);
    exit;
}

try {
    $DB->beginTransaction();

    // Idempotent contract for the receipts UI: an already-posted bill is a
    // success, not an error. postApBill re-checks under FOR UPDATE, so a
    // concurrent duplicate request fails there and rolls back cleanly.
    $stmt = $DB->prepare("SELECT status FROM ap_bills WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$billId, $companyId]);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bill) {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Bill not found']);
        exit;
    }
    if ($bill['status'] === 'posted' || $bill['status'] === 'paid') {
        $DB->rollBack();
        echo json_encode(['ok' => true]);
        exit;
    }

    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postApBill($billId);

    $stmt = $DB->prepare("SELECT journal_id FROM ap_bills WHERE id = ? AND company_id = ?");
    $stmt->execute([$billId, $companyId]);
    $journalId = (int)$stmt->fetchColumn();

    /*
     * Project costing write-back
     * For each board/item pair that received expenses, add the NET line cost
     * (qty × price − discount) to a number column. Tries to use an existing
     * number column if present; otherwise creates a "Cost to date" column.
     */
    $stmt = $DB->prepare(
        "SELECT quantity, unit_price, discount, project_board_id, project_item_id
           FROM ap_bill_lines WHERE bill_id = ?"
    );
    $stmt->execute([$billId]);
    $costAllocations = [];
    $grossAmount = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $net = (float)$line['quantity'] * (float)$line['unit_price'] - (float)($line['discount'] ?? 0);
        $grossAmount += $net;
        $bId = $line['project_board_id'] ? (int)$line['project_board_id'] : null;
        $iId = $line['project_item_id'] ? (int)$line['project_item_id'] : null;
        if ($bId && $iId) {
            $costAllocations[$bId][$iId] = ($costAllocations[$bId][$iId] ?? 0.0) + $net;
        }
    }

    // Helper to fetch or create a number column on a board
    $numberColumnCache = [];
    $getNumberColumnId = function (int $boardId) use ($DB, $companyId, &$numberColumnCache) {
        if (isset($numberColumnCache[$boardId])) {
            return $numberColumnCache[$boardId];
        }
        $stmt = $DB->prepare("SELECT column_id FROM board_columns WHERE board_id = ? AND company_id = ? AND type = 'number' ORDER BY sort_order LIMIT 1");
        $stmt->execute([$boardId, $companyId]);
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $numberColumnCache[$boardId] = (int)$col['column_id'];
            return $numberColumnCache[$boardId];
        }
        $stmt = $DB->prepare("SELECT IFNULL(MAX(sort_order), 0) AS max_sort FROM board_columns WHERE board_id = ? AND company_id = ?");
        $stmt->execute([$boardId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $sortOrder = ($row && $row['max_sort'] !== null) ? ((int)$row['max_sort'] + 1) : 1;
        $stmt = $DB->prepare("INSERT INTO board_columns (board_id, company_id, name, type, position, sort_order, visible, width, created_at) VALUES (?, ?, 'Cost to date', 'number', ?, ?, 1, 150, NOW())");
        $stmt->execute([$boardId, $companyId, $sortOrder, $sortOrder]);
        $numberColumnCache[$boardId] = (int)$DB->lastInsertId();
        return $numberColumnCache[$boardId];
    };
    foreach ($costAllocations as $bId => $items) {
        $columnId = $getNumberColumnId($bId);
        foreach ($items as $iId => $amount) {
            $stmt = $DB->prepare("SELECT value FROM board_item_values WHERE item_id = ? AND column_id = ? LIMIT 1");
            $stmt->execute([$iId, $columnId]);
            $valRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($valRow && isset($valRow['value'])) {
                $newVal = (float)$valRow['value'] + $amount;
                $stmt2 = $DB->prepare("UPDATE board_item_values SET value = ? WHERE item_id = ? AND column_id = ?");
                $stmt2->execute([number_format($newVal, 2, '.', ''), $iId, $columnId]);
            } else {
                $stmt2 = $DB->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
                $stmt2->execute([$iId, $columnId, number_format($amount, 2, '.', '')]);
            }
        }
    }

    $DB->commit();

    // Audit logging: record that the bill was posted (best effort)
    try {
        $details = json_encode([
            'bill_id' => $billId,
            'journal_id' => $journalId,
            'total_net' => round($grossAmount, 2),
        ]);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmtAudit = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, 'ap_bill_posted', 'ap_bill', ?, ?, ?)");
        $stmtAudit->execute([$companyId, $userId, $billId, $details, $ip]);
    } catch (Exception $e) {
        error_log('Audit log error (post_to_gl): ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'journal_id' => $journalId]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Post to GL error: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Failed to post bill to GL' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
}
