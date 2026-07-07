<?php
// /finances/inventory/api/return_issue.php
// Endpoint to process inventory returns. Receives a quantity of an item back into
// stock at the current weighted average cost and posts a reversing COGS/Inventory
// journal entry.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
    exit;
}

// CSRF validation
require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

// Restrict access to admins and bookkeepers
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin', 'bookkeeper']);

require_once __DIR__ . '/../../lib/AccountsMap.php';
require_once __DIR__ . '/../../lib/http.php';
require_once __DIR__ . '/../../lib/PeriodService.php';
require_once __DIR__ . '/../../lib/InventoryService.php';

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

header('Content-Type: application/json');

// Parse input
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON input']);
    exit;
}

try {
    $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;
    $qty    = isset($data['qty']) ? (float)$data['qty'] : 0.0;
    $date   = isset($data['date']) ? trim($data['date']) : '';
    $refType = isset($data['ref_type']) ? trim($data['ref_type']) : 'return';
    $refId   = $data['ref_id'] ?? null;
    if (!$itemId || $qty <= 0.0001 || !$date) {
        throw new Exception('Missing or invalid item_id, qty or date');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }
    // Check period lock
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($date)) {
        throw new Exception('Cannot post return into locked period (' . $date . ')');
    }
    // Determine GL accounts
    $accounts = new AccountsMap($DB, $companyId);
    $invCode  = $accounts->code('finance_inventory_account_id');
    $cogsCode = $accounts->code('finance_cogs_account_id');
    if (!$invCode || !$cogsCode) {
        throw new Exception('Missing inventory or COGS account mapping');
    }

    // ONE transaction around movement + journal: the movement used to commit
    // in autocommit BEFORE the transaction started, so a failed journal left
    // phantom stock (and a retry doubled it). The journal also posted with
    // default status 'draft', invisible to every posted-only report.
    $DB->beginTransaction();

    $inventory = new InventoryService($DB, $companyId);
    $unitCost = $inventory->getAverageCost($itemId);
    $totalCost = $inventory->receive($itemId, $qty, $unitCost, $date, $refType, $refId);
    $movementId = (int)$DB->lastInsertId();

    require_once __DIR__ . '/../../lib/PostingService.php';
    $reference = 'RET' . $itemId . '-' . date('Ymd');
    $posting = new PostingService($DB, (int)$companyId, (int)$userId);
    $journalId = $posting->postAdHocJournal([
        'entry_date'  => $date,
        'reference'   => $reference,
        'description' => 'Inventory return for item ' . $itemId,
        'module'      => 'fin',
        'ref_type'    => 'inventory_return',
        'ref_id'      => $itemId,
        'source_type' => 'inventory',
        'source_id'   => $itemId,
    ], [
        [
            'account_code' => $invCode,
            'description'  => 'Return to inventory',
            'debit'        => round($totalCost, 2),
            'credit'       => 0,
        ],
        [
            'account_code' => $cogsCode,
            'description'  => 'Reverse COGS on return',
            'debit'        => 0,
            'credit'       => round($totalCost, 2),
        ],
    ]);

    // Link the movement to its journal so a future reversal of this journal
    // reverses the stock too (reverseMovementsForJournal matches journal_id).
    $DB->prepare("UPDATE inventory_movements SET journal_id = ? WHERE id = ? AND company_id = ?")
        ->execute([$journalId, $movementId, $companyId]);

    $DB->commit();
    echo json_encode(['ok' => true, 'data' => ['journal_id' => $journalId, 'unit_cost' => $unitCost]]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    json_exception($e);
}
?>