<?php
// /finances/ajax/bank_rule_toggle.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

Csrf::validate();

$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$ruleId = isset($input['rule_id']) ? (int)$input['rule_id'] : 0;

if (!$ruleId) {
    echo json_encode(['ok' => false, 'error' => 'Rule ID required']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("SELECT id, rule_name, is_active FROM gl_bank_rules WHERE id = ? AND company_id = ?");
    $stmt->execute([$ruleId, $companyId]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rule) {
        throw new Exception('Rule not found');
    }

    $newStatus = (int)$rule['is_active'] === 1 ? 0 : 1;

    $stmt = $DB->prepare("UPDATE gl_bank_rules SET is_active = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$newStatus, $ruleId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'bank_rule_toggled', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['rule_id' => $ruleId, 'name' => $rule['rule_name'], 'new_status' => $newStatus]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    echo json_encode(['ok' => true, 'is_active' => $newStatus]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Bank rule toggle error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
