<?php
// /finances/inventory/api/return_issue.php
// Endpoint to process inventory returns. Receives a quantity of an item back into
// stock at the current weighted average cost and posts a reversing COGS/Inventory
// journal entry.

require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../auth_gate.php';

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
    exit;
}

// CSRF validation
require_once __DIR__ . '/../../../lib/Csrf.php';
Csrf::validate();

// Restrict access to admins and bookkeepers
require_once __DIR__ . '/../../../permissions.php';
requireRoles(['admin', 'bookkeeper']);

require_once __DIR__ . '/../../../lib/AccountsMap.php';
require_once __DIR__ . '/../../../lib/PeriodService.php';
require_once __DIR__ . '/../../../lib/InventoryService.php';

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
    // Compute current average cost and record receipt
    $inventory = new InventoryService($DB, $companyId);
    $unitCost = $inventory->getAverageCost($itemId);
    // Record the return as a receipt
    $totalCost = $inventory->receive($itemId, $qty, $unitCost, $date, $refType, $refId);
    // Determine GL accounts
    $accounts = new AccountsMap($DB, $companyId);
    $invCode  = $accounts->get('finance_inventory_account_id', '1300');
    $cogsCode = $accounts->get('finance_cogs_account_id', '5000');
    if (!$invCode || !$cogsCode) {
        throw new Exception('Missing inventory or COGS account mapping');
    }
    // Begin transaction for GL entry
    $DB->beginTransaction();
    // Create journal entry
    $reference = 'RET' . $itemId . '-' . date('Ymd');
    $description = 'Inventory return for item ' . $itemId;
    $stmtJ = $DB->prepare(
        "INSERT INTO journal_entries (
            company_id, entry_date, reference, description,
            module, ref_type, ref_id, source_type, source_id,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, 'fin', 'inventory_return', ?, 'inventory', ?, ?, NOW())"
    );
    $stmtJ->execute([
        $companyId,
        $date,
        $reference,
        $description,
        $itemId,
        $itemId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();
    // Insert inventory debit and COGS credit
    $stmtL = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit)
         VALUES (?, ?, ?, ?, ?)"
    );
    // Debit inventory (increase asset)
    $stmtL->execute([
        $journalId,
        $invCode,
        'Return to inventory',
        number_format($totalCost, 2, '.', ''),
        '0.00'
    ]);
    // Credit COGS (reduce expense)
    $stmtL->execute([
        $journalId,
        $cogsCode,
        'Reverse COGS on return',
        '0.00',
        number_format($totalCost, 2, '.', '')
    ]);
    $DB->commit();
    echo json_encode(['ok' => true, 'data' => ['journal_id' => $journalId, 'unit_cost' => $unitCost]]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>