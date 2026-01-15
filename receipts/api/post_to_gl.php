<?php
// Posts an AP bill to the general ledger. Creates a journal entry and lines then marks the bill as posted.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

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

$input = json_decode(file_get_contents('php://input'), true);
$billId = isset($input['bill_id']) ? (int)$input['bill_id'] : 0;

if (!$billId) {
    echo json_encode(['ok' => false, 'error' => 'Missing bill_id']);
    exit;
}

// Fetch bill
$stmt = $DB->prepare("SELECT status FROM ap_bills WHERE id = ? AND company_id = ?");
$stmt->execute([$billId, $companyId]);
$bill = $stmt->fetch();
if (!$bill) {
    echo json_encode(['ok' => false, 'error' => 'Bill not found']);
    exit;
}

// If already posted, return success
if ($bill['status'] === 'posted') {
    echo json_encode(['ok' => true]);
    exit;
}

// Fetch full bill details including supplier and invoice number
$stmt = $DB->prepare("SELECT supplier_id, issue_date, vendor_invoice_number, subtotal, tax, total, status FROM ap_bills WHERE id = ? AND company_id = ?");
$stmt->execute([$billId, $companyId]);
$billInfo = $stmt->fetch();
if (!$billInfo) {
    echo json_encode(['ok' => false, 'error' => 'Bill not found']);
    exit;
}

// Load company finance settings
function getCompanySetting(PDO $DB, int $companyId, string $key) {
    $stmt = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1");
    $stmt->execute([$companyId, $key]);
    $row = $stmt->fetch();
    return $row ? trim($row['setting_value']) : '';
}

// Helper to resolve an account code from an account_id (gl_accounts table)
function resolveAccountCode(PDO $DB, int $companyId, $accountId) {
    if (!$accountId) {
        return null;
    }
    $stmt = $DB->prepare("SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_id = ? LIMIT 1");
    $stmt->execute([$companyId, $accountId]);
    $row = $stmt->fetch();
    return $row ? $row['account_code'] : null;
}

// Determine AP and VAT Input accounts from settings; fall back to defaults if not set
$apAccountId = getCompanySetting($DB, $companyId, 'finance_ap_account_id');
$vatAccountId = getCompanySetting($DB, $companyId, 'finance_vat_input_account_id');
// Use numeric id if present, else empty string
$apAccountCode = null;
if ($apAccountId !== '') {
    $apAccountCode = resolveAccountCode($DB, $companyId, (int)$apAccountId);
}
// Default AP to 2110 if not resolved
if (!$apAccountCode) {
    $apAccountCode = '2110';
}
$vatAccountCode = null;
if ($vatAccountId !== '') {
    $vatAccountCode = resolveAccountCode($DB, $companyId, (int)$vatAccountId);
}
// Default VAT Input to 2130 if not resolved
if (!$vatAccountCode) {
    $vatAccountCode = '2130';
}

// Fetch bill lines
$stmt = $DB->prepare("SELECT quantity, unit_price, tax_rate, gl_account_id, project_board_id, project_item_id, item_description FROM ap_bill_lines WHERE bill_id = ? ORDER BY sort_order");
$stmt->execute([$billId]);
$billLines = $stmt->fetchAll();
if (!$billLines) {
    echo json_encode(['ok' => false, 'error' => 'No bill lines to post']);
    exit;
}

// Prepare journal lines
$journalLines = [];
$totalNet = 0.0;
$totalVat = 0.0;
$supplierIdForLines = (int)$billInfo['supplier_id'];

// Track cost allocations per board/item for later project costing write-back
$costAllocations = [];

foreach ($billLines as $line) {
    $qty = (float)$line['quantity'];
    $price = (float)$line['unit_price'];
    $taxRate = isset($line['tax_rate']) ? (float)$line['tax_rate'] : 0.0;
    $net = $qty * $price;
    $vat = ($taxRate > 0 ? $net * ($taxRate / 100.0) : 0.0);
    $expenseAccountCode = null;
    if (!empty($line['gl_account_id'])) {
        $expenseAccountCode = resolveAccountCode($DB, $companyId, (int)$line['gl_account_id']);
    }
    if (!$expenseAccountCode) {
        // Fallback to generic expense 5000 if not provided
        $expenseAccountCode = '5000';
    }
    $journalLines[] = [
        'account_code' => $expenseAccountCode,
        'description' => $line['item_description'],
        'debit' => $net,
        'credit' => 0.0,
        'project_id' => null,
        'board_id' => $line['project_board_id'] ? (int)$line['project_board_id'] : null,
        'item_id' => $line['project_item_id'] ? (int)$line['project_item_id'] : null,
        'supplier_id' => $supplierIdForLines
    ];
    $totalNet += $net;
    $totalVat += $vat;

    // Accumulate cost for project costing write-back
    $bId = $line['project_board_id'] ? (int)$line['project_board_id'] : null;
    $iId = $line['project_item_id'] ? (int)$line['project_item_id'] : null;
    if ($bId && $iId) {
        if (!isset($costAllocations[$bId])) {
            $costAllocations[$bId] = [];
        }
        if (!isset($costAllocations[$bId][$iId])) {
            $costAllocations[$bId][$iId] = 0.0;
        }
        $costAllocations[$bId][$iId] += $net;
    }
}

// VAT line if applicable
if ($totalVat > 0) {
    $journalLines[] = [
        'account_code' => $vatAccountCode,
        'description' => 'VAT on ' . $billInfo['vendor_invoice_number'],
        'debit' => $totalVat,
        'credit' => 0.0,
        'project_id' => null,
        'board_id' => null,
        'item_id' => null,
        'supplier_id' => $supplierIdForLines
    ];
}

// AP credit line
// Total gross is net + vat (should equal bill total)
$grossAmount = $totalNet + $totalVat;
$journalLines[] = [
    'account_code' => $apAccountCode,
    'description' => 'AP: ' . $billInfo['vendor_invoice_number'],
    'debit' => 0.0,
    'credit' => $grossAmount,
    'project_id' => null,
    'board_id' => null,
    'item_id' => null,
    'supplier_id' => $supplierIdForLines
];

try {
    $DB->beginTransaction();
    // Create journal entry header
    $stmt = $DB->prepare(
        "INSERT INTO journal_entries (
            company_id, entry_date, reference, description, module, ref_type, ref_id, source_type, source_id, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $entryDate = $billInfo['issue_date'];
    $reference = $billInfo['vendor_invoice_number'];
    $description = 'AP Bill ' . $reference;
    $module = 'finance';
    $refType = 'ap_bill';
    $sourceType = 'receipt';
    $sourceId = $billId;
    $stmt->execute([
        $companyId,
        $entryDate,
        $reference,
        $description,
        $module,
        $refType,
        $billId,
        $sourceType,
        $sourceId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();
    // Insert journal lines
    $stmtLine = $DB->prepare(
        "INSERT INTO journal_lines (
            journal_id, account_code, description, debit, credit, project_id, board_id, item_id, tax_code_id, customer_id, supplier_id, reference
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)"
    );
    foreach ($journalLines as $jl) {
        $stmtLine->execute([
            $journalId,
            $jl['account_code'],
            $jl['description'],
            number_format($jl['debit'], 2, '.', ''),
            number_format($jl['credit'], 2, '.', ''),
            $jl['project_id'],
            $jl['board_id'],
            $jl['item_id'],
            $jl['supplier_id'],
            $reference
        ]);
    }
    // Update bill status and link journal
    $stmt = $DB->prepare("UPDATE ap_bills SET status = 'posted', journal_id = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $billId, $companyId]);

    /*
     * Project costing write-back
     * For each board/item pair that received expenses, add the net amount to a number column.
     * Tries to use an existing number column if present; otherwise creates a new "Cost to date" column.
     */
    // Helper to fetch or create a number column on a board
    $numberColumnCache = [];
    $getNumberColumnId = function(int $boardId) use ($DB, $companyId, &$numberColumnCache) {
        if (isset($numberColumnCache[$boardId])) {
            return $numberColumnCache[$boardId];
        }
        // Try to find an existing number column on this board
        $stmt = $DB->prepare("SELECT column_id FROM board_columns WHERE board_id = ? AND company_id = ? AND type = 'number' ORDER BY sort_order LIMIT 1");
        $stmt->execute([$boardId, $companyId]);
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($col) {
            $numberColumnCache[$boardId] = (int)$col['column_id'];
            return $numberColumnCache[$boardId];
        }
        // Otherwise create a new number column named 'Cost to date'
        // Determine sort order
        $stmt = $DB->prepare("SELECT IFNULL(MAX(sort_order), 0) AS max_sort FROM board_columns WHERE board_id = ? AND company_id = ?");
        $stmt->execute([$boardId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $sortOrder = ($row && $row['max_sort'] !== null) ? ((int)$row['max_sort'] + 1) : 1;
        $stmt = $DB->prepare("INSERT INTO board_columns (board_id, company_id, name, type, position, sort_order, visible, width, created_at) VALUES (?, ?, 'Cost to date', 'number', ?, ?, 1, 150, NOW())");
        $stmt->execute([$boardId, $companyId, $sortOrder, $sortOrder]);
        $newColumnId = (int)$DB->lastInsertId();
        $numberColumnCache[$boardId] = $newColumnId;
        return $newColumnId;
    };
    // Apply cost allocations
    foreach ($costAllocations as $bId => $items) {
        $columnId = $getNumberColumnId($bId);
        foreach ($items as $iId => $amount) {
            // Fetch existing value
            $stmt = $DB->prepare("SELECT value FROM board_item_values WHERE item_id = ? AND column_id = ? LIMIT 1");
            $stmt->execute([$iId, $columnId]);
            $valRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($valRow && isset($valRow['value'])) {
                $existing = (float)$valRow['value'];
                $newVal = $existing + $amount;
                $stmt2 = $DB->prepare("UPDATE board_item_values SET value = ? WHERE item_id = ? AND column_id = ?");
                $stmt2->execute([number_format($newVal, 2, '.', ''), $iId, $columnId]);
            } else {
                $stmt2 = $DB->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
                $stmt2->execute([$iId, $columnId, number_format($amount, 2, '.', '')]);
            }
        }
    }

    $DB->commit();
    // Audit logging: record that the bill was posted
    try {
        $details = json_encode([
            'bill_id' => $billId,
            'journal_id' => $journalId,
            // total gross amount posted (net + VAT)
            'total' => $grossAmount
        ]);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmtAudit = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, 'ap_bill_posted', 'ap_bill', ?, ?, ?)");
        $stmtAudit->execute([$companyId, $userId, $billId, $details, $ip]);
    } catch (Exception $e) {
        // If audit fails, log but do not block success
        error_log('Audit log error (post_to_gl): ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'journal_id' => $journalId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Post to GL error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to post bill to GL']);
}