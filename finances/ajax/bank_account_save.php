<?php
// /finances/ajax/bank_account_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();

// Role check
$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$name = trim($input['name'] ?? '');
$bankName = trim($input['bank_name'] ?? '');
$accountNo = trim($input['account_no'] ?? '');
$glAccountId = $input['gl_account_id'] ?? null;
$openingBalance = floatval($input['opening_balance'] ?? 0);

if (!$name) {
    echo json_encode(['ok' => false, 'error' => 'Account name required']);
    exit;
}

// Validate GL account belongs to this company
if ($glAccountId) {
    $stmt = $DB->prepare("SELECT account_id FROM gl_accounts WHERE account_id = ? AND company_id = ?");
    $stmt->execute([$glAccountId, $companyId]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Invalid GL account']);
        exit;
    }
}

try {
    $DB->beginTransaction();

    $openingBalanceCents = round($openingBalance * 100);

    $stmt = $DB->prepare("
        INSERT INTO gl_bank_accounts (
            company_id, name, bank_name, account_no, gl_account_id,
            opening_balance_cents, current_balance_cents, currency, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ZAR', 1, NOW())
    ");
    $stmt->execute([
        $companyId,
        $name,
        $bankName,
        $accountNo,
        $glAccountId,
        $openingBalanceCents,
        $openingBalanceCents
    ]);

    $bankAccountId = $DB->lastInsertId();

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'bank_account_created', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['bank_account_id' => $bankAccountId, 'name' => $name]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => ['bank_account_id' => $bankAccountId]
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Bank account save error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to save bank account'
    ]);
}