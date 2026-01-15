<?php
// /payroll/ajax/employee_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$frequency = $_GET['frequency'] ?? '';

$sql = "SELECT id, employee_no, first_name, last_name, email, phone, 
               hire_date, termination_date, employment_type, pay_frequency,
               base_salary_cents
        FROM employees
        WHERE company_id = ?";

$params = [$companyId];

if ($status === 'active') {
    $sql .= " AND termination_date IS NULL";
} elseif ($status === 'terminated') {
    $sql .= " AND termination_date IS NOT NULL";
}

if ($frequency) {
    $sql .= " AND pay_frequency = ?";
    $params[] = $frequency;
}

if ($search) {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR employee_no LIKE ? OR email LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= " ORDER BY first_name ASC, last_name ASC LIMIT 200";

try {
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'employees' => $employees
    ]);
} catch (Exception $e) {
    error_log("Employee list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Database error'
    ]);
}