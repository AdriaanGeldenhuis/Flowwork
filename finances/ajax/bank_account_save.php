<?php
// /finances/ajax/bank_account_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
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

try {
    $DB->beginTransaction();

    $openingBalanceCents = round($openingBalance * 100);

    $stmt = $DB->prepare("
        INSERT INTO gl_bank_accounts (
            company_id, name, bank_name, account_no, gl_account_id,
            opening_balance_cents, current_balance_cents, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
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