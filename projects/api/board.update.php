<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_POST['board_id'] ?? 0);
$title = isset($_POST['title']) ? trim($_POST['title']) : null;
$description = isset($_POST['description']) ? trim($_POST['description']) : null;
$defaultView = isset($_POST['default_view']) ? trim($_POST['default_view']) : null;

if (!$boardId) respond_error('Board ID required');

$ALLOWED_VIEWS = ['table', 'kanban', 'calendar', 'gantt', 'workload'];
if ($defaultView !== null && !in_array($defaultView, $ALLOWED_VIEWS, true)) {
    respond_error('Invalid default view');
}
if ($title !== null && $title === '') respond_error('Title cannot be empty');
if ($title === null && $description === null && $defaultView === null) {
    respond_error('Nothing to update');
}

require_board_role($boardId, 'editor');

try {
    // Verify board belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM project_boards WHERE board_id = ? AND company_id = ?");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$board) respond_error('Board not found', 404);

    $updates = [];
    $params = [];
    if ($title !== null) { $updates[] = 'title = ?'; $params[] = $title; }
    if ($description !== null) { $updates[] = 'description = ?'; $params[] = $description; }
    if ($defaultView !== null) { $updates[] = 'default_view = ?'; $params[] = $defaultView; }

    $params[] = $boardId;
    $params[] = $COMPANY_ID;
    $stmt = $DB->prepare("UPDATE project_boards SET " . implode(', ', $updates) . " WHERE board_id = ? AND company_id = ?");
    $stmt->execute($params);

    respond_ok(['title' => $title, 'default_view' => $defaultView]);

} catch (Exception $e) {
    error_log("Board update error: " . $e->getMessage());
    respond_error('Failed to update board', 500);
}
