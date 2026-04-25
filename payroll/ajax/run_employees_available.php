<?php
// /payroll/ajax/run_employees_available.php
// List active employees matching the run's frequency that are not yet in the run.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$runId = (int)($_GET['run_id'] ?? 0);

if (!$runId) {
    echo json_encode(['ok' => false, 'error' => 'Missing run_id']);
    exit;
}

try {
    $stmt = $DB->prepare("SELECT frequency FROM pay_runs WHERE id = ? AND company_id = ?");
    $stmt->execute([$runId, $companyId]);
    $run = $stmt->fetch();

    if (!$run) {
        echo json_encode(['ok' => false, 'error' => 'Run not found']);
        exit;
    }

    $stmt = $DB->prepare("
        SELECT
            e.id,
            e.employee_no,
            e.first_name,
            e.last_name,
            e.pay_frequency,
            e.base_salary_cents
        FROM employees e
        WHERE e.company_id = ?
          AND e.pay_frequency = ?
          AND e.termination_date IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM pay_run_employees pre
              WHERE pre.run_id = ? AND pre.employee_id = e.id
          )
        ORDER BY e.last_name ASC, e.first_name ASC
    ");
    $stmt->execute([$companyId, $run['frequency'], $runId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'frequency' => $run['frequency'],
        'employees' => $employees
    ]);
} catch (Exception $e) {
    error_log("Run available employees error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
