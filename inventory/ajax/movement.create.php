<?php
// inventory/ajax/movement.create.php
// Endpoint to record a new inventory movement (receipt or issue).

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
require_once __DIR__ . '/../../finances/lib/InventoryService.php';

// A manual movement now posts to the GL at an arbitrary cost, so restrict it to
// finance roles — the weaker 'member' role must not move stock value.
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// CSRF header check
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrfHeader || $csrfHeader !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Parse inputs
$itemId   = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$date     = trim($_POST['date'] ?? '');
$qty      = isset($_POST['qty']) ? floatval($_POST['qty']) : 0.0;
$unitCost = isset($_POST['unit_cost']) ? floatval($_POST['unit_cost']) : 0.0;

if (!$itemId || !$date) {
    echo json_encode(['success' => false, 'message' => 'Item ID and date are required']);
    exit;
}

// Validate date format (YYYY-mm-dd)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

if (abs($qty) < 0.0001) {
    echo json_encode(['success' => false, 'message' => 'Quantity must be non‑zero']);
    exit;
}

try {
    $companyId = (int)$_SESSION['company_id'];
    $userId    = (int)$_SESSION['user_id'];
    // Ensure the item exists and belongs to company
    $stmt = $DB->prepare(
        "SELECT id FROM inventory_items WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$itemId, $companyId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Item not found']);
        exit;
    }
    if ($qty > 0 && $unitCost <= 0) {
        echo json_encode(['success' => false, 'message' => 'Unit cost must be greater than zero for receipts']);
        exit;
    }

    require_once __DIR__ . '/../../finances/lib/PostingService.php';
    require_once __DIR__ . '/../../finances/lib/AccountsMap.php';
    $map = new AccountsMap($DB, $companyId);
    $invCode = $map->code('finance_inventory_account_id');
    // Offset: a dedicated stock-adjustment account where the chart has one,
    // otherwise COGS. A manual movement is a stock adjustment (found / lost /
    // corrected), so the contra is an adjustment account, not another asset.
    $adjStmt = $DB->prepare(
        "SELECT account_code FROM gl_accounts
          WHERE company_id = ? AND is_active = 1
            AND (account_name LIKE 'Stock Adjustment%' OR account_name LIKE 'Inventory Adjustment%'
                 OR account_name LIKE 'Stock Write-off%')
          ORDER BY account_code LIMIT 1"
    );
    $adjStmt->execute([$companyId]);
    $adjCode = $adjStmt->fetchColumn() ?: $map->code('finance_cogs_account_id');

    $DB->beginTransaction();
    $inventoryService = new InventoryService($DB, $companyId);
    $posting = new PostingService($DB, $companyId, $userId);

    if ($qty > 0) {
        $cost = $inventoryService->receive($itemId, $qty, $unitCost, $date, 'manual', null);
        $movementId = (int)$DB->lastInsertId();
        // Dr Inventory / Cr Stock Adjustment
        $jLines = [
            ['account_code' => $invCode, 'description' => 'Manual stock receipt', 'debit' => round($cost, 2), 'credit' => 0],
            ['account_code' => $adjCode, 'description' => 'Manual stock receipt', 'debit' => 0, 'credit' => round($cost, 2)],
        ];
    } else {
        // Issue: absolute quantity; the service computes the weighted-avg cost.
        $cost = $inventoryService->issue($itemId, abs($qty), $date, 'manual', null);
        $movementId = (int)$DB->lastInsertId();
        // Dr Stock Adjustment / Cr Inventory
        $jLines = [
            ['account_code' => $adjCode, 'description' => 'Manual stock issue', 'debit' => round($cost, 2), 'credit' => 0],
            ['account_code' => $invCode, 'description' => 'Manual stock issue', 'debit' => 0, 'credit' => round($cost, 2)],
        ];
    }

    // postAdHocJournal enforces the period lock (throws if locked) and inserts a
    // balanced journal, then we stamp journal_id on the movement. Without this a
    // manual movement silently diverged the GL inventory account from
    // stock-on-hand, and could be dated into a locked period.
    if (round($cost, 2) > 0) {
        $journalId = $posting->postAdHocJournal([
            'entry_date'  => $date,
            'reference'   => 'INVADJ-' . $movementId,
            'description' => 'Manual inventory movement #' . $movementId,
            'ref_type'    => 'inventory_adjust',
            'ref_id'      => $movementId,
            'source_type' => 'inventory_adjust',
            'source_id'   => $movementId,
        ], $jLines);
        $DB->prepare("UPDATE inventory_movements SET journal_id = ? WHERE id = ? AND company_id = ?")
           ->execute([$journalId, $movementId, $companyId]);
    }

    $DB->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($DB->inTransaction()) { $DB->rollBack(); }
    error_log('Inventory movement create error: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Failed to record the movement' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $msg]);
}
