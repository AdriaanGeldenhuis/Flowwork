<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$boardId = (int)($_GET['board_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = min(200, max(50, (int)($_GET['per'] ?? 200)));
$offset = ($page - 1) * $per;

if (!$boardId) respond_error('Board ID required');

require_board_role($boardId, 'viewer');

try {
    // Board meta
    $stmt = $DB->prepare("
        SELECT pb.board_id, pb.project_id, pb.title, pb.default_view, pb.description
        FROM project_boards pb
        WHERE pb.board_id = ?
        LIMIT 1
    ");
    $stmt->execute([$boardId]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$board) respond_error('Board not found', 404);

    // Columns
    $stmt = $DB->prepare("
        SELECT column_id, name, type, config, position, sort_order, visible, width, color, required
        FROM board_columns
        WHERE board_id = ? AND company_id = ?
        ORDER BY position ASC, column_id ASC
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Groups
    $stmt = $DB->prepare("
        SELECT id, name, position, is_locked, collapsed, color
        FROM board_groups
        WHERE board_id = ?
        ORDER BY position ASC, id ASC
    ");
    $stmt->execute([$boardId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Item count
    $stmt = $DB->prepare("
        SELECT COUNT(*) 
        FROM board_items 
        WHERE board_id = ? AND company_id = ? AND archived = 0
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    $totalItems = (int)$stmt->fetchColumn();

    // Items page
    $stmt = $DB->prepare("
        SELECT id, group_id, title, description, position, status_label,
               assigned_to, priority, progress, due_date, start_date, end_date, tags
        FROM board_items
        WHERE board_id = ? AND company_id = ? AND archived = 0
        ORDER BY group_id ASC, position ASC, id ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $boardId, PDO::PARAM_INT);
    $stmt->bindValue(2, $COMPANY_ID, PDO::PARAM_INT);
    $stmt->bindValue(3, $per, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Values
    $values = [];
    if ($items) {
        $itemIds = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $DB->prepare("
            SELECT item_id, column_id, value
            FROM board_item_values
            WHERE item_id IN ($placeholders)
        ");
        $stmt->execute($itemIds);
        $values = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    respond_ok([
        'board' => $board,
        'columns' => $columns,
        'groups' => $groups,
        'items' => $items,
        'values' => $values,
        'pagination' => [
            'page' => $page,
            'per' => $per,
            'total' => $totalItems,
            'pages' => max(1, ceil($totalItems / $per))
        ]
    ]);

} catch (Exception $e) {
    error_log("Board load error: " . $e->getMessage());
    respond_error('Failed to load board', 500);
}
