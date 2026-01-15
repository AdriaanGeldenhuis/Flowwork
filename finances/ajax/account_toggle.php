<?php
// /finances/ajax/account_toggle.php
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
$accountId = $input['account_id'] ?? null;

if (!$accountId) {
    echo json_encode(['ok' => false, 'error' => 'Account ID required']);
    exit;
}

try {
    $DB->beginTransaction();

    // Get current status
    $stmt = $DB->prepare("
        SELECT is_active, is_system, account_code 
        FROM gl_accounts 
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch();

    if (!$account) {
        throw new Exception('Account not found');
    }

    if ($account['is_system'] == 1) {
        throw new Exception('Cannot modify system accounts');
    }

    $newStatus = $account['is_active'] == 1 ? 0 : 1;

    // Update status
    $stmt = $DB->prepare("
        UPDATE gl_accounts 
        SET is_active = ?, updated_at = NOW()
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$newStatus, $accountId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'account_status_changed', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode([
            'account_id' => $accountId,
            'code' => $account['account_code'],
            'new_status' => $newStatus
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Account toggle error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}