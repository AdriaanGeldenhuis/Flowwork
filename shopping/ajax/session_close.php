<?php
// /shopping/ajax/session_close.php
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

try {
    $DB->beginTransaction();
    
    // Close active session
    $stmt = $DB->prepare("
        UPDATE shopping_sessions 
        SET ended_at = NOW() 
        WHERE list_id = ? AND ended_at IS NULL
    ");
    $stmt->execute([$listId]);
    
    // Check if all items bought
    $stmt = $DB->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'bought' THEN 1 ELSE 0 END) as bought
        FROM shopping_items
        WHERE list_id = ? AND status != 'removed'
    ");
    $stmt->execute([$listId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $newStatus = ($stats['total'] == $stats['bought']) ? 'done' : 'open';
    
    $stmt = $DB->prepare("UPDATE shopping_lists SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $listId]);
    
    $DB->commit();
    
    echo json_encode(['ok' => true, 'status' => $newStatus]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Session close error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}