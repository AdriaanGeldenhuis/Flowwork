<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    // Validate CSRF
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    $groupId = (int)($_POST['group_id'] ?? 0);
    $targetGroupId = (int)($_POST['target_group_id'] ?? 0);
    $insertBefore = (int)($_POST['insert_before'] ?? 0);
    
    if (!$groupId || !$targetGroupId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Group IDs required']));
    }
    
    $DB->beginTransaction();
    
    try {
        // Get board_id
        $stmt = $DB->prepare("SELECT board_id FROM board_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $boardId = $stmt->fetchColumn();
        
        if (!$boardId) {
            throw new Exception('Group not found');
        }
        
        // Get all groups for this board
        $stmt = $DB->prepare("
            SELECT id, position
            FROM board_groups
            WHERE board_id = ?
            ORDER BY position
        ");
        $stmt->execute([$boardId]);
        $allGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate new positions
        $newPositions = [];
        $newPos = 0;
        
        foreach ($allGroups as $group) {
            $gid = (int)$group['id'];
            
            // Skip dragged group
            if ($gid === $groupId) continue;
            
            // Insert dragged group before target
            if ($insertBefore && $gid === $targetGroupId) {
                $newPositions[$groupId] = $newPos++;
            }
            
            $newPositions[$gid] = $newPos++;
            
            // Insert dragged group after target
            if (!$insertBefore && $gid === $targetGroupId) {
                $newPositions[$groupId] = $newPos++;
            }
        }
        
        // Update positions
        $stmt = $DB->prepare("UPDATE board_groups SET position = ? WHERE id = ?");
        
        foreach ($newPositions as $gid => $pos) {
            $stmt->execute([$pos, $gid]);
        }
        
        $DB->commit();
        
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => 'Group reordered',
            'data' => [
                'group_id' => $groupId,
                'new_position' => $newPositions[$groupId]
            ]
        ]);
        
    } catch (Exception $e) {
        $DB->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Group Reorder Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;