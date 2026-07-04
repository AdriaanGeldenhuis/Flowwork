<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';
require_once __DIR__ . '/_audit.php';

$itemId = (int)($_POST['item_id'] ?? 0);
if (!$itemId) respond_error('Item ID required');

try {
    // Verify item belongs to company and get board_id for role check
    $stmt = $DB->prepare("
        SELECT bi.id, bi.board_id, bi.title
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE bi.id = ? AND pb.company_id = ?
    ");
    $stmt->execute([$itemId, $COMPANY_ID]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) respond_error('Item not found', 404);

    require_board_role($item['board_id'], 'member');

    // Build update query dynamically
    $updates = [];
    $params = [];

    if (isset($_POST['title'])) {
        $updates[] = "title = ?";
        $params[] = trim($_POST['title']);
    }

    if (isset($_POST['description'])) {
        $updates[] = "description = ?";
        $params[] = trim($_POST['description']);
    }

    if (isset($_POST['group_id'])) {
        $updates[] = "group_id = ?";
        $params[] = (int)$_POST['group_id'];
    }

    if (isset($_POST['position'])) {
        $updates[] = "position = ?";
        $params[] = (int)$_POST['position'];
    }

    if (isset($_POST['status_label'])) {
        $updates[] = "status_label = ?";
        $params[] = $_POST['status_label'] ?: null;
    }

    if (isset($_POST['assigned_to'])) {
        $updates[] = "assigned_to = ?";
        $params[] = $_POST['assigned_to'] ? (int)$_POST['assigned_to'] : null;
    }

    if (isset($_POST['priority'])) {
        $updates[] = "priority = ?";
        $params[] = $_POST['priority'] ?: null;
    }

    if (isset($_POST['progress'])) {
        $updates[] = "progress = ?";
        $params[] = (int)$_POST['progress'];
    }

    if (isset($_POST['due_date'])) {
        $updates[] = "due_date = ?";
        $params[] = $_POST['due_date'] ?: null;
    }

    if (isset($_POST['start_date'])) {
        $updates[] = "start_date = ?";
        $params[] = $_POST['start_date'] ?: null;
    }

    if (isset($_POST['end_date'])) {
        $updates[] = "end_date = ?";
        $params[] = $_POST['end_date'] ?: null;
    }

    if (isset($_POST['tags'])) {
        $updates[] = "tags = ?";
        $params[] = $_POST['tags'];
    }

    if (isset($_POST['archived'])) {
        $updates[] = "archived = ?";
        $params[] = (int)$_POST['archived'];
    }

    if (empty($updates)) {
        respond_ok(['item_id' => $itemId, 'updated_fields' => 0]);
        exit;
    }

    // Always update timestamp
    $updates[] = "updated_at = NOW()";

    $params[] = $itemId;
    $sql = "UPDATE board_items SET " . implode(', ', $updates) . " WHERE id = ?";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);

    // Activity log: pick the most meaningful action for this update
    if (isset($_POST['archived'])) {
        $action = ((int)$_POST['archived'] === 1) ? 'item_deleted' : 'item_restored';
        $details = ['title' => $item['title']];
    } elseif (isset($_POST['status_label'])) {
        $action = 'status_changed';
        $details = ['new_status' => $_POST['status_label'] ?: 'none'];
    } else {
        $action = 'item_updated';
        $details = ['fields' => array_values(array_intersect(
            ['title', 'description', 'group_id', 'position', 'assigned_to', 'priority', 'progress', 'due_date', 'start_date', 'end_date', 'tags'],
            array_keys($_POST)
        ))];
    }
    fw_audit($DB, $COMPANY_ID, $item['board_id'], $itemId, $USER_ID, $action, $details);

    respond_ok(['item_id' => $itemId, 'updated_fields' => count($updates) - 1]);

} catch (Exception $e) {
    error_log("Item update error: " . $e->getMessage());
    respond_error('Failed to update item', 500);
}
