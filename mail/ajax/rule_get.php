<?php
// /mail/ajax/rule_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$ruleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$ruleId) {
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT * FROM email_rules
        WHERE rule_id = ? AND company_id = ? AND (user_id = ? OR user_id IS NULL)
    ");
    $stmt->execute([$ruleId, $companyId, $userId]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rule) {
        echo json_encode(['ok' => false, 'error' => 'Rule not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'rule' => $rule]);

} catch (Exception $e) {
    error_log("Rule get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load rule']);
}