<?php
// /payroll/ajax/report_cost_allocation.php
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
            p.project_id,
            p.name as project_name,
            SUM(prl.amount_cents) as wage_cost_cents,
            SUM(pre.uif_employer_cents + pre.sdl_cents) as employer_cost_cents
        FROM pay_run_lines prl
        JOIN pay_runs pr ON pr.id = prl.run_id
        LEFT JOIN projects p ON p.project_id = prl.project_id
        LEFT JOIN pay_run_employees pre ON pre.run_id = pr.id AND pre.employee_id = prl.employee_id
        WHERE pr.company_id = ?
        AND pr.pay_date >= ? AND pr.pay_date <= ?
        AND pr.status IN ('locked', 'posted')
        AND prl.project_id IS NOT NULL
        GROUP BY prl.project_id
        ORDER BY p.name
    ");
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $projectData = [];
    foreach ($projects as $proj) {
        $wageCost = $proj['wage_cost_cents'] ?? 0;
        $employerCost = $proj['employer_cost_cents'] ?? 0;
        
        $projectData[] = [
            'name' => $proj['project_name'] ?? 'Project #' . $proj['project_id'],
            'wage_cost' => $wageCost,
            'employer_cost' => $employerCost,
            'total_cost' => $wageCost + $employerCost
        ];
    }

    $allocation = [
        'period' => $period,
        'period_label' => date('F Y', strtotime($periodStart)),
        'projects' => $projectData,
        'total_wage' => array_sum(array_column($projectData, 'wage_cost')),
        'total_employer' => array_sum(array_column($projectData, 'employer_cost')),
        'grand_total' => array_sum(array_column($projectData, 'total_cost'))
    ];

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="Cost_Allocation_' . $period . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Project Cost Allocation']);
        fputcsv($output, ['Period', $allocation['period_label']]);
        fputcsv($output, []);
        fputcsv($output, ['Project', 'Wage Cost (cents)', 'Employer Cost (cents)', 'Total Cost (cents)']);
        
        foreach ($projectData as $proj) {
            fputcsv($output, [
                $proj['name'],
                $proj['wage_cost'],
                $proj['employer_cost'],
                $proj['total_cost']
            ]);
        }
        
        fputcsv($output, ['TOTAL', $allocation['total_wage'], $allocation['total_employer'], $allocation['grand_total']]);
        fclose($output);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'allocation' => $allocation
    ]);

} catch (Exception $e) {
    error_log("Cost allocation error: " . $e->getMessage());
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Report failed']);
    } else {
        die('Report failed');
    }
}