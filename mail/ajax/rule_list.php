<?php
// /mail/ajax/rule_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    // Get user rules + company-wide rules (admin)
    $stmt = $DB->prepare("
        SELECT rule_id, name, priority, is_active
        FROM email_rules
        WHERE company_id = ? AND (user_id = ? OR user_id IS NULL)
        ORDER BY priority ASC, name
    ");
    $stmt->execute([$companyId, $userId]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'rules' => $rules]);

} catch (Exception $e) {
    error_log("Rule list error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load rules']);
}