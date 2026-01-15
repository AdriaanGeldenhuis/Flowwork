<?php
// /payroll/ajax/run_export_bank.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$runId = $_GET['run_id'] ?? 0;

if (!$runId) {
    die('Missing run_id');
}

try {
    // Verify run is locked or posted
    $stmt = $DB->prepare("
        SELECT * FROM pay_runs 
        WHERE id = ? AND company_id = ? AND status IN ('locked', 'posted')
    ");
    $stmt->execute([$runId, $companyId]);
    $run = $stmt->fetch();

    if (!$run) {
        die('Run not found or not locked');
    }

    // Get employees
    $stmt = $DB->prepare("
        SELECT 
            e.employee_no,
            e.first_name,
            e.last_name,
            e.bank_name,
            e.branch_code,
            e.bank_account_no,
            pre.net_cents,
            pre.bank_amount_cents
        FROM pay_run_employees pre
        JOIN employees e ON e.id = pre.employee_id
        WHERE pre.run_id = ? AND pre.company_id = ?
        AND e.bank_account_no IS NOT NULL
        AND pre.bank_amount_cents > 0
        ORDER BY e.last_name ASC, e.first_name ASC
    ");
    $stmt->execute([$runId, $companyId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($employees) === 0) {
        die('No employees with bank details found');
    }

    // Generate CSV (Standard Bank format)
    $filename = 'payroll_' . $run['run_number'] . '_' . date('Ymd') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, ['Employee No', 'First Name', 'Last Name', 'Bank', 'Branch', 'Account', 'Amount']);
    
    $totalCents = 0;
    foreach ($employees as $emp) {
        $amount = number_format($emp['bank_amount_cents'] / 100, 2, '.', '');
        
        fputcsv($output, [
            $emp['employee_no'],
            $emp['first_name'],
            $emp['last_name'],
            $emp['bank_name'],
            $emp['branch_code'],
            $emp['bank_account_no'],
            $amount
        ]);
        
        $totalCents += $emp['bank_amount_cents'];
    }
    
    // Total row
    fputcsv($output, ['', '', '', '', '', 'TOTAL:', number_format($totalCents / 100, 2, '.', '')]);
    
    fclose($output);
    
    // Log export
    $stmt = $DB->prepare("
        INSERT INTO bank_file_exports (company_id, run_id, format, file_path, total_amount_cents, employee_count, created_by, created_at)
        VALUES (?, ?, 'standard_bank_csv', ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId, $runId, $filename, $totalCents, count($employees), $userId
    ]);
    
    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'payrun_bank_export', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['run_id' => $runId, 'employees' => count($employees), 'total' => $totalCents]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    exit;

} catch (Exception $e) {
    error_log("Bank export error: " . $e->getMessage());
    die('Export failed');
}