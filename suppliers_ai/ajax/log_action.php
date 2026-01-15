<?php
// /suppliers_ai/ajax/log_action.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$queryId = $input['query_id'] ?? null;
$candidateId = $input['candidate_id'] ?? null;
$action = $input['action'] ?? null;

$validActions = ['call', 'whatsapp', 'email', 'view'];

if (!$queryId || !$candidateId || !in_array($action, $validActions)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $stmt = $DB->prepare("
        INSERT INTO ai_actions (company_id, query_id, candidate_id, action, details_json, user_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $queryId,
        $candidateId,
        $action,
        json_encode(['timestamp' => time()]),
        $userId
    ]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}