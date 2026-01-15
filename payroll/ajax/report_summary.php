<?php
// /payroll/ajax/report_summary.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$period = $_GET['period'] ?? '';
$format = $_GET['format'] ?? 'json';

if (!$period || !preg_match('/^\d{4}-\d{2}$/', $period)) {
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid period']);
    } else {
        die('Invalid period');
    }
    exit;
}

try {
    list($year, $month) = explode('-', $period);
    $periodStart = "$year-$month-01";
    $periodEnd = date('Y-m-t', strtotime($periodStart));

    $stmt = $DB->prepare("
        SELECT 
            e.employee_no,
            CONCAT(e.first_name, ' ', e.last_name) as name,
            SUM(pre.gross_cents) as gross,
            SUM(pre.paye_cents) as paye,
            SUM(pre.uif_employee_cents) as uif,
            SUM(pre.other_deductions_cents) as deductions,
            SUM(pre.net_cents) as net
        FROM employees e
        JOIN pay_run_employees pre ON pre.employee_id = e.id
        JOIN pay_runs pr ON pr.id = pre.run_id
        WHERE pr.company_id = ?
        AND pr.pay_date >= ? AND pr.pay_date <= ?
        AND pr.status IN ('locked', 'posted')
        GROUP BY e.id
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'period' => $period,
        'period_label' => date('F Y', strtotime($periodStart)),
        'employees' => $employees,
        'total_gross' => array_sum(array_column($employees, 'gross')),
        'total_paye' => array_sum(array_column($employees, 'paye')),
        'total_uif' => array_sum(array_column($employees, 'uif')),
        'total_deductions' => array_sum(array_column($employees, 'deductions')),
        'total_net' => array_sum(array_column($employees, 'net'))
    ];

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="Payroll_Summary_' . $period . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Payroll Summary']);
        fputcsv($output, ['Period', $summary['period_label']]);
        fputcsv($output, []);
        fputcsv($output, ['Employee', 'Gross (cents)', 'PAYE (cents)', 'UIF (cents)', 'Deductions (cents)', 'Net (cents)']);
        
        foreach ($employees as $emp) {
            fputcsv($output, [
                $emp['name'],
                $emp['gross'],
                $emp['paye'],
                $emp['uif'],
                $emp['deductions'],
                $emp['net']
            ]);
        }
        
        fputcsv($output, ['TOTAL', $summary['total_gross'], $summary['total_paye'], $summary['total_uif'], $summary['total_deductions'], $summary['total_net']]);
        fclose($output);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'summary' => $summary
    ]);

} catch (Exception $e) {
    error_log("Summary report error: " . $e->getMessage());
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Report failed']);
    } else {
        die('Report failed');
    }
}