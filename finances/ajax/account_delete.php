<?php
// /finances/ajax/account_delete.php — hard delete (when safe)
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/CoaSchema.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];
$input     = json_decode(file_get_contents('php://input'), true);
$accountId = $input['account_id'] ?? null;

if (!$accountId) {
    echo json_encode(['ok' => false, 'error' => 'Account ID required']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("
        SELECT account_id, account_code, account_name, is_system, is_locked
        FROM gl_accounts WHERE account_id = ? AND company_id = ?
    ");
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) throw new Exception('Account not found');
    if ($account['is_system'])  throw new Exception('Cannot delete a system account. Deactivate instead.');
    if ($account['is_locked'])  throw new Exception('Cannot delete a SARS-statutory / locked account');

    // Check for any journal lines referencing this account
    $lineCount = CoaSchema::postedLineCount($DB, $companyId, $account['account_code']);
    if ($lineCount > 0) {
        throw new Exception('Cannot delete — account has ' . $lineCount . ' journal line(s). Deactivate instead.');
    }

    // Check for children
    $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE parent_id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Cannot delete — account has sub-accounts. Remove children first.');
    }

    // Safe to delete
    $stmt = $DB->prepare("DELETE FROM gl_accounts WHERE account_id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'account_deleted', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['account_id' => $accountId, 'code' => $account['account_code'], 'name' => $account['account_name']]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Account delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
