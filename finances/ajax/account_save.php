<?php
// /finances/ajax/account_save.php — SARS bookkeeping-grade
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/CoaSchema.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// CSRF
Csrf::validate();

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];
$input     = json_decode(file_get_contents('php://input'), true);

// --- Extract & trim input ---------------------------------------------------
$accountId      = $input['account_id'] ?? null;
$accountCode    = trim($input['account_code'] ?? '');
$accountName    = trim($input['account_name'] ?? '');
$accountType    = $input['account_type'] ?? '';
$normalBalance  = $input['normal_balance'] ?? '';
$accountSubtype = $input['account_subtype'] ?? '';
$description    = trim($input['description'] ?? '');
$parentId       = $input['parent_id'] ?: null;
$taxCodeId      = $input['tax_code_id'] ?: null;
$isActive       = isset($input['is_active']) ? (int)$input['is_active'] : 1;

// --- Basic required-field validation ----------------------------------------
if (!$accountCode || !$accountName || !$accountType) {
    echo json_encode(['ok' => false, 'error' => 'Account code, name and type are required']);
    exit;
}

$validTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'];
if (!in_array($accountType, $validTypes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid account type']);
    exit;
}

// --- Default normal_balance if not supplied ----------------------------------
if (!$normalBalance) {
    $normalBalance = CoaSchema::defaultNormalBalance($accountType);
}
if (!in_array($normalBalance, ['debit', 'credit'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid normal balance']);
    exit;
}

// --- Subtype validation ------------------------------------------------------
if ($accountSubtype !== '' && !CoaSchema::isValidSubtype($accountType, $accountSubtype)) {
    echo json_encode(['ok' => false, 'error' => "Subtype '$accountSubtype' is not valid for type '$accountType'"]);
    exit;
}

// --- Account code must fall within the type's numeric band -------------------
if (!CoaSchema::isCodeInBand($accountType, $accountCode)) {
    $bands = CoaSchema::codeBands()[$accountType];
    $ranges = array_map(fn($b) => $b[0] . '-' . $b[1], $bands);
    echo json_encode(['ok' => false, 'error' => 'Account code must be in range ' . implode(' or ', $ranges) . ' for type ' . $accountType]);
    exit;
}

// --- Parent validation -------------------------------------------------------
if ($parentId) {
    $parentId = (int)$parentId;
    $selfId = $accountId ? (int)$accountId : null;
    $parentError = CoaSchema::validateParent($DB, $companyId, $parentId, $accountType, $selfId);
    if ($parentError) {
        echo json_encode(['ok' => false, 'error' => $parentError]);
        exit;
    }
}

// --- Description length cap --------------------------------------------------
if (mb_strlen($description) > 500) {
    $description = mb_substr($description, 0, 500);
}

try {
    $DB->beginTransaction();

    if ($accountId) {
        // === UPDATE existing account =========================================
        $accountId = (int)$accountId;

        // Fetch existing row (for lock/system checks and audit diff)
        $stmt = $DB->prepare("SELECT * FROM gl_accounts WHERE account_id = ? AND company_id = ?");
        $stmt->execute([$accountId, $companyId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new Exception('Account not found');
        }

        // Locked accounts: only description & tax_code may be changed
        if ($existing['is_locked']) {
            $stmt = $DB->prepare("
                UPDATE gl_accounts SET
                    description = ?, tax_code_id = ?, updated_at = NOW(), updated_by = ?
                WHERE account_id = ? AND company_id = ?
            ");
            $stmt->execute([$description, $taxCodeId, $userId, $accountId, $companyId]);

            $action = 'account_updated';
            $changes = [];
            if ($existing['description'] !== $description) $changes['description'] = [$existing['description'], $description];
            if ($existing['tax_code_id'] != $taxCodeId) $changes['tax_code_id'] = [$existing['tax_code_id'], $taxCodeId];
            $details = json_encode(['account_id' => $accountId, 'code' => $existing['account_code'], 'locked' => true, 'changes' => $changes]);

        } else {
            // System accounts: structural fields are immutable — only the name,
            // description, parent, tax code and (guarded below) active flag may
            // change. Deactivation is blocked separately below.
            if ($existing['is_system']) {
                if ($existing['account_code'] !== $accountCode
                    || $existing['account_type'] !== $accountType
                    || (string)$existing['account_subtype'] !== $accountSubtype
                    || $existing['normal_balance'] !== $normalBalance) {
                    throw new Exception('Cannot change the code, type, subtype or normal balance of a system account');
                }
            }

            // Duplicate account_code check (company-scoped, excluding this account) —
            // mirrors the INSERT path so a rename cannot collide with an existing code
            if ($existing['account_code'] !== $accountCode) {
                $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE company_id = ? AND account_code = ? AND account_id <> ?");
                $stmt->execute([$companyId, $accountCode, $accountId]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Account code already exists');
                }
            }

            // Prevent changing account_code or account_type if journals reference it
            if ($existing['account_code'] !== $accountCode || $existing['account_type'] !== $accountType) {
                $lineCount = CoaSchema::postedLineCount($DB, $companyId, $existing['account_code']);
                if ($lineCount > 0) {
                    throw new Exception('Cannot change code/type — account has ' . $lineCount . ' posted journal line(s)');
                }
            }

            // Prevent deactivation if there are posted lines with non-zero balance
            if ($isActive === 0 && $existing['is_active'] == 1) {
                if ($existing['is_system']) {
                    throw new Exception('Cannot deactivate a system account');
                }
                $lineCount = CoaSchema::postedLineCount($DB, $companyId, $existing['account_code']);
                if ($lineCount > 0) {
                    throw new Exception('Cannot deactivate — account has posted journal entries. Transfer balances first.');
                }
            }

            // Build diff for audit
            $changes = [];
            $fields = ['account_code', 'account_name', 'account_type', 'normal_balance', 'account_subtype', 'description', 'parent_id', 'tax_code_id', 'is_active'];
            $newVals = [
                'account_code' => $accountCode, 'account_name' => $accountName, 'account_type' => $accountType,
                'normal_balance' => $normalBalance, 'account_subtype' => $accountSubtype, 'description' => $description,
                'parent_id' => $parentId, 'tax_code_id' => $taxCodeId, 'is_active' => $isActive,
            ];
            foreach ($fields as $f) {
                if ((string)($existing[$f] ?? '') !== (string)($newVals[$f] ?? '')) {
                    $changes[$f] = [$existing[$f], $newVals[$f]];
                }
            }

            $stmt = $DB->prepare("
                UPDATE gl_accounts SET
                    account_code = ?, account_name = ?, description = ?,
                    account_type = ?, normal_balance = ?, account_subtype = ?,
                    parent_id = ?, tax_code_id = ?, is_active = ?,
                    updated_at = NOW(), updated_by = ?
                WHERE account_id = ? AND company_id = ?
            ");
            $stmt->execute([
                $accountCode, $accountName, $description,
                $accountType, $normalBalance, $accountSubtype,
                $parentId, $taxCodeId, $isActive,
                $userId, $accountId, $companyId
            ]);

            $action = 'account_updated';
            $details = json_encode(['account_id' => $accountId, 'code' => $accountCode, 'changes' => $changes]);
        }

    } else {
        // === INSERT new account ==============================================
        // Check for duplicate code
        $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE company_id = ? AND account_code = ?");
        $stmt->execute([$companyId, $accountCode]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Account code already exists');
        }

        $stmt = $DB->prepare("
            INSERT INTO gl_accounts (
                company_id, account_code, account_name, description,
                account_type, normal_balance, account_subtype,
                parent_id, tax_code_id,
                is_system, is_control, is_locked, allow_manual_journal,
                is_active, currency, created_by, updated_by,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 1, ?, 'ZAR', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $companyId, $accountCode, $accountName, $description,
            $accountType, $normalBalance, $accountSubtype,
            $parentId, $taxCodeId,
            $isActive, $userId, $userId
        ]);

        $accountId = $DB->lastInsertId();
        $action = 'account_created';
        $details = json_encode([
            'account_id' => $accountId, 'code' => $accountCode,
            'name' => $accountName, 'type' => $accountType,
            'subtype' => $accountSubtype, 'normal_balance' => $normalBalance,
        ]);
    }

    // --- Audit log -----------------------------------------------------------
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$companyId, $userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);

    $DB->commit();

    echo json_encode(['ok' => true, 'data' => ['account_id' => $accountId]]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Account save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
