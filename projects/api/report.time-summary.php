<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = (int)($_GET['project_id'] ?? 0);
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // First day of month
$endDate = $_GET['end_date'] ?? date('Y-m-d');

if (!$projectId) respond_error('Project ID required');

require_project_role($projectId, 'viewer');

try {
    // Time by user
    $stmt = $DB->prepare("
        SELECT 
            u.first_name, u.last_name,
            SUM(t.hours) as total_hours,
            SUM(CASE WHEN t.billable = 1 THEN t.hours ELSE 0 END) as billable_hours,
            COUNT(DISTINCT t.date) as days_worked
        FROM timesheets t
        JOIN users u ON t.user_id = u.id
        WHERE t.project_id = ? AND t.company_id = ?
        AND t.date BETWEEN ? AND ?
        GROUP BY t.user_id
        ORDER BY total_hours DESC
    ");
    $stmt->execute([$projectId, $COMPANY_ID, $startDate, $endDate]);
    $byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Time by item
    $stmt = $DB->prepare("
        SELECT 
            bi.title,
            SUM(t.hours) as total_hours
        FROM timesheets t
        JOIN board_items bi ON t.item_id = bi.id
        WHERE t.project_id = ? AND t.company_id = ?
        AND t.date BETWEEN ? AND ?
        GROUP BY t.item_id
        ORDER BY total_hours DESC
        LIMIT 20
    ");
    $stmt->execute([$projectId, $COMPANY_ID, $startDate, $endDate]);
    $byItem = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Time by date (for chart)
    $stmt = $DB->prepare("
        SELECT 
            DATE(t.date) as date,
            SUM(t.hours) as total_hours
        FROM timesheets t
        WHERE t.project_id = ? AND t.company_id = ?
        AND t.date BETWEEN ? AND ?
        GROUP BY DATE(t.date)
        ORDER BY date ASC
    ");
    $stmt->execute([$projectId, $COMPANY_ID, $startDate, $endDate]);
    $byDate = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Totals
    $totalHours = array_sum(array_column($byUser, 'total_hours'));
    $totalBillable = array_sum(array_column($byUser, 'billable_hours'));
    
    respond_ok([
        'period' => [
            'start' => $startDate,
            'end' => $endDate
        ],
        'totals' => [
            'hours' => (float)$totalHours,
            'billable_hours' => (float)$totalBillable,
            'non_billable_hours' => (float)($totalHours - $totalBillable)
        ],
        'by_user' => $byUser,
        'by_item' => $byItem,
        'by_date' => $byDate
    ]);
    
} catch (Exception $e) {
    error_log("Time summary error: " . $e->getMessage());
    respond_error('Failed to generate time summary', 500);
}