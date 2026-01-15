<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
$title = trim($_POST['title'] ?? '');

if (!$boardId || !$title) respond_error('Board ID and title required');

require_board_role($boardId, 'editor');

try {
    // Verify board belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM project_boards WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$board) respond_error('Board not found', 404);
    
    // Update board
    $stmt = $DB->prepare("UPDATE project_boards SET title = ? WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$title, $boardId, $COMPANY_ID]);
    
    respond_ok(['title' => $title]);
    
} catch (Exception $e) {
    error_log("Board update error: " . $e->getMessage());
    respond_error('Failed to update board', 500);
}