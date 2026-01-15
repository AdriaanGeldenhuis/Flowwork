<?php
// /finances/ajax/account_save.php
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
$accountCode = trim($input['account_code'] ?? '');
$accountName = trim($input['account_name'] ?? '');
$accountType = $input['account_type'] ?? '';
$parentId = $input['parent_id'] ?: null;
$taxCodeId = $input['tax_code_id'] ?: null;
$isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

// Validation
if (!$accountCode || !$accountName || !$accountType) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

if (!in_array($accountType, ['asset', 'liability', 'equity', 'revenue', 'expense'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid account type']);
    exit;
}

try {
    $DB->beginTransaction();

    if ($accountId) {
        // Update existing account
        $stmt = $DB->prepare("
            UPDATE gl_accounts 
            SET 
                account_code = ?,
                account_name = ?,
                account_type = ?,
                parent_id = ?,
                tax_code_id = ?,
                is_active = ?,
                updated_at = NOW()
            WHERE account_id = ? AND company_id = ?
        ");
        $stmt->execute([
            $accountCode,
            $accountName,
            $accountType,
            $parentId,
            $taxCodeId,
            $isActive,
            $accountId,
            $companyId
        ]);

        $action = 'account_updated';
        $details = json_encode(['account_id' => $accountId, 'code' => $accountCode]);

    } else {
        // Check for duplicate code
        $stmt = $DB->prepare("
            SELECT COUNT(*) FROM gl_accounts 
            WHERE company_id = ? AND account_code = ?
        ");
        $stmt->execute([$companyId, $accountCode]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Account code already exists');
        }

        // Insert new account
        $stmt = $DB->prepare("
            INSERT INTO gl_accounts (
                company_id, account_code, account_name, account_type, 
                parent_id, tax_code_id, is_system, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $companyId,
            $accountCode,
            $accountName,
            $accountType,
            $parentId,
            $taxCodeId,
            $isActive
        ]);

        $accountId = $DB->lastInsertId();
        $action = 'account_created';
        $details = json_encode(['account_id' => $accountId, 'code' => $accountCode]);
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => ['account_id' => $accountId]
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Account save error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}