<?php
// /payroll/ajax/run_employee_detail.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$runId = $_GET['run_id'] ?? 0;
$employeeId = $_GET['employee_id'] ?? 0;

if (!$runId || !$employeeId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

try {
    // Get employee summary
    $stmt = $DB->prepare("
        SELECT 
            pre.*,
            e.employee_no,
            e.first_name,
            e.last_name,
            CONCAT(e.first_name, ' ', e.last_name) as employee_name
        FROM pay_run_employees pre
        JOIN employees e ON e.id = pre.employee_id
        WHERE pre.run_id = ? AND pre.employee_id = ? AND pre.company_id = ?
    ");
    $stmt->execute([$runId, $employeeId, $companyId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        echo json_encode(['ok' => false, 'error' => 'Employee not found in run']);
        exit;
    }

    // Get lines
    $stmt = $DB->prepare("
        SELECT 
            prl.*,
            pi.code as payitem_code,
            pi.name as payitem_name,
            pi.type as payitem_type
        FROM pay_run_lines prl
        LEFT JOIN payitems pi ON pi.id = prl.payitem_id
        WHERE prl.run_id = ? AND prl.employee_id = ? AND prl.company_id = ?
        ORDER BY pi.type ASC, prl.id ASC
    ");
    $stmt->execute([$runId, $employeeId, $companyId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'employee' => $employee,
        'lines' => $lines
    ]);
} catch (Exception $e) {
    error_log("Employee detail error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}