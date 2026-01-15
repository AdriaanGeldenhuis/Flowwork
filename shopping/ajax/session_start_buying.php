<?php
// /shopping/ajax/session_start_buying.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$listId = intval($input['list_id'] ?? 0);

if (!$listId) {
    echo json_encode(['ok' => false, 'error' => 'Missing list ID']);
    exit;
}

// Verify ownership
$stmt = $DB->prepare("SELECT id FROM shopping_lists WHERE id = ? AND company_id = ?");
$stmt->execute([$listId, $companyId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'List not found']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // Create session
    $stmt = $DB->prepare("
        INSERT INTO shopping_sessions (list_id, started_by, started_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$listId, $userId]);
    $sessionId = $DB->lastInsertId();
    
    // Update list status
    $stmt = $DB->prepare("UPDATE shopping_lists SET status = 'buying' WHERE id = ?");
    $stmt->execute([$listId]);
    
    // Audit
    $stmt = $DB->prepare("
        INSERT INTO shopping_actions (company_id, list_id, user_id, action, payload_json, ip)
        VALUES (?, ?, ?, 'buy', ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $listId,
        $userId,
        json_encode(['session_id' => $sessionId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    $DB->commit();
    
    echo json_encode(['ok' => true, 'session_id' => $sessionId]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Session start error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}