<?php
// /crm/ajax/compliance_type_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check admin rights
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$userRole = $stmt->fetchColumn();

if (!in_array($userRole, ['admin', 'owner'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $DB->beginTransaction();

    $typeId = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');

    if (!$code || !$name) {
        throw new Exception('Code and name are required');
    }

    // Validate code format (alphanumeric, underscores only)
    if (!preg_match('/^[A-Z0-9_]+$/', $code)) {
        throw new Exception('Code must contain only uppercase letters, numbers, and underscores');
    }

    // Check for duplicate code
    $dupStmt = $DB->prepare("
        SELECT id FROM crm_compliance_types 
        WHERE company_id = ? AND code = ? AND id != ?
    ");
    $dupStmt->execute([$companyId, $code, $typeId ?? 0]);
    if ($dupStmt->fetch()) {
        throw new Exception('A document type with this code already exists');
    }

    $expiryMonths = !empty($_POST['default_expiry_months']) ? (int)$_POST['default_expiry_months'] : null;
    $required = isset($_POST['required']) ? 1 : 0;

    if ($typeId) {
        // UPDATE
        $stmt = $DB->prepare("
            UPDATE crm_compliance_types SET
                code = ?,
                name = ?,
                default_expiry_months = ?,
                required = ?
            WHERE id = ? AND company_id = ?
        ");

        $stmt->execute([
            $code,
            $name,
            $expiryMonths,
            $required,
            $typeId,
            $companyId
        ]);

    } else {
        // INSERT
        $stmt = $DB->prepare("
            INSERT INTO crm_compliance_types (
                company_id, code, name, default_expiry_months, required
            ) VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $companyId,
            $code,
            $name,
            $expiryMonths,
            $required
        ]);

        $typeId = $DB->lastInsertId();
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, ?, 'crm_compliance_type', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $typeId ? 'update' : 'create',
        $typeId,
        json_encode(['code' => $code, 'name' => $name])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'type_id' => $typeId]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM compliance_type_save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}