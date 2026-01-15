<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;

if ($projectId) {
    require_project_role($projectId, 'viewer');
    
    $sql = "SELECT * FROM calendar_events WHERE company_id = ? AND project_id = ?";
    $params = [$COMPANY_ID, $projectId];
    
    if ($start && $end) {
        $sql .= " AND start_datetime >= ? AND start_datetime <= ?";
        $params[] = $start;
        $params[] = $end;
    }
    
    $sql .= " ORDER BY start_datetime ASC";
    
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
} else {
    if ($USER_ROLE !== 'admin' && $USER_ROLE !== 'manager') {
        respond_error('Access denied', 403);
    }
    
    $sql = "SELECT ce.*, p.name as project_name FROM calendar_events ce LEFT JOIN projects p ON ce.project_id = p.project_id WHERE ce.company_id = ?";
    $params = [$COMPANY_ID];
    
    if ($start && $end) {
        $sql .= " AND ce.start_datetime >= ? AND ce.start_datetime <= ?";
        $params[] = $start;
        $params[] = $end;
    }
    
    $sql .= " ORDER BY ce.start_datetime ASC";
    
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
}

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
respond_ok(['events' => $events]);