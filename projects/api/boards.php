<?php
// Unified API endpoint for board operations.
//
// This endpoint consolidates many of the existing board-related API endpoints into a
// single entry point. Actions are controlled via the `action` parameter.  See the
// documentation in the frontend for which payloads are expected for each action.

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

/**
 * Determine the action and dispatch accordingly. All actions must be explicitly
 * defined below.  If an action is missing, a 400 response will be returned.
 */
$action = strtolower($_POST['action'] ?? $_GET['action'] ?? '');
if (!$action) {
    respond_error('Action required');
}

try {
    switch ($action) {
        /**
         * Fetch a board along with its columns, groups, items and values.  Supports
         * pagination on items.  Required: `board_id`. Optional: `page`, `per`.
         */
        case 'get_board': {
            $boardId = (int)($_POST['board_id'] ?? $_GET['board_id'] ?? 0);
            $page    = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
            $per     = min(200, max(50, (int)($_POST['per'] ?? $_GET['per'] ?? 200)));
            $offset  = ($page - 1) * $per;
            if (!$boardId) respond_error('Board ID required');
            require_board_role($boardId, 'viewer');

            // Basic board metadata
            $stmt = $DB->prepare(
                "
                SELECT pb.board_id, pb.project_id, pb.title, pb.default_view, pb.description
                FROM project_boards pb
                WHERE pb.board_id = ?
                LIMIT 1
            "
            );
            $stmt->execute([$boardId]);
            $board = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$board) respond_error('Board not found', 404);

            // Columns for this board
            $stmt = $DB->prepare(
                "
                SELECT column_id, name, type, config, position, sort_order, visible, width, color, required
                FROM board_columns
                WHERE board_id = ? AND company_id = ?
                ORDER BY position ASC, column_id ASC
            "
            );
            $stmt->execute([$boardId, $COMPANY_ID]);
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Groups for this board
            $stmt = $DB->prepare(
                "
                SELECT id, name, position, is_locked, collapsed, color
                FROM board_groups
                WHERE board_id = ?
                ORDER BY position ASC, id ASC
            "
            );
            $stmt->execute([$boardId]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Total count of non-archived items
            $stmt = $DB->prepare(
                "
                SELECT COUNT(*)
                FROM board_items
                WHERE board_id = ? AND company_id = ? AND archived = 0
            "
            );
            $stmt->execute([$boardId, $COMPANY_ID]);
            $totalItems = (int)$stmt->fetchColumn();

            // Paginated items
            $stmt = $DB->prepare(
                "
                SELECT id, group_id, title, description, position, status_label,
                       assigned_to, priority, progress, due_date, start_date, end_date, tags
                FROM board_items
                WHERE board_id = ? AND company_id = ? AND archived = 0
                ORDER BY group_id ASC, position ASC, id ASC
                LIMIT ? OFFSET ?
            "
            );
            $stmt->bindValue(1, $boardId, PDO::PARAM_INT);
            $stmt->bindValue(2, $COMPANY_ID, PDO::PARAM_INT);
            $stmt->bindValue(3, $per, PDO::PARAM_INT);
            $stmt->bindValue(4, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Values for these items and columns
            $values = [];
            if ($items) {
                $itemIds = array_column($items, 'id');
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $stmt = $DB->prepare(
                    "
                    SELECT item_id, column_id, value
                    FROM board_item_values
                    WHERE item_id IN ($placeholders)
                "
                );
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
            break;
        }
        /**
         * Create a new group in the specified board.  Required: `board_id`, `name`.
         * Optional: `color`. Returns the new group ID on success.
         */
        case 'create_group': {
            $boardId = (int)($_POST['board_id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $color   = $_POST['color'] ?? '#8b5cf6';
            if (!$boardId) respond_error('Board ID required');
            if (!$name)    respond_error('Group name required');
            require_board_role($boardId, 'contributor');

            // Determine next position for the new group
            $stmt = $DB->prepare("SELECT COALESCE(MAX(position), 0) FROM board_groups WHERE board_id = ?");
            $stmt->execute([$boardId]);
            $maxPos = (int)$stmt->fetchColumn();

            // Insert the new group
            $stmt = $DB->prepare(
                "
                INSERT INTO board_groups (board_id, name, color, position, collapsed, is_locked, created_at)
                VALUES (?, ?, ?, ?, 0, 0, NOW())
            "
            );
            $stmt->execute([$boardId, $name, $color, $maxPos + 1]);
            $groupId = (int)$DB->lastInsertId();

            // Audit log the group addition
            $stmt = $DB->prepare(
                "
                INSERT INTO board_audit_log (company_id, board_id, item_id, user_id, action, details, ip_address, created_at)
                VALUES (?, ?, NULL, ?, 'group_added', ?, ?, NOW())
            "
            );
            $stmt->execute([
                $COMPANY_ID,
                $boardId,
                $USER_ID,
                json_encode(['group_id' => $groupId, 'name' => $name]),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            respond_ok(['group_id' => $groupId]);
            break;
        }
        /**
         * Update a group.  Required: `group_id`.  Optional: name, color, position,
         * collapsed, is_locked.  Only provided fields will be updated.
         */
        case 'update_group': {
            $groupId = (int)($_POST['group_id'] ?? 0);
            if (!$groupId) respond_error('Group ID required');
            // Resolve board ID for permission check
            $stmt = $DB->prepare("SELECT board_id FROM board_groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $grp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$grp) respond_error('Group not found', 404);
            require_board_role((int)$grp['board_id'], 'contributor');

            $updates = [];
            $values  = [];
            $fields  = [
                'name'      => 'name',
                'color'     => 'color',
                'position'  => 'position',
                'collapsed' => 'collapsed',
                'is_locked' => 'is_locked'
            ];
            foreach ($fields as $key => $col) {
                if (isset($_POST[$key])) {
                    $updates[] = "$col = ?";
                    $values[]  = $_POST[$key];
                }
            }
            if (!$updates) respond_error('No fields to update');
            $values[] = $groupId;
            $sql = "UPDATE board_groups SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $DB->prepare($sql);
            $stmt->execute($values);
            respond_ok(['updated' => $stmt->rowCount()]);
            break;
        }
        /**
         * Reorder groups within a board.  Required: `board_id`, `ordered_group_ids` as an array.
         */
        case 'reorder_groups': {
            $boardId    = (int)($_POST['board_id'] ?? 0);
            $orderedIds = $_POST['ordered_group_ids'] ?? [];
            if (!$boardId) respond_error('Board ID required');
            if (!is_array($orderedIds) || empty($orderedIds)) respond_error('ordered_group_ids required');
            require_board_role($boardId, 'contributor');
            $DB->beginTransaction();
            try {
                $stmt = $DB->prepare("UPDATE board_groups SET position = ? WHERE id = ? AND board_id = ?");
                foreach ($orderedIds as $pos => $gid) {
                    $stmt->execute([$pos, (int)$gid, $boardId]);
                }
                $DB->commit();
                respond_ok(['reordered' => count($orderedIds)]);
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }
            break;
        }
        /**
         * Create a new column.  Required: `board_id`, `name`.  Optional: `type`,
         * `width`.  Type must be one of the allowed column types.  If no type is
         * provided, 'text' is used by default.  Returns new column ID.
         */
        case 'create_column': {
            $boardId = (int)($_POST['board_id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $type    = $_POST['type'] ?? 'text';
            $width   = (int)($_POST['width'] ?? 150);
            if (!$boardId) respond_error('Board ID required');
            if (!$name)    respond_error('Column name required');
            // Validate the column type.  We include 'files' here even though it
            // does not exist in the DB enum; the frontend interprets this type
            // specially and stores attachments via board_item_attachments.  It is
            // stored as type text in the DB.
            $allowedTypes = ['status','people','date','timeline','text','longtext','number','dropdown','checkbox','tags','link','email','phone','formula','progress','files'];
            if (!in_array($type, $allowedTypes, true)) respond_error('Invalid column type');
            require_board_role($boardId, 'manager');
            // Determine the next position within this board
            $stmt = $DB->prepare("SELECT COALESCE(MAX(position), 0) FROM board_columns WHERE board_id = ? AND company_id = ?");
            $stmt->execute([$boardId, $COMPANY_ID]);
            $maxPos = (int)$stmt->fetchColumn();
            // Insert the column; config remains NULL until updated via update_column
            $stmt = $DB->prepare(
                "
                INSERT INTO board_columns (board_id, company_id, name, type, config, position, sort_order, visible, width, color, required, created_at)
                VALUES (?, ?, ?, ?, NULL, ?, 0, 1, ?, NULL, 0, NOW())
            "
            );
            $stmt->execute([$boardId, $COMPANY_ID, $name, $type, $maxPos + 1, $width]);
            $columnId = (int)$DB->lastInsertId();
            // Log addition
            $stmt = $DB->prepare(
                "
                INSERT INTO board_audit_log (company_id, board_id, user_id, action, details, ip_address, created_at)
                VALUES (?, ?, ?, 'column_added', ?, ?, NOW())
            "
            );
            $stmt->execute([
                $COMPANY_ID,
                $boardId,
                $USER_ID,
                json_encode(['column_id' => $columnId, 'name' => $name, 'type' => $type]),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            respond_ok(['column_id' => $columnId]);
            break;
        }
        /**
         * Update a column.  Required: `column_id`.  Optional fields include: name,
         * config, position, sort_order, visible, width, color, required.  Only
         * provided fields are updated.
         */
        case 'update_column': {
            $columnId = (int)($_POST['column_id'] ?? 0);
            if (!$columnId) respond_error('Column ID required');
            // Determine board for permission
            $stmt = $DB->prepare("SELECT board_id FROM board_columns WHERE column_id = ? AND company_id = ?");
            $stmt->execute([$columnId, $COMPANY_ID]);
            $col = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$col) respond_error('Column not found', 404);
            require_board_role((int)$col['board_id'], 'manager');
            // Build updates
            $updates = [];
            $values  = [];
            $allowed = [
                'name'       => 'name',
                'config'     => 'config',
                'position'   => 'position',
                'sort_order' => 'sort_order',
                'visible'    => 'visible',
                'width'      => 'width',
                'color'      => 'color',
                'required'   => 'required'
            ];
            foreach ($allowed as $in => $dbcol) {
                if (isset($_POST[$in])) {
                    $updates[] = "$dbcol = ?";
                    $values[]  = $_POST[$in];
                }
            }
            if (!$updates) respond_error('No fields to update');
            $values[] = $columnId;
            $values[] = $COMPANY_ID;
            $sql = "UPDATE board_columns SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE column_id = ? AND company_id = ?";
            $stmt = $DB->prepare($sql);
            $stmt->execute($values);
            respond_ok(['updated' => $stmt->rowCount()]);
            break;
        }
        /**
         * Delete a column along with its values.  Required: `column_id`.
         */
        case 'delete_column': {
            $columnId = (int)($_POST['column_id'] ?? 0);
            if (!$columnId) respond_error('Column ID required');
            // Resolve board and check permission
            $stmt = $DB->prepare("SELECT board_id FROM board_columns WHERE column_id = ? AND company_id = ?");
            $stmt->execute([$columnId, $COMPANY_ID]);
            $col = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$col) respond_error('Column not found', 404);
            require_board_role((int)$col['board_id'], 'manager');
            $DB->beginTransaction();
            try {
                // remove values
                $stmt = $DB->prepare("DELETE FROM board_item_values WHERE column_id = ?");
                $stmt->execute([$columnId]);
                // remove the column
                $stmt = $DB->prepare("DELETE FROM board_columns WHERE column_id = ? AND company_id = ?");
                $stmt->execute([$columnId, $COMPANY_ID]);
                $DB->commit();
                respond_ok(['deleted' => true]);
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }
            break;
        }
        /**
         * Reorder columns.  Required: `board_id`, `ordered_column_ids` array.
         */
        case 'reorder_columns': {
            $boardId    = (int)($_POST['board_id'] ?? 0);
            $orderedIds = $_POST['ordered_column_ids'] ?? [];
            if (!$boardId) respond_error('Board ID required');
            if (!is_array($orderedIds) || empty($orderedIds)) respond_error('ordered_column_ids required');
            require_board_role($boardId, 'manager');
            $DB->beginTransaction();
            try {
                $stmt = $DB->prepare("UPDATE board_columns SET position = ? WHERE column_id = ? AND board_id = ? AND company_id = ?");
                foreach ($orderedIds as $pos => $cid) {
                    $stmt->execute([$pos, (int)$cid, $boardId, $COMPANY_ID]);
                }
                $DB->commit();
                // Record an audit entry for column reorder.  Store the new order
                // as an array of integers for traceability.  The details payload
                // includes the ordered column IDs in their new positions.
                try {
                    $detail = json_encode(['ordered_column_ids' => array_map('intval', $orderedIds)]);
                    $stmt = $DB->prepare(
                        "INSERT INTO board_audit_log (company_id, board_id, user_id, action, details, ip_address, created_at)
                         VALUES (?, ?, ?, 'columns_reordered', ?, ?, NOW())"
                    );
                    $stmt->execute([
                        $COMPANY_ID,
                        $boardId,
                        $USER_ID,
                        $detail,
                        $_SERVER['REMOTE_ADDR'] ?? null
                    ]);
                } catch (Exception $ex) {
                    // Silently ignore audit failures to avoid blocking reorder
                    error_log('Audit log error (reorder_columns): ' . $ex->getMessage());
                }
                respond_ok(['reordered' => count($orderedIds)]);
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }
            break;
        }
        /**
         * Create a new item in a group.  Required: `board_id`, `group_id`, `title`.
         * Optional: `assignee_user_id`, `status`, `due_date`.  Returns new item ID.
         */
        case 'create_item': {
            $boardId    = (int)($_POST['board_id'] ?? 0);
            $groupId    = (int)($_POST['group_id'] ?? 0);
            $title      = trim($_POST['title'] ?? '');
            $assigneeId = isset($_POST['assignee_user_id']) ? (int)$_POST['assignee_user_id'] : null;
            $status     = $_POST['status'] ?? null;
            $dueDate    = $_POST['due_date'] ?? null;
            if (!$boardId) respond_error('Board ID required');
            if (!$groupId) respond_error('Group ID required');
            if (!$title)   respond_error('Item title required');
            require_board_role($boardId, 'contributor');
            // Determine next position in group
            $stmt = $DB->prepare(
                "
                SELECT COALESCE(MAX(position), 0)
                FROM board_items
                WHERE board_id = ? AND company_id = ? AND group_id = ?
            "
            );
            $stmt->execute([$boardId, $COMPANY_ID, $groupId]);
            $maxPos = (int)$stmt->fetchColumn();
            // Validate due date
            $due = null;
            if ($dueDate) {
                $d = DateTime::createFromFormat('Y-m-d', $dueDate);
                if ($d) $due = $d->format('Y-m-d');
            }
            // Insert the item
            $stmt = $DB->prepare(
                "
                INSERT INTO board_items
                    (board_id, company_id, group_id, title, description, position, status_label,
                     assigned_to, priority, progress, due_date, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'medium', 0, ?, ?, NOW(), NOW())
            "
            );
            $stmt->execute([
                $boardId,
                $COMPANY_ID,
                $groupId,
                $title,
                $maxPos + 1,
                $status,
                $assigneeId ?: null,
                $due,
                $USER_ID
            ]);
            $itemId = (int)$DB->lastInsertId();
            // Audit
            $stmt = $DB->prepare(
                "
                INSERT INTO board_audit_log (company_id, board_id, item_id, user_id, action, details, ip_address, created_at)
                VALUES (?, ?, ?, ?, 'item_created', ?, ?, NOW())
            "
            );
            $stmt->execute([
                $COMPANY_ID, $boardId, $itemId, $USER_ID,
                json_encode(['title' => $title]),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            respond_ok(['item_id' => $itemId]);
            break;
        }
        /**
         * Update an item.  Required: `item_id`.  Optional fields include: title,
         * description, priority, progress, tags, status, status_label, due_date,
         * start_date, end_date, assignee_user_id.  If dates are provided, they
         * should be in 'Y-m-d' format.
         */
        case 'update_item': {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if (!$itemId) respond_error('Item ID required');
            // Resolve board ID
            $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
            $stmt->execute([$itemId, $COMPANY_ID]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) respond_error('Item not found', 404);
            require_board_role((int)$item['board_id'], 'contributor');
            // Map inputs to DB columns
            $map = [
                'title'        => 'title',
                'description'  => 'description',
                'priority'     => 'priority',
                'progress'     => 'progress',
                'tags'         => 'tags',
                'status'       => 'status_label',
                'status_label' => 'status_label',
                'due_date'     => 'due_date',
                'start_date'   => 'start_date',
                'end_date'     => 'end_date',
            ];
            // Convert assignee_user_id → assigned_to
            if (isset($_POST['assignee_user_id'])) {
                $_POST['assigned_to'] = (int)$_POST['assignee_user_id'] ?: null;
            }
            if (isset($_POST['assigned_to'])) {
                $map['assigned_to'] = 'assigned_to';
            }
            $updates = [];
            $values  = [];
            foreach ($map as $in => $col) {
                if (!array_key_exists($in, $_POST)) continue;
                $val = $_POST[$in];
                // Normalise date strings
                if (in_array($col, ['due_date','start_date','end_date']) && $val) {
                    $d = DateTime::createFromFormat('Y-m-d', $val);
                    $val = $d ? $d->format('Y-m-d') : null;
                }
                $updates[] = "$col = ?";
                $values[]  = $val;
            }
            if (!$updates) respond_error('No fields to update');
            $values[] = $itemId;
            $values[] = $COMPANY_ID;
            $sql = "UPDATE board_items SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ? AND company_id = ?";
            $stmt = $DB->prepare($sql);
            $stmt->execute($values);
            respond_ok(['updated' => $stmt->rowCount()]);
            break;
        }
        /**
         * Delete an item and its associated values, comments and attachments.
         * Required: `item_id`.
         */
        case 'delete_item': {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if (!$itemId) respond_error('Item ID required');
            // Resolve board for permission
            $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
            $stmt->execute([$itemId, $COMPANY_ID]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) respond_error('Item not found', 404);
            require_board_role((int)$item['board_id'], 'contributor');
            $DB->beginTransaction();
            try {
                // remove all values
                $stmt = $DB->prepare("DELETE FROM board_item_values WHERE item_id = ?");
                $stmt->execute([$itemId]);
                // remove comments
                $stmt = $DB->prepare("DELETE FROM board_item_comments WHERE item_id = ?");
                $stmt->execute([$itemId]);
                // remove attachments
                $stmt = $DB->prepare("DELETE FROM board_item_attachments WHERE item_id = ?");
                $stmt->execute([$itemId]);
                // remove item
                $stmt = $DB->prepare("DELETE FROM board_items WHERE id = ? AND company_id = ?");
                $stmt->execute([$itemId, $COMPANY_ID]);
                $DB->commit();
                respond_ok(['deleted' => true]);
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }
            break;
        }
        /**
         * Reorder items within a group.  Required: `board_id`, `group_id`,
         * `ordered_item_ids` array.  The positions start from 0.
         */
        case 'reorder_items': {
            $boardId    = (int)($_POST['board_id'] ?? 0);
            $groupId    = (int)($_POST['group_id'] ?? 0);
            $orderedIds = $_POST['ordered_item_ids'] ?? [];
            if (!$boardId) respond_error('Board ID required');
            if (!$groupId) respond_error('Group ID required');
            if (!is_array($orderedIds) || empty($orderedIds)) respond_error('ordered_item_ids required');
            require_board_role($boardId, 'contributor');
            $DB->beginTransaction();
            try {
                $stmt = $DB->prepare("UPDATE board_items SET position = ?, updated_at = NOW() WHERE id = ? AND board_id = ? AND group_id = ? AND company_id = ?");
                foreach ($orderedIds as $pos => $iid) {
                    $stmt->execute([$pos, (int)$iid, $boardId, $groupId, $COMPANY_ID]);
                }
                $DB->commit();
                // Audit the group reorder.  Capture the new group order as an array
                // for diagnostics.  Failures in audit logging should not block the
                // primary operation.
                try {
                    $detail = json_encode(['ordered_group_ids' => array_map('intval', $orderedIds)]);
                    $stmt = $DB->prepare(
                        "INSERT INTO board_audit_log (company_id, board_id, user_id, action, details, ip_address, created_at)
                         VALUES (?, ?, ?, 'groups_reordered', ?, ?, NOW())"
                    );
                    $stmt->execute([
                        $COMPANY_ID,
                        $boardId,
                        $USER_ID,
                        $detail,
                        $_SERVER['REMOTE_ADDR'] ?? null
                    ]);
                } catch (Exception $ex) {
                    error_log('Audit log error (reorder_groups): ' . $ex->getMessage());
                }
                respond_ok(['reordered' => count($orderedIds)]);
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }
            break;
        }
        /**
         * Update the value of a cell.  Required: `item_id`, `column_id`, `value`.
         * Built-in types (status, people, date, timeline) update fields on
         * board_items.  All other types write to board_item_values.
         */
        case 'update_cell': {
            $itemId   = (int)($_POST['item_id'] ?? 0);
            $columnId = (int)($_POST['column_id'] ?? 0);
            $value    = $_POST['value'] ?? '';
            if (!$itemId) respond_error('Item ID required');
            if (!$columnId) respond_error('Column ID required');
            // Resolve board from item
            $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
            $stmt->execute([$itemId, $COMPANY_ID]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) respond_error('Item not found', 404);
            require_board_role((int)$item['board_id'], 'contributor');
            // Get column
            $stmt = $DB->prepare("SELECT board_id, type, name FROM board_columns WHERE column_id = ? AND company_id = ?");
            $stmt->execute([$columnId, $COMPANY_ID]);
            $col = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$col) respond_error('Column not found', 404);
            // Determine if built-in
            $builtin = ['status','people','date','timeline'];
            if (in_array($col['type'], $builtin, true)) {
                switch ($col['type']) {
                    case 'status':
                        $stmt = $DB->prepare("UPDATE board_items SET status_label = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
                        $stmt->execute([$value, $itemId, $COMPANY_ID]);
                        break;
                    case 'people':
                        $stmt = $DB->prepare("UPDATE board_items SET assigned_to = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
                        $stmt->execute([$value !== '' ? (int)$value : null, $itemId, $COMPANY_ID]);
                        break;
                    case 'date':
                        $v = null;
                        if ($value) { $d = DateTime::createFromFormat('Y-m-d', $value); if ($d) $v = $d->format('Y-m-d'); }
                        $stmt = $DB->prepare("UPDATE board_items SET due_date = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
                        $stmt->execute([$v, $itemId, $COMPANY_ID]);
                        break;
                    case 'timeline':
                        $start = $end = null;
                        if ($value) {
                            if (strpos($value, '|') !== false) {
                                [$s,$e] = explode('|', $value, 2);
                                $sd = DateTime::createFromFormat('Y-m-d', $s);
                                $ed = DateTime::createFromFormat('Y-m-d', $e);
                                $start = $sd ? $sd->format('Y-m-d') : null;
                                $end   = $ed ? $ed->format('Y-m-d') : null;
                            } else {
                                $json = json_decode($value, true);
                                if ($json) {
                                    $sd = DateTime::createFromFormat('Y-m-d', $json['start'] ?? '');
                                    $ed = DateTime::createFromFormat('Y-m-d', $json['end'] ?? '');
                                    $start = $sd ? $sd->format('Y-m-d') : null;
                                    $end   = $ed ? $ed->format('Y-m-d') : null;
                                }
                            }
                        }
                        $stmt = $DB->prepare("UPDATE board_items SET start_date = ?, end_date = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
                        $stmt->execute([$start, $end, $itemId, $COMPANY_ID]);
                        break;
                }
                respond_ok(['updated' => true, 'where' => 'items']);
            } else {
                // Upsert value
                $stmt = $DB->prepare(
                    "
                    INSERT INTO board_item_values (item_id, column_id, value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)
                    "
                );
                $stmt->execute([$itemId, $columnId, (string)$value]);
                respond_ok(['updated' => true, 'where' => 'values']);
            }
            break;
        }
        /**
         * Search items by title/description within a board, project or globally.  A
         * minimal query length of 2 characters is enforced.  Depending on
         * `board_id` and `project_id` parameters, permissions are checked via
         * require_board_role or require_project_role.  Admin/Manager roles may
         * perform global searches.
         */
        case 'search': {
            $query     = trim($_POST['q'] ?? $_GET['q'] ?? '');
            $boardId   = isset($_POST['board_id']) ? (int)$_POST['board_id'] : (isset($_GET['board_id']) ? (int)$_GET['board_id'] : null);
            $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : (isset($_GET['project_id']) ? (int)$_GET['project_id'] : null);
            if (strlen($query) < 2) respond_error('Query too short (min 2 characters)');
            $results = [];
            if ($boardId) {
                require_board_role($boardId, 'viewer');
                $stmt = $DB->prepare(
                    "
                    SELECT
                        bi.id, bi.title, bi.status_label,
                        bg.name as group_name,
                        u.first_name, u.last_name
                    FROM board_items bi
                    JOIN board_groups bg ON bi.group_id = bg.id
                    LEFT JOIN users u ON bi.assigned_to = u.id
                    WHERE bi.board_id = ? AND bi.company_id = ? AND bi.archived = 0
                    AND (bi.title LIKE ? OR bi.description LIKE ?)
                    ORDER BY bi.created_at DESC
                    LIMIT 50
                    "
                );
                $searchTerm = '%' . $query . '%';
                $stmt->execute([$boardId, $COMPANY_ID, $searchTerm, $searchTerm]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($projectId) {
                require_project_role($projectId, 'viewer');
                $stmt = $DB->prepare(
                    "
                    SELECT
                        bi.id, bi.title, bi.status_label,
                        bg.name as group_name,
                        pb.title as board_title,
                        u.first_name, u.last_name
                    FROM board_items bi
                    JOIN board_groups bg ON bi.group_id = bg.id
                    JOIN project_boards pb ON bi.board_id = pb.board_id
                    LEFT JOIN users u ON bi.assigned_to = u.id
                    WHERE pb.project_id = ? AND bi.company_id = ? AND bi.archived = 0
                    AND (bi.title LIKE ? OR bi.description LIKE ?)
                    ORDER BY bi.created_at DESC
                    LIMIT 50
                    "
                );
                $searchTerm = '%' . $query . '%';
                $stmt->execute([$projectId, $COMPANY_ID, $searchTerm, $searchTerm]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Global search allowed only for admin/manager
                if ($USER_ROLE !== 'admin' && $USER_ROLE !== 'manager') {
                    respond_error('Access denied', 403);
                }
                $stmt = $DB->prepare(
                    "
                    SELECT
                        bi.id, bi.title, bi.status_label,
                        bg.name as group_name,
                        pb.title as board_title,
                        p.name as project_name,
                        u.first_name, u.last_name
                    FROM board_items bi
                    JOIN board_groups bg ON bi.group_id = bg.id
                    JOIN project_boards pb ON bi.board_id = pb.board_id
                    JOIN projects p ON pb.project_id = p.project_id
                    LEFT JOIN users u ON bi.assigned_to = u.id
                    WHERE bi.company_id = ? AND bi.archived = 0
                    AND (bi.title LIKE ? OR bi.description LIKE ?)
                    ORDER BY bi.created_at DESC
                    LIMIT 100
                    "
                );
                $searchTerm = '%' . $query . '%';
                $stmt->execute([$COMPANY_ID, $searchTerm, $searchTerm]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            respond_ok(['results' => $results, 'count' => count($results)]);
            break;
        }
        /**
         * Save a view of the board.  Required: `board_id`, `name`. Optional:
         * `filters`, `sort`, `is_default`.  When is_default is truthy, all other
         * views for the board are unset as default.
         */
        case 'save_view': {
            $boardId  = (int)($_POST['board_id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $filters  = $_POST['filters'] ?? '{}';
            $sort     = $_POST['sort'] ?? '{}';
            $isDefault = (int)($_POST['is_default'] ?? 0);
            if (!$boardId) respond_error('Board ID required');
            if (!$name) respond_error('View name required');
            require_board_role($boardId, 'contributor');
            $DB->beginTransaction();
            try {
                // Unset other default views if needed
                if ($isDefault) {
                    $stmt = $DB->prepare("UPDATE board_saved_views SET is_default = 0 WHERE board_id = ?");
                    $stmt->execute([$boardId]);
                }
                // Insert new view
                $stmt = $DB->prepare(
                    "
                    INSERT INTO board_saved_views (board_id, name, filters_json, sort_json, is_default, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    "
                );
                $stmt->execute([$boardId, $name, $filters, $sort, $isDefault, $USER_ID]);
                $viewId = $DB->lastInsertId();
                $DB->commit();
                respond_ok(['view_id' => $viewId]);
            } catch (Exception $e) {
                $DB->rollBack();
                throw $e;
            }
            break;
        }

        /**
         * Fetch saved views for a board. Required: `board_id`. Returns
         * an array of view objects (id, name, filters_json, sort_json, is_default).
         * This mirrors the behaviour of view.list.php but is exposed via
         * the unified boards API to avoid CORS or routing issues.
         */
        case 'views': {
            $boardId = (int)($_POST['board_id'] ?? $_GET['board_id'] ?? 0);
            if (!$boardId) respond_error('Board ID required');
            require_board_role($boardId, 'viewer');
            try {
                $stmt = $DB->prepare(
                    "SELECT id, name, filters_json, sort_json, is_default \n"
                    . "FROM board_saved_views \n"
                    . "WHERE board_id = ? \n"
                    . "ORDER BY is_default DESC, name ASC"
                );
                $stmt->execute([$boardId]);
                $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
                respond_ok(['views' => $views]);
            } catch (Exception $e) {
                // If the views table or query fails, return an empty list rather
                // than throwing a 500.  This ensures the client can load the
                // board without surfacing an error toast.  Log the error for
                // server diagnostics.
                error_log('Boards views action error: ' . $e->getMessage());
                respond_ok(['views' => []]);
            }
            break;
        }

        /**
         * Watch an item to receive updates.  Required: `item_id`.  Records a
         * watch action in the audit log so that the client can derive the
         * current watcher list without requiring a dedicated table.  At least
         * viewer role is required on the board.  The response payload simply
         * acknowledges the watch action.  Multiple watch calls for the same
         * user and item are idempotent at the audit level.
         */
        case 'watch_item': {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if (!$itemId) respond_error('Item ID required');
            // Resolve board ID for permission check
            $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
            $stmt->execute([$itemId, $COMPANY_ID]);
            $it = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$it) respond_error('Item not found', 404);
            $boardId = (int)$it['board_id'];
            // At least viewer role is needed to watch an item
            require_board_role($boardId, 'viewer');
            try {
                // Record watch event in audit log.  Do not include details
                // payload to minimise storage; presence of the entry implies
                // watch state.  If a previous watch/unwatch entry exists, the
                // latest entry determines the current state.
                $stmt = $DB->prepare(
                    "INSERT INTO board_audit_log (company_id, board_id, item_id, user_id, action, details, ip_address, created_at)
                     VALUES (?, ?, ?, ?, 'item_watched', NULL, ?, NOW())"
                );
                $stmt->execute([
                    $COMPANY_ID,
                    $boardId,
                    $itemId,
                    $USER_ID,
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);
                respond_ok(['watched' => true]);
            } catch (Exception $e) {
                error_log('watch_item error: ' . $e->getMessage());
                respond_error('Failed to watch item', 500);
            }
            break;
        }

        /**
         * Stop watching an item.  Required: `item_id`.  Adds an unwatch entry
         * to the audit log.  The latest watch/unwatch entry per user
         * determines whether the user is currently watching the item.  At
         * least viewer role is required.  Response acknowledges the action.
         */
        case 'unwatch_item': {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if (!$itemId) respond_error('Item ID required');
            // Resolve board ID
            $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
            $stmt->execute([$itemId, $COMPANY_ID]);
            $it = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$it) respond_error('Item not found', 404);
            $boardId = (int)$it['board_id'];
            require_board_role($boardId, 'viewer');
            try {
                $stmt = $DB->prepare(
                    "INSERT INTO board_audit_log (company_id, board_id, item_id, user_id, action, details, ip_address, created_at)
                     VALUES (?, ?, ?, ?, 'item_unwatched', NULL, ?, NOW())"
                );
                $stmt->execute([
                    $COMPANY_ID,
                    $boardId,
                    $itemId,
                    $USER_ID,
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);
                respond_ok(['watched' => false]);
            } catch (Exception $e) {
                error_log('unwatch_item error: ' . $e->getMessage());
                respond_error('Failed to unwatch item', 500);
            }
            break;
        }

        /**
         * List watchers for an item.  Required: `item_id`.  Returns an array of
         * users currently watching the item, each with user_id, first_name and
         * last_name.  To compute the watcher list, the most recent watch or
         * unwatch entry for each user in the audit log is considered.  Users
         * whose last action was 'item_watched' are returned.  At least viewer
         * role is required.  If the audit log is unavailable, an empty list
         * is returned.
         */
        case 'list_watchers': {
            $itemId = (int)($_POST['item_id'] ?? ($_GET['item_id'] ?? 0));
            if (!$itemId) respond_error('Item ID required');
            // Resolve board ID
            $stmt = $DB->prepare("SELECT board_id FROM board_items WHERE id = ? AND company_id = ?");
            $stmt->execute([$itemId, $COMPANY_ID]);
            $it = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$it) respond_error('Item not found', 404);
            $boardId = (int)$it['board_id'];
            require_board_role($boardId, 'viewer');
            try {
                // Subquery to find the latest audit entry for each user for this item
                $sql = "SELECT ba.user_id, ba.action, u.first_name, u.last_name
                        FROM board_audit_log ba
                        JOIN (
                            SELECT user_id, MAX(id) AS max_id
                            FROM board_audit_log
                            WHERE company_id = ? AND board_id = ? AND item_id = ? AND action IN ('item_watched','item_unwatched')
                            GROUP BY user_id
                        ) latest ON ba.id = latest.max_id
                        JOIN users u ON ba.user_id = u.id
                        WHERE ba.action = 'item_watched'";
                $stmt = $DB->prepare($sql);
                $stmt->execute([$COMPANY_ID, $boardId, $itemId]);
                $watchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // Map to simple array with id and names
                $list = [];
                foreach ($watchers as $w) {
                    $list[] = [
                        'user_id'    => (int)$w['user_id'],
                        'first_name' => $w['first_name'],
                        'last_name'  => $w['last_name'],
                    ];
                }
                respond_ok(['watchers' => $list]);
            } catch (Exception $e) {
                error_log('list_watchers error: ' . $e->getMessage());
                respond_ok(['watchers' => []]);
            }
            break;
        }
        default:
            respond_error('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    // Generic error handling
    error_log('Unified boards.php error: ' . $e->getMessage());
    respond_error('Failed to handle action', 500);
}