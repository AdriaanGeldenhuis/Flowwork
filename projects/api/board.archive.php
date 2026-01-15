<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
if (!$boardId) respond_error('Board ID required');

require_board_role($boardId, 'editor');

try {
    // Verify board belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM project_boards WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$board) respond_error('Board not found', 404);
    
    // Archive board
    $stmt = $DB->prepare("UPDATE project_boards SET archived = 1 WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    
    respond_ok();
    
} catch (Exception $e) {
    error_log("Board archive error: " . $e->getMessage());
    respond_error('Failed to archive board', 500);
}