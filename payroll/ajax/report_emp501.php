<?php
// /payroll/ajax/report_emp501.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$period = $_GET['period'] ?? ''; // Format: YYYY-MM (Feb or Aug)
$format = $_GET['format'] ?? 'json';

if (!$period || !preg_match('/^\d{4}-(02|08)$/', $period)) {
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid period (must be Feb or Aug)']);
    } else {
        die('Invalid period');
    }
    exit;
}

try {
    list($year, $month) = explode('-', $period);
    
    // Determine 6-month range
    if ($month === '02') {
        // Mar-Aug previous year
        $startMonth = ($year - 1) . '-03-01';
        $endMonth = ($year - 1) . '-08-31';
    } else {
        // Sep previous year - Feb current year
        $startMonth = ($year - 1) . '-09-01';
        $endMonth = $year . '-02-28';
    }

    $months = [];
    $current = new DateTime($startMonth);
    $end = new DateTime($endMonth);

    while ($current <= $end) {
        $monthKey = $current->format('Y-m');
        
        $stmt = $DB->prepare("
            SELECT 
                SUM(pre.paye_cents) as paye,
                SUM(pre.uif_employee_cents + pre.uif_employer_cents) as uif,
                SUM(pre.sdl_cents) as sdl
            FROM pay_runs pr
            LEFT JOIN pay_run_employees pre ON pre.run_id = pr.id
            WHERE pr.company_id = ?
            AND pr.pay_date >= ? AND pr.pay_date <= ?
            AND pr.status IN ('locked', 'posted')
        ");
        
        $monthStart = $current->format('Y-m-01');
        $monthEnd = $current->format('Y-m-t');
        
        $stmt->execute([$companyId, $monthStart, $monthEnd]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $paye = $data['paye'] ?? 0;
        $uif = $data['uif'] ?? 0;
        $sdl = $data['sdl'] ?? 0;

        $months[] = [
            'label' => $current->format('M Y'),
            'paye' => $paye,
            'uif' => $uif,
            'sdl' => $sdl,
            'total' => $paye + $uif + $sdl
        ];

        $current->modify('+1 month');
    }

    $totalPAYE = array_sum(array_column($months, 'paye'));
    $totalUIF = array_sum(array_column($months, 'uif'));
    $totalSDL = array_sum(array_column($months, 'sdl'));

    $report = [
        'period' => $period,
        'period_label' => ($month === '02' ? 'Mar-Aug ' . ($year-1) : 'Sep ' . ($year-1) . ' - Feb ' . $year),
        'months' => $months,
        'total_paye' => $totalPAYE,
        'total_uif' => $totalUIF,
        'total_sdl' => $totalSDL,
        'grand_total' => $totalPAYE + $totalUIF + $totalSDL
    ];

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="EMP501_' . $period . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['EMP501 Reconciliation']);
        fputcsv($output, ['Period', $report['period_label']]);
        fputcsv($output, []);
        fputcsv($output, ['Month', 'PAYE (cents)', 'UIF (cents)', 'SDL (cents)', 'Total (cents)']);
        
        foreach ($months as $m) {
            fputcsv($output, [$m['label'], $m['paye'], $m['uif'], $m['sdl'], $m['total']]);
        }
        
        fputcsv($output, ['TOTAL', $totalPAYE, $totalUIF, $totalSDL, $report['grand_total']]);
        fclose($output);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'report' => $report
    ]);

} catch (Exception $e) {
    error_log("EMP501 error: " . $e->getMessage());
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Report failed']);
    } else {
        die('Report failed');
    }
}