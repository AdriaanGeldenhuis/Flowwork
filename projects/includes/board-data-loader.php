<?php
// /projects/includes/board-data-loader.php
// Shared between board.php and guest-view.php

// Load columns
$stmt = $DB->prepare("
    SELECT * FROM board_columns
    WHERE board_id = ? AND company_id = ? AND visible = 1
    ORDER BY position
");
$stmt->execute([$boardId, $companyId]);
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load groups
$stmt = $DB->prepare("
    SELECT * FROM board_groups 
    WHERE board_id = ? 
    ORDER BY position
");
$stmt->execute([$boardId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load items
$stmt = $DB->prepare("
    SELECT bi.*, u.first_name, u.last_name, bg.name AS group_name
    FROM board_items bi
    LEFT JOIN users u ON bi.assigned_to = u.id
    LEFT JOIN board_groups bg ON bi.group_id = bg.id
    WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
    ORDER BY bg.position, bi.position
    LIMIT 500
");
$stmt->execute([$boardId, $companyId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load item values
$itemIds = array_column($items, 'id');
$valuesMap = [];

if (!empty($itemIds)) {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $DB->prepare("
        SELECT item_id, column_id, value
        FROM board_item_values
        WHERE item_id IN ($placeholders)
    ");
    $stmt->execute($itemIds);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $valuesMap[$row['item_id']][$row['column_id']] = $row['value'];
    }
}
?>