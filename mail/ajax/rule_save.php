<?php
// /mail/ajax/rule_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$ruleId = isset($input['rule_id']) ? (int)$input['rule_id'] : 0;
$name = trim($input['name'] ?? '');
$priority = (int)($input['priority'] ?? 100);
$conditionsJson = $input['conditions_json'] ?? '[]';
$actionsJson = $input['actions_json'] ?? '[]';
$stopProcessing = isset($input['stop_processing']) ? 1 : 0;
$isActive = isset($input['is_active']) ? 1 : 0;

if (!$name) {
    echo json_encode(['ok' => false, 'error' => 'Name is required']);
    exit;
}

try {
    if ($ruleId) {
        // Update
        $stmt = $DB->prepare("SELECT rule_id FROM email_rules WHERE rule_id = ? AND company_id = ?");
        $stmt->execute([$ruleId, $companyId]);
        if (!$stmt->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Rule not found']);
            exit;
        }

        $stmt = $DB->prepare("
            UPDATE email_rules SET
                name = ?, priority = ?, conditions_json = ?, actions_json = ?,
                stop_processing = ?, is_active = ?, updated_at = NOW()
            WHERE rule_id = ?
        ");
        $stmt->execute([$name, $priority, $conditionsJson, $actionsJson, $stopProcessing, $isActive, $ruleId]);

    } else {
        // Create
        $stmt = $DB->prepare("
            INSERT INTO email_rules (company_id, user_id, name, priority, conditions_json, actions_json, stop_processing, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, $name, $priority, $conditionsJson, $actionsJson, $stopProcessing, $isActive]);
        $ruleId = $DB->lastInsertId();
    }

    echo json_encode(['ok' => true, 'rule_id' => $ruleId]);

} catch (Exception $e) {
    error_log("Rule save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save rule']);
}