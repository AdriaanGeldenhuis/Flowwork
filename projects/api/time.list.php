<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$itemId = (int)($_GET['item_id'] ?? 0);
$projectId = (int)($_GET['project_id'] ?? 0);

if (!$itemId && !$projectId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing item_id or project_id']);
    exit;
}

$COMPANY_ID = $_SESSION['company_id'];

$sql = "
    SELECT 
        t.id, t.date, t.hours, t.billable, t.note,
        u.first_name, u.last_name,
        t.created_at
    FROM timesheets t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.company_id = ?
";

$params = [$COMPANY_ID];

if ($itemId) {
    $sql .= " AND t.item_id = ?";
    $params[] = $itemId;
} else {
    $sql .= " AND t.project_id = ? AND t.item_id IS NULL";
    $params[] = $projectId;
}

$sql .= " ORDER BY t.date DESC, t.created_at DESC";

$stmt = $DB->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalHours = array_sum(array_column($logs, 'hours'));
$billableHours = array_sum(array_map(fn($l) => $l['billable'] ? (float)$l['hours'] : 0, $logs));

echo json_encode([
    'ok' => true, 
    'logs' => $logs, 
    'total_hours' => $totalHours,
    'billable_hours' => $billableHours
]);