<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) respond_error('Project ID required');

require_project_role($projectId, 'viewer');

try {
    // Project meta
    $stmt = $DB->prepare("SELECT * FROM projects WHERE project_id = ? AND company_id = ?");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) respond_error('Project not found', 404);
    
    // Total items
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE pb.project_id = ? AND bi.company_id = ? AND bi.archived = 0
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $totalItems = (int)$stmt->fetchColumn();
    
    // Items by status
    $stmt = $DB->prepare("
        SELECT status_label, COUNT(*) as count
        FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE pb.project_id = ? AND bi.company_id = ? AND bi.archived = 0
        GROUP BY status_label
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $statusBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Overdue items
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE pb.project_id = ? AND bi.company_id = ? 
        AND bi.archived = 0 AND bi.due_date < CURDATE() AND bi.status_label != 'done'
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $overdueItems = (int)$stmt->fetchColumn();
    
    // Upcoming milestones (next 30 days)
    $stmt = $DB->prepare("
        SELECT title, due_date FROM board_items bi
        JOIN project_boards pb ON bi.board_id = pb.board_id
        WHERE pb.project_id = ? AND bi.company_id = ? 
        AND bi.archived = 0 AND bi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY bi.due_date ASC
        LIMIT 10
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $upcomingMilestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Budget vs Actual
    $budget = (float)$project['budget'];
    $actualCost = (float)$project['actual_cost'];
    $budgetUsed = $budget > 0 ? ($actualCost / $budget) * 100 : 0;
    
    // Time logged
    $stmt = $DB->prepare("
        SELECT SUM(hours) as total_hours, SUM(CASE WHEN billable = 1 THEN hours ELSE 0 END) as billable_hours
        FROM timesheets
        WHERE project_id = ? AND company_id = ?
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
    $timeStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Recent activity (last 7 days)
    $stmt = $DB->prepare("
        SELECT action, details, created_at FROM board_audit_log
        WHERE company_id = ? AND board_id IN (
            SELECT board_id FROM project_boards WHERE project_id = ?
        )
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$COMPANY_ID, $projectId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond_ok([
        'project' => $project,
        'health' => [
            'total_items' => $totalItems,
            'status_breakdown' => $statusBreakdown,
            'overdue_items' => $overdueItems,
            'upcoming_milestones' => $upcomingMilestones,
            'budget_used_percent' => round($budgetUsed, 2),
            'total_hours' => (float)($timeStats['total_hours'] ?? 0),
            'billable_hours' => (float)($timeStats['billable_hours'] ?? 0),
            'recent_activity' => $recentActivity
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Project health report error: " . $e->getMessage());
    respond_error('Failed to generate report', 500);
}