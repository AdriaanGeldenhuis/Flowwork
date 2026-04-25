<?php
// /payroll/ajax/payslip_email.php
// Manually email payslips for a run. Re-sends to anyone whose
// payslip_emailed_at is NULL (or all employees if force=1).

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$runId     = isset($_REQUEST['run_id']) ? (int)$_REQUEST['run_id'] : 0;
$force     = !empty($_REQUEST['force']);

if (!$runId) {
    echo json_encode(['ok' => false, 'error' => 'Missing run_id']);
    exit;
}

require_once __DIR__ . '/../lib/PayslipEmailer.php';

try {
    $res = emailPayslips($DB, $companyId, $runId, !$force);
    echo json_encode(['ok' => true] + $res);
} catch (Exception $e) {
    error_log('Payslip email error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
