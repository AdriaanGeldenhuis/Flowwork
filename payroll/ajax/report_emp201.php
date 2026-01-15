<?php
// /payroll/ajax/report_emp201.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$period = $_GET['period'] ?? ''; // Format: YYYY-MM
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

    // Get runs in period
    $stmt = $DB->prepare("
        SELECT 
            pr.id,
            pr.name,
            COUNT(DISTINCT pre.employee_id) as employee_count,
            SUM(pre.gross_cents) as total_gross,
            SUM(pre.paye_cents) as total_paye,
            SUM(pre.uif_employee_cents + pre.uif_employer_cents) as total_uif,
            SUM(pre.sdl_cents) as total_sdl
        FROM pay_runs pr
        LEFT JOIN pay_run_employees pre ON pre.run_id = pr.id
        WHERE pr.company_id = ?
        AND pr.pay_date >= ? AND pr.pay_date <= ?
        AND pr.status IN ('locked', 'posted')
        GROUP BY pr.id
    ");
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPAYE = 0;
    $totalUIF = 0;
    $totalSDL = 0;
    $totalGross = 0;
    $totalEmployees = 0;

    foreach ($runs as $run) {
        $totalPAYE += $run['total_paye'] ?? 0;
        $totalUIF += $run['total_uif'] ?? 0;
        $totalSDL += $run['total_sdl'] ?? 0;
        $totalGross += $run['total_gross'] ?? 0;
        $totalEmployees += $run['employee_count'] ?? 0;
    }

    $report = [
        'period' => $period,
        'period_label' => date('F Y', strtotime($periodStart)),
        'paye' => $totalPAYE,
        'uif' => $totalUIF,
        'sdl' => $totalSDL,
        'total' => $totalPAYE + $totalUIF + $totalSDL,
        'gross' => $totalGross,
        'run_count' => count($runs),
        'employee_count' => $totalEmployees
    ];

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="EMP201_' . $period . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['EMP201 Monthly Return']);
        fputcsv($output, ['Period', $report['period_label']]);
        fputcsv($output, []);
        fputcsv($output, ['Item', 'Source Code', 'Amount (cents)']);
        fputcsv($output, ['PAYE', '3601', $report['paye']]);
        fputcsv($output, ['UIF', '3701', $report['uif']]);
        fputcsv($output, ['SDL', '3801', $report['sdl']]);
        fputcsv($output, ['Total', '', $report['total']]);
        fclose($output);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'report' => $report
    ]);

} catch (Exception $e) {
    error_log("EMP201 error: " . $e->getMessage());
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Report failed']);
    } else {
        die('Report failed');
    }
}