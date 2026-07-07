<?php
// /finances/ajax/account_toggle.php — SARS bookkeeping-grade
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/CoaSchema.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];
$input     = json_decode(file_get_contents('php://input'), true);
$accountId = (int)($input['account_id'] ?? 0);

if (!$accountId) {
    echo json_encode(['ok' => false, 'error' => 'Account ID required']);
    exit;
}

try {
    $DB->beginTransaction();

    // Fetch current row
    $stmt = $DB->prepare("
        SELECT account_id, account_code, account_name, account_type,
               normal_balance, is_active, is_system, is_locked
        FROM gl_accounts
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception('Account not found');
    }

    if ($account['is_system']) {
        throw new Exception('Cannot modify system accounts');
    }

    if ($account['is_locked']) {
        throw new Exception('Cannot modify SARS-statutory / locked accounts');
    }

    // If deactivating, check for posted journal lines
    if ($account['is_active'] == 1) {
        $lineCount = CoaSchema::postedLineCount($DB, $companyId, $account['account_code']);
        if ($lineCount > 0) {
            throw new Exception('Cannot deactivate — account has posted journal entries. Transfer balances first.');
        }
    }

    $newStatus = $account['is_active'] == 1 ? 0 : 1;

    $stmt = $DB->prepare("
        UPDATE gl_accounts
        SET is_active = ?, updated_at = NOW(), updated_by = ?
        WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$newStatus, $userId, $accountId, $companyId]);

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
            'old_status' => (int)$account['is_active'],
            'new_status' => $newStatus,
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    if ($DB->inTransaction()) { $DB->rollBack(); }
    error_log("Account toggle error: " . $e->getMessage());
    // Route through json_exception so PDO/engine detail isn't leaked to the
    // client (business-rule messages still pass through).
    json_exception($e);
}
