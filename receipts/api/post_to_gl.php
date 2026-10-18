<?php
// Posts an AP bill (created from a receipt) to the general ledger, then
// writes the net line costs back to the linked project boards.
//
// GL work goes through PostingService::postApBill — the previous version
// hand-rolled the journal here: no period-lock check, no row lock (two
// concurrent posts created two journals), hardcoded fallback account codes,
// line discounts ignored, and the journal was inserted without a status so
// it defaulted to 'draft' and never appeared in any posted-only report.
//
// Board write-back goes through the Projects engine (ItemRepo::setValue) so
// formulas, mirrored item fields, automations and the activity log stay right.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/lib/PostingService.php';
require_once __DIR__ . '/../../projects/lib/autoload.php';

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

/**
 * The board column receipt costs add up in: a plain 'number' column (never a
 * formula) named like "Actual cost" / "Cost" / "Amount" / "Spent", else one
 * formatted as currency. Rate, price, quantity, budget, estimate and
 * percentage columns are never used. null = the board has no such column
 * (nothing is written).
 */
function receipts_cost_column(PDO $DB, int $companyId, int $boardId): ?array
{
    $stmt = $DB->prepare("SELECT column_id, name, type, config FROM board_columns WHERE board_id = ? AND company_id = ? AND type = 'number' ORDER BY position, column_id");
    $stmt->execute([$boardId, $companyId]);
    $best = null;
    $bestScore = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $name = (string)$col['name'];
        $format = Columns::config($col)['format'];
        if ($format === 'percent' || preg_match('/\b(unit|rate|price|qty|quantit|budget|estimat|forecast|quot|planned|margin|markup|varian|percent)|%/i', $name)) continue;
        if (preg_match('/actual\s*cost/i', $name)) $score = 4;
        elseif (preg_match('/cost/i', $name)) $score = 3;
        elseif (preg_match('/amount|spent/i', $name)) $score = 2;
        elseif ($format === 'currency') $score = 1;
        else continue;
        if ($score > $bestScore) {
            $best = $col;
            $bestScore = $score;
        }
    }
    return $best;
}

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
     * Project costing: the NET line cost (qty × price − discount) per
     * board/item pair, written back after the GL commit (below).
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

    $DB->commit();

    /*
     * Project costing write-back. Runs after the GL commit (the engine opens
     * its own transaction) and never undoes the posting. For each verified
     * board/item pair the cost is added to the board's cost column as the
     * poster; when the board has no cost column, or the poster may not edit
     * it, the cost is noted in the item's activity instead.
     */
    $projectCosts = [];
    try {
        if ($costAllocations) {
            $stmt = $DB->prepare("SELECT b.vendor_invoice_number, a.name AS supplier FROM ap_bills b
                                  LEFT JOIN crm_accounts a ON a.id = b.supplier_id AND a.company_id = b.company_id
                                  WHERE b.id = ? AND b.company_id = ?");
            $stmt->execute([$billId, $companyId]);
            $billRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $billLabel = 'Bill ' . ((string)($billRow['vendor_invoice_number'] ?? '') !== '' ? $billRow['vendor_invoice_number'] : '#' . $billId)
                . (!empty($billRow['supplier']) ? ' · ' . $billRow['supplier'] : '');
            $itemRepo = new ItemRepo(ProjectLink::repo($DB, $companyId, $userId, (string)$userRole));
            foreach ($costAllocations as $bId => $items) {
                $col = ProjectLink::boardBelongs($DB, $companyId, (int)$bId) ? receipts_cost_column($DB, $companyId, (int)$bId) : null;
                foreach ($items as $iId => $amount) {
                    $amount = round($amount, 2);
                    $entry = ['board_id' => (int)$bId, 'item_id' => (int)$iId, 'amount' => $amount, 'column' => null];
                    if (!ProjectLink::boardBelongs($DB, $companyId, (int)$bId, (int)$iId)) {
                        $projectCosts[] = $entry + ['status' => 'invalid'];
                        continue;
                    }
                    $reason = 'no_cost_column';
                    if ($col) {
                        try {
                            $stmt = $DB->prepare("SELECT value FROM board_item_values WHERE item_id = ? AND column_id = ?");
                            $stmt->execute([(int)$iId, (int)$col['column_id']]);
                            $was = $stmt->fetchColumn();
                            $newVal = (is_numeric($was) ? (float)$was : 0.0) + $amount;
                            $itemRepo->setValue((int)$iId, (int)$col['column_id'], number_format($newVal, 2, '.', ''));
                            $projectCosts[] = ['column' => $col['name'], 'status' => 'written'] + $entry;
                            continue;
                        } catch (ProjectUserError $e) {
                            // ProjectForbidden or not visible: the poster may not edit this board.
                            $reason = 'no_access';
                        } catch (Throwable $e) {
                            error_log('Post to GL board write-back (item ' . (int)$iId . '): ' . $e->getMessage());
                            $reason = 'error';
                        }
                    }
                    Activity::log($DB, $companyId, $userId, (int)$bId, (int)$iId, 'cost_saved', [
                        'description' => Activity::clip($billLabel, 80), 'amount' => $amount,
                        'source' => 'receipts', 'bill_id' => $billId, 'reason' => $reason,
                    ]);
                    $projectCosts[] = $entry + ['status' => 'noted', 'reason' => $reason];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Post to GL board write-back: ' . $e->getMessage());
    }

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
    echo json_encode(['ok' => true, 'journal_id' => $journalId, 'project_costs' => $projectCosts]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Post to GL error: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Failed to post bill to GL' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $msg]);
}
