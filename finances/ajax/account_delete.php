<?php
// /finances/ajax/account_delete.php — hard delete (when safe)
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/http.php';
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
$accountId = (int)($input['account_id'] ?? 0);

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

    // Check for posted journal lines
    $postedCount = CoaSchema::postedLineCount($DB, $companyId, $account['account_code']);
    if ($postedCount > 0) {
        throw new Exception('Cannot delete — account has ' . $postedCount . ' posted journal line(s). Deactivate instead.');
    }

    // Check for draft/prepared journal lines that would be orphaned
    $allCount = CoaSchema::allLineCount($DB, $companyId, $account['account_code']);
    if ($allCount > 0) {
        throw new Exception('Cannot delete — account has ' . $allCount . ' draft/prepared journal line(s). Delete or reassign them first.');
    }

    // Check for children
    $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE parent_id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Cannot delete — account has sub-accounts. Remove children first.');
    }

    // Check other records that reference this account by id (company-scoped)
    $referencedBy = [];
    $refChecks = [
        'bank accounts (GL mapping)' => ["SELECT EXISTS(SELECT 1 FROM gl_bank_accounts WHERE company_id = ? AND gl_account_id = ?)", [$companyId, $accountId]],
        'bank rules'                 => ["SELECT EXISTS(SELECT 1 FROM gl_bank_rules WHERE company_id = ? AND gl_account_id = ?)", [$companyId, $accountId]],
        'budgets'                    => ["SELECT EXISTS(SELECT 1 FROM gl_budgets WHERE company_id = ? AND gl_account_id = ?)", [$companyId, $accountId]],
        'fixed assets'               => ["SELECT EXISTS(SELECT 1 FROM gl_fixed_assets WHERE company_id = ? AND (asset_account_id = ? OR depreciation_expense_account_id = ? OR accumulated_depreciation_account_id = ?))", [$companyId, $accountId, $accountId, $accountId]],
    ];
    foreach ($refChecks as $label => [$sql, $params]) {
        $stmt = $DB->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) { $referencedBy[] = $label; }
    }

    // company_settings finance_*_account_id mappings may store the account_id
    // or the account_code (see AccountsMap::get)
    $stmt = $DB->prepare("
        SELECT setting_key FROM company_settings
        WHERE company_id = ?
          AND setting_key LIKE 'finance%account_id'
          AND setting_value IN (?, ?)
    ");
    $stmt->execute([$companyId, (string)$accountId, $account['account_code']]);
    $settingKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($settingKeys) {
        $referencedBy[] = 'finance settings (' . implode(', ', $settingKeys) . ')';
    }

    if ($referencedBy) {
        throw new Exception('Cannot delete — account is still referenced by: ' . implode('; ', $referencedBy) . '. Update those references first, or deactivate the account instead.');
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
    json_exception($e);
}
