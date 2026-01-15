<?php
// /crm/ajax/contact_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $DB->beginTransaction();

    $accountId = (int)($_POST['account_id'] ?? 0);
    $contactId = !empty($_POST['id']) ? (int)$_POST['id'] : null;

    if (!$accountId) {
        throw new Exception('Account ID required');
    }

    // Verify account belongs to company
    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Account not found');
    }

    // Normalize phone
    $phone = trim($_POST['phone'] ?? '');
    if ($phone && !preg_match('/^\+/', $phone)) {
        $phone = '+27' . ltrim($phone, '0');
    }

    $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

    // If setting as primary, unset others
    if ($isPrimary) {
        $stmt = $DB->prepare("
            UPDATE crm_contacts 
            SET is_primary = 0 
            WHERE account_id = ? AND company_id = ?
        ");
        $stmt->execute([$accountId, $companyId]);
    }

    if ($contactId) {
        // UPDATE
        $stmt = $DB->prepare("
            UPDATE crm_contacts SET
                first_name = ?,
                last_name = ?,
                role_title = ?,
                phone = ?,
                email = ?,
                is_primary = ?,
                updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");

        $stmt->execute([
            trim($_POST['first_name'] ?? '') ?: null,
            trim($_POST['last_name'] ?? '') ?: null,
            trim($_POST['role_title'] ?? '') ?: null,
            $phone ?: null,
            trim($_POST['email'] ?? '') ?: null,
            $isPrimary,
            $contactId,
            $companyId
        ]);

    } else {
        // INSERT
        $stmt = $DB->prepare("
            INSERT INTO crm_contacts (
                company_id, account_id, first_name, last_name, role_title,
                phone, email, is_primary, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $companyId,
            $accountId,
            trim($_POST['first_name'] ?? '') ?: null,
            trim($_POST['last_name'] ?? '') ?: null,
            trim($_POST['role_title'] ?? '') ?: null,
            $phone ?: null,
            trim($_POST['email'] ?? '') ?: null,
            $isPrimary,
            $userId
        ]);

        $contactId = $DB->lastInsertId();
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, ?, 'crm_contact', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $contactId ? 'update' : 'create',
        $contactId,
        json_encode(['account_id' => $accountId])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'contact_id' => $contactId]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM contact_save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}