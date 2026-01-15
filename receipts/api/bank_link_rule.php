<?php
// Creates or updates a bank rule for a supplier. Suggests GL account and project based on last bill.

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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$supplierId = isset($input['supplier_id']) ? (int)$input['supplier_id'] : 0;
$pattern = isset($input['pattern']) ? trim($input['pattern']) : '';

if (!$supplierId) {
    echo json_encode(['ok' => false, 'error' => 'Missing supplier_id']);
    exit;
}

// Determine supplier name if pattern not provided
$stmt = $DB->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ? AND type = 'supplier'");
$stmt->execute([$supplierId, $companyId]);
$acc = $stmt->fetch();
if (!$acc) {
    echo json_encode(['ok' => false, 'error' => 'Supplier not found']);
    exit;
}
if ($pattern === '') {
    $pattern = $acc['name'];
}

// Determine last used GL account for this supplier
$stmt = $DB->prepare(
    "SELECT abl.gl_account_id
     FROM ap_bills ab
     JOIN ap_bill_lines abl ON abl.bill_id = ab.id
     WHERE ab.company_id = ? AND ab.supplier_id = ? AND abl.gl_account_id IS NOT NULL
     ORDER BY ab.id DESC LIMIT 1"
);
$stmt->execute([$companyId, $supplierId]);
$glRow = $stmt->fetch();
$glAccountId = null;
if ($glRow && !empty($glRow['gl_account_id'])) {
    $glAccountId = (int)$glRow['gl_account_id'];
}
// Fallback to default expense account 5000 if not found
if (!$glAccountId) {
    $stmt = $DB->prepare("SELECT account_id FROM gl_accounts WHERE company_id = ? AND account_code = '5000' LIMIT 1");
    $stmt->execute([$companyId]);
    $accRow = $stmt->fetch();
    if ($accRow && !empty($accRow['account_id'])) {
        $glAccountId = (int)$accRow['account_id'];
    }
}
if (!$glAccountId) {
    echo json_encode(['ok' => false, 'error' => 'No GL account found to assign to rule']);
    exit;
}

// Construct rule name
$ruleName = 'AP: ' . $acc['name'];

// Check if a similar rule already exists (same pattern and GL account)
$stmt = $DB->prepare("SELECT id FROM gl_bank_rules WHERE company_id = ? AND match_value = ? AND gl_account_id = ? LIMIT 1");
$stmt->execute([$companyId, $pattern, $glAccountId]);
$existing = $stmt->fetch();
if ($existing) {
    echo json_encode(['ok' => true]);
    exit;
}

// Insert new rule
try {
    $stmt = $DB->prepare("INSERT INTO gl_bank_rules (
            company_id, rule_name, match_field, match_operator, match_value,
            gl_account_id, description_template, priority, is_active, created_at, created_by
        ) VALUES (?, ?, 'description', 'contains', ?, ?, ?, 100, 1, NOW(), ?)");
    $stmt->execute([
        $companyId,
        $ruleName,
        $pattern,
        $glAccountId,
        $pattern,
        $userId
    ]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('Bank rule error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save bank rule']);
}