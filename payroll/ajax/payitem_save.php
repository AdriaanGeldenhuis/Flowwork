<?php
// /payroll/ajax/payitem_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$id = $_POST['id'] ?? 0;
$code = trim($_POST['code'] ?? '');
$name = trim($_POST['name'] ?? '');
$type = $_POST['type'] ?? 'earning';
$taxable = isset($_POST['taxable']) ? 1 : 0;
$uifSubject = isset($_POST['uif_subject']) ? 1 : 0;
$sdlSubject = isset($_POST['sdl_subject']) ? 1 : 0;
$active = isset($_POST['active']) ? 1 : 0;
$glAccountCode = trim($_POST['gl_account_code'] ?? '');

// Validation
if (empty($code)) {
    echo json_encode(['ok' => false, 'error' => 'Code required']);
    exit;
}
if (empty($name)) {
    echo json_encode(['ok' => false, 'error' => 'Name required']);
    exit;
}

try {
    $DB->beginTransaction();

    if ($id) {
        // Update existing
        $stmt = $DB->prepare("
            UPDATE payitems SET
                code = ?,
                name = ?,
                type = ?,
                taxable = ?,
                uif_subject = ?,
                sdl_subject = ?,
                gl_account_code = ?,
                active = ?,
                updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $code, $name, $type, $taxable, $uifSubject, $sdlSubject,
            $glAccountCode, $active, $id, $companyId
        ]);

        // Audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
            VALUES (?, ?, 'payitem_updated', ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $userId,
            json_encode(['payitem_id' => $id, 'code' => $code, 'name' => $name]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

    } else {
        // Insert new
        $stmt = $DB->prepare("
            INSERT INTO payitems (
                company_id, code, name, type, taxable, uif_subject, sdl_subject,
                gl_account_code, active, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, NOW()
            )
        ");
        $stmt->execute([
            $companyId, $code, $name, $type, $taxable, $uifSubject, $sdlSubject,
            $glAccountCode, $active
        ]);
        $id = $DB->lastInsertId();

        // Audit
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
            VALUES (?, ?, 'payitem_created', ?, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $userId,
            json_encode(['payitem_id' => $id, 'code' => $code, 'name' => $name]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'id' => $id
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Payitem save error: " . $e->getMessage());
    
    // Check for duplicate code
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode(['ok' => false, 'error' => 'Code already exists']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Save failed']);
    }
}