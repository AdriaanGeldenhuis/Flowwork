<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_POST['project_id'] ?? 0);
if (!$projectId) respond_error('Project ID required');

require_project_role($projectId, 'owner');

try {
    $DB->beginTransaction();
    
    // Verify project belongs to company
    $stmt = $DB->prepare("SELECT project_id FROM projects WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$projectId, $COMPANY_ID]);
    if (!$stmt->fetch()) respond_error('Project not found', 404);
    
    // Get all boards for this project
    $stmt = $DB->prepare("SELECT board_id FROM project_boards WHERE project_id = ?");
    $stmt->execute([$projectId]);
    $boardIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Delete attachments from disk
    if (!empty($boardIds)) {
        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        $stmt = $DB->prepare("SELECT file_path FROM board_item_attachments WHERE item_id IN (SELECT id FROM board_items WHERE board_id IN ($placeholders))");
        $stmt->execute($boardIds);
        
        while ($file = $stmt->fetch()) {
            if (file_exists($file['file_path'])) {
                @unlink($file['file_path']);
            }
        }
    }
    
    // Delete database records (cascading from boards)
    if (!empty($boardIds)) {
        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        
        // Delete item-level data
        $DB->prepare("DELETE FROM board_item_attachments WHERE item_id IN (SELECT id FROM board_items WHERE board_id IN ($placeholders))")->execute($boardIds);
        $DB->prepare("DELETE FROM board_item_comments WHERE item_id IN (SELECT id FROM board_items WHERE board_id IN ($placeholders))")->execute($boardIds);
        $DB->prepare("DELETE FROM board_item_values WHERE item_id IN (SELECT id FROM board_items WHERE board_id IN ($placeholders))")->execute($boardIds);
        $DB->prepare("DELETE FROM board_watchers WHERE item_id IN (SELECT id FROM board_items WHERE board_id IN ($placeholders))")->execute($boardIds);
        $DB->prepare("DELETE FROM board_items WHERE board_id IN ($placeholders)")->execute($boardIds);
        
        // Delete board-level data
        $DB->prepare("DELETE FROM board_groups WHERE board_id IN ($placeholders)")->execute($boardIds);
        $DB->prepare("DELETE FROM board_columns WHERE board_id IN ($placeholders)")->execute($boardIds);
        $DB->prepare("DELETE FROM board_members WHERE board_id IN ($placeholders)")->execute($boardIds);
        $DB->prepare("DELETE FROM board_activity WHERE board_id IN ($placeholders)")->execute($boardIds);
        $DB->prepare("DELETE FROM board_saved_views WHERE board_id IN ($placeholders)")->execute($boardIds);
        $DB->prepare("DELETE FROM board_automations WHERE board_id IN ($placeholders)")->execute($boardIds);
        $DB->prepare("DELETE FROM board_dependencies WHERE board_id IN ($placeholders)")->execute($boardIds);
    }
    
    // Delete boards
    $DB->prepare("DELETE FROM project_boards WHERE project_id = ?")->execute([$projectId]);
    
    // Delete project members
    $DB->prepare("DELETE FROM project_members WHERE project_id = ?")->execute([$projectId]);
    
    // Delete project
    $DB->prepare("DELETE FROM projects WHERE project_id = ? AND company_id = ?")->execute([$projectId, $COMPANY_ID]);
    
    $DB->commit();
    respond_ok();
    
} catch (Exception $e) {
    $DB->rollBack();
    error_log("Project delete error: " . $e->getMessage());
    respond_error('Failed to delete project: ' . $e->getMessage(), 500);
}