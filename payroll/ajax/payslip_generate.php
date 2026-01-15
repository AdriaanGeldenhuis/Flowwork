<?php
// /payroll/ajax/payslip_generate.php
// Generate payslips for a payroll run. This endpoint can be used to (re)generate
// payslips for all employees in a given run. It returns the number of payslips
// generated. To view the payslips, navigate to /payroll/payslips.php?run_id={id}.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Only admins and payroll managers/bookkeepers should access this endpoint
require_once __DIR__ . '/../../finances/permissions.php';
// In payroll context, use the same permission helper; reuse roles
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

// Get parameters from POST or GET
$companyId = $_SESSION['company_id'];
$runId = isset($_REQUEST['run_id']) ? (int)$_REQUEST['run_id'] : 0;

if (!$runId) {
    echo json_encode(['ok' => false, 'error' => 'Missing run_id']);
    exit;
}

// Include our generator function
require_once __DIR__ . '/../lib/PayslipGenerator.php';

try {
    $count = generatePayslips($DB, (int)$companyId, (int)$runId);
    echo json_encode(['ok' => true, 'count' => $count]);
} catch (Exception $e) {
    error_log('Payslip generation error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}