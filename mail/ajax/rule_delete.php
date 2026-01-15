<?php
// /mail/ajax/rule_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$input = json_decode(file_get_contents('php://input'), true);
$ruleId = isset($input['rule_id']) ? (int)$input['rule_id'] : 0;

if (!$ruleId) {
    echo json_encode(['ok' => false, 'error' => 'Missing rule_id']);
    exit;
}

try {
    $stmt = $DB->prepare("DELETE FROM email_rules WHERE rule_id = ? AND company_id = ?");
    $stmt->execute([$ruleId, $companyId]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Rule delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to delete rule']);
}