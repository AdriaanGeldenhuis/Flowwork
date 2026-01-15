<?php
// /payroll/ajax/run_employees.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$runId = $_GET['run_id'] ?? 0;

if (!$runId) {
    echo json_encode(['ok' => false, 'error' => 'Missing run_id']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT 
            pre.id,
            pre.employee_id,
            e.employee_no,
            e.first_name,
            e.last_name,
            CONCAT(SUBSTRING(e.first_name, 1, 1), SUBSTRING(e.last_name, 1, 1)) as initials,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name,
            pre.gross_cents,
            pre.paye_cents,
            pre.uif_employee_cents,
            pre.other_deductions_cents,
            pre.net_cents
        FROM pay_run_employees pre
        JOIN employees e ON e.id = pre.employee_id
        WHERE pre.run_id = ? AND pre.company_id = ?
        ORDER BY e.last_name ASC, e.first_name ASC
    ");
    $stmt->execute([$runId, $companyId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'employees' => $employees
    ]);
} catch (Exception $e) {
    error_log("Run employees error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}