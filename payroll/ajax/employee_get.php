<?php
// /payroll/ajax/employee_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$id = $_GET['id'] ?? 0;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'Missing ID']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT * FROM employees 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$id, $companyId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        echo json_encode(['ok' => false, 'error' => 'Employee not found']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'employee' => $employee
    ]);
} catch (Exception $e) {
    error_log("Employee get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}