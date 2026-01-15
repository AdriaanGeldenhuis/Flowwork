<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
$templateFile = $_POST['template'] ?? null;
$dryRun = (int)($_POST['dry_run'] ?? 0);

if (!$projectId) respond_error('Project ID required');
if (!$templateFile) respond_error('Template file required');

require_project_role($projectId, 'manager');

// Load template
$templatePath = __DIR__ . '/../templates/' . basename($templateFile) . '.json';
if (!file_exists($templatePath)) {
    respond_error('Template not found', 404);
}

$templateData = json_decode(file_get_contents($templatePath), true);
if (!$templateData) {
    respond_error('Invalid template format', 400);
}

// Validate structure
$required = ['name', 'description', 'boards'];
foreach ($required as $field) {
    if (!isset($templateData[$field])) {
        respond_error("Template missing required field: $field", 400);
    }
}

if ($dryRun) {
    respond_ok([
        'dry_run' => true,
        'template' => $templateData['name'],
        'boards_count' => count($templateData['boards']),
        'estimated_items' => array_sum(array_map(function($b) {
            return array_sum(array_map(function($g) {
                return count($g['items'] ?? []);
            }, $b['groups'] ?? []));
        }, $templateData['boards']))
    ]);
}

try {
    $DB->beginTransaction();
    
    $createdBoards = [];
    
    foreach ($templateData['boards'] as $boardDef) {
        // Create board
        $stmt = $DB->prepare("
            INSERT INTO project_boards (
                company_id, project_id, title, board_type, default_view, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $COMPANY_ID, 
            $projectId, 
            $boardDef['title'], 
            $boardDef['type'] ?? 'main',
            $boardDef['default_view'] ?? 'table'
        ]);
        $boardId = $DB->lastInsertId();
        $createdBoards[] = $boardId;
        
        // Create columns
        $columnMap = [];
        if (isset($boardDef['columns'])) {
            $position = 0;
            foreach ($boardDef['columns'] as $colDef) {
                $stmt = $DB->prepare("
                    INSERT INTO board_columns (
                        board_id, company_id, name, type, width, position, visible, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt->execute([
                    $boardId, $COMPANY_ID, 
                    $colDef['name'], 
                    $colDef['type'] ?? 'text',
                    $colDef['width'] ?? 150,
                    $position++
                ]);
                $columnMap[$colDef['id'] ?? $colDef['name']] = $DB->lastInsertId();
            }
        }
        
        // Create groups
        if (isset($boardDef['groups'])) {
            $groupPosition = 0;
            foreach ($boardDef['groups'] as $groupDef) {
                $stmt = $DB->prepare("
                    INSERT INTO board_groups (
                        board_id, name, color, position, collapsed, created_at
                    ) VALUES (?, ?, ?, ?, 0, NOW())
                ");
                $stmt->execute([
                    $boardId,
                    $groupDef['name'],
                    $groupDef['color'] ?? '#8b5cf6',
                    $groupPosition++
                ]);
                $groupId = $DB->lastInsertId();
                
                // Create items
                if (isset($groupDef['items'])) {
                    $itemPosition = 0;
                    foreach ($groupDef['items'] as $itemDef) {
                        $stmt = $DB->prepare("
                            INSERT INTO board_items (
                                board_id, company_id, group_id, title, description, 
                                status_label, priority, position, created_by, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $boardId, $COMPANY_ID, $groupId,
                            $itemDef['title'],
                            $itemDef['description'] ?? null,
                            $itemDef['status'] ?? null,
                            $itemDef['priority'] ?? null,
                            $itemPosition++,
                            $USER_ID
                        ]);
                        $itemId = $DB->lastInsertId();
                        
                        // Set column values
                        if (isset($itemDef['values'])) {
                            $stmtVal = $DB->prepare("
                                INSERT INTO board_item_values (item_id, column_id, value)
                                VALUES (?, ?, ?)
                            ");
                            foreach ($itemDef['values'] as $colName => $value) {
                                if (isset($columnMap[$colName])) {
                                    $stmtVal->execute([$itemId, $columnMap[$colName], $value]);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    $DB->commit();
    
    respond_ok([
        'imported' => true,
        'template' => $templateData['name'],
        'boards_created' => $createdBoards
    ]);
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Template import error: " . $e->getMessage());
    respond_error('Failed to import template: ' . $e->getMessage(), 500);
}