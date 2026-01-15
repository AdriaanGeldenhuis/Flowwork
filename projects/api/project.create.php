<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_respond.php';

$name = trim($_POST['name'] ?? '');
$managerId = (int)($_POST['manager_user_id'] ?? 0);
$startDate = $_POST['start_date'] ?? null;
$endDate = $_POST['end_date'] ?? null;
$budget = (int)($_POST['budget'] ?? 0);
$template = trim($_POST['template'] ?? '');

if (!$name) respond_error('Project name is required');

try {
    $DB->beginTransaction();
    
    // Create project
    $stmt = $DB->prepare("
        INSERT INTO projects (company_id, name, project_manager_id, owner_id, 
                             start_date, end_date, budget, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
    ");
    $stmt->execute([$COMPANY_ID, $name, $managerId, $USER_ID, $startDate, $endDate, $budget, $USER_ID]);
    $projectId = $DB->lastInsertId();
    
    // Add creator as owner
    // Insert creator as owner
    $stmtOwner = $DB->prepare(
        "INSERT INTO project_members (company_id, project_id, user_id, role, can_edit, added_at)
         VALUES (?, ?, ?, 'owner', 1, NOW())"
    );
    $stmtOwner->execute([$COMPANY_ID, $projectId, $USER_ID]);

    // Add manager if different from creator
    if ($managerId && $managerId !== $USER_ID) {
        $stmtMgr = $DB->prepare(
            "INSERT INTO project_members (company_id, project_id, user_id, role, can_edit, added_at)
             VALUES (?, ?, ?, 'manager', 1, NOW())"
        );
        $stmtMgr->execute([$COMPANY_ID, $projectId, $managerId]);
    }

    // If a template is specified, apply it to create boards, columns and groups
    if ($template) {
        // Only allow alphanumeric and underscores to avoid directory traversal
        $templateSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', $template);
        $templatePath = __DIR__ . '/../templates/' . $templateSafe . '.json';
        if (file_exists($templatePath)) {
            $json = file_get_contents($templatePath);
            $tpl = json_decode($json, true);
            if ($tpl && isset($tpl['boards']) && is_array($tpl['boards'])) {
                foreach ($tpl['boards'] as $boardDef) {
                    // Prepare defaults
                    $bTitle = $boardDef['title'] ?? 'Untitled Board';
                    // Use a generic board type for all template boards.  The board_type
                    // value is used to determine the icon and behaviour in the
                    // front‑end.  Custom values such as "schedule", "costing" or
                    // "procurement" are not recognised and may cause boards to be
                    // hidden.  Default to 'work' (same as board.create) so that
                    // all boards show up consistently in the project view.
                    $bType  = 'work';
                    $bView  = $boardDef['default_view'] ?? 'table';
                    // Insert board
                    $stmt = $DB->prepare("INSERT INTO project_boards (company_id, project_id, title, board_type, default_view, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$COMPANY_ID, $projectId, $bTitle, $bType, $bView]);
                    $boardId = $DB->lastInsertId();
                    // Insert columns
                    $columnMap = [];
                    if (isset($boardDef['columns']) && is_array($boardDef['columns'])) {
                        $colPos = 0;
                        foreach ($boardDef['columns'] as $col) {
                            $colName = $col['name'] ?? 'Column';
                            $colType = $col['type'] ?? 'text';
                            $colWidth = $col['width'] ?? 150;
                            $colConfig = null;
                            if (isset($col['config']) && is_array($col['config'])) {
                                // Ensure JSON string for config
                                $colConfig = json_encode($col['config']);
                            }
                            $stmtCol = $DB->prepare("INSERT INTO board_columns (board_id, company_id, name, type, config, position, visible, width, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())");
                            $stmtCol->execute([$boardId, $COMPANY_ID, $colName, $colType, $colConfig, $colPos++, $colWidth]);
                            $newColId = $DB->lastInsertId();
                            // Map template id or name to new column id for values (not used for groups)
                            $mapKey = $col['id'] ?? $colName;
                            $columnMap[$mapKey] = $newColId;
                        }
                    }
                    // Insert groups
                    if (isset($boardDef['groups']) && is_array($boardDef['groups'])) {
                        $grpPos = 0;
                        foreach ($boardDef['groups'] as $grp) {
                            $grpName = $grp['name'] ?? 'Group';
                            $grpColor= $grp['color'] ?? '#8b5cf6';
                            $stmtGrp = $DB->prepare("INSERT INTO board_groups (board_id, name, color, position, collapsed, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
                            $stmtGrp->execute([$boardId, $grpName, $grpColor, $grpPos++]);
                            $groupId = $DB->lastInsertId();
                            // Insert items if provided
                            if (isset($grp['items']) && is_array($grp['items'])) {
                                $itemPos = 0;
                                foreach ($grp['items'] as $item) {
                                    $title = $item['title'] ?? '';
                                    $desc = $item['description'] ?? null;
                                    $status = $item['status'] ?? null;
                                    $priority = $item['priority'] ?? null;
                                    $stmtItem = $DB->prepare("INSERT INTO board_items (board_id, company_id, group_id, title, description, status_label, priority, position, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                                    $stmtItem->execute([$boardId, $COMPANY_ID, $groupId, $title, $desc, $status, $priority, $itemPos++, $USER_ID]);
                                    $itemId = $DB->lastInsertId();
                                    // Insert item values
                                    if (isset($item['values']) && is_array($item['values'])) {
                                        foreach ($item['values'] as $colKey => $val) {
                                            if (isset($columnMap[$colKey])) {
                                                $stmtVal = $DB->prepare("INSERT INTO board_item_values (item_id, column_id, value) VALUES (?, ?, ?)");
                                                $stmtVal->execute([$itemId, $columnMap[$colKey], $val]);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    $DB->commit();
    respond_ok(['project_id' => $projectId]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Project create error: " . $e->getMessage());
    respond_error('Failed to create project', 500);
}