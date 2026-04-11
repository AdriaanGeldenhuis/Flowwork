<?php
// /finances/ajax/bank_rule_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

Csrf::validate();

// Admin only for deletion
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

    // Verify rule exists and belongs to company
    $stmt = $DB->prepare("SELECT id, rule_name FROM gl_bank_rules WHERE id = ? AND company_id = ?");
    $stmt->execute([$ruleId, $companyId]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rule) {
        throw new Exception('Rule not found');
    }

    // Delete the rule
    $stmt = $DB->prepare("DELETE FROM gl_bank_rules WHERE id = ? AND company_id = ?");
    $stmt->execute([$ruleId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'bank_rule_deleted', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['rule_id' => $ruleId, 'name' => $rule['rule_name']]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Bank rule delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to delete rule']);
}
