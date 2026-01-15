<?php
// /payroll/ajax/run_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$status = $_GET['status'] ?? '';
$frequency = $_GET['frequency'] ?? '';

$sql = "SELECT 
            pr.id,
            pr.run_number,
            pr.name,
            pr.frequency,
            pr.period_start,
            pr.period_end,
            pr.pay_date,
            pr.status,
            COUNT(pre.id) as employee_count,
            SUM(pre.net_cents) as net_total_cents
        FROM pay_runs pr
        LEFT JOIN pay_run_employees pre ON pre.run_id = pr.id
        WHERE pr.company_id = ?";

$params = [$companyId];

if ($status) {
    $statuses = explode(',', $status);
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql .= " AND pr.status IN ($placeholders)";
    $params = array_merge($params, $statuses);
}

if ($frequency) {
    $sql .= " AND pr.frequency = ?";
    $params[] = $frequency;
}

$sql .= " GROUP BY pr.id ORDER BY pr.pay_date DESC, pr.created_at DESC LIMIT 100";

try {
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'runs' => $runs
    ]);
} catch (Exception $e) {
    error_log("Run list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Database error'
    ]);
}