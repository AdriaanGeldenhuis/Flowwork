<?php
// CSV Payroll Summary Report
//
// Provides a CSV summary of each payroll run including number of
// employees, gross pay, PAYE, UIF, SDL, net pay and status. Useful for
// management review of payroll history.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payroll_summary.csv"');

// Aggregate pay run data
$stmt = $DB->prepare(
    "SELECT pr.id, pr.pay_date, pr.period_start, pr.period_end, pr.status,
            COUNT(pre.id) AS employees,
            COALESCE(SUM(pre.gross_pay),0) AS gross_pay,
            COALESCE(SUM(pre.paye),0) AS paye,
            COALESCE(SUM(pre.uif),0) AS uif,
            COALESCE(SUM(pre.sdl),0) AS sdl,
            COALESCE(SUM(pre.net_pay),0) AS net_pay
       FROM pay_runs pr
  LEFT JOIN pay_run_employees pre ON pre.pay_run_id = pr.id
      WHERE pr.company_id = ?
   GROUP BY pr.id
   ORDER BY pr.pay_date DESC"
);
$stmt->execute([$companyId]);
$runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out = fopen('php://output', 'w');
fputcsv($out, ['Run #', 'Pay Date', 'Period', 'Employees', 'Gross Pay', 'PAYE', 'UIF', 'SDL', 'Net Pay', 'Status']);
foreach ($runs as $run) {
    $period = '';
    if ($run['period_start'] && $run['period_end']) {
        $period = $run['period_start'] . ' to ' . $run['period_end'];
    }
    fputcsv($out, [
        $run['id'],
        $run['pay_date'],
        $period,
        (int)$run['employees'],
        number_format((float)$run['gross_pay'], 2, '.', ''),
        number_format((float)$run['paye'], 2, '.', ''),
        number_format((float)$run['uif'], 2, '.', ''),
        number_format((float)$run['sdl'], 2, '.', ''),
        number_format((float)$run['net_pay'], 2, '.', ''),
        $run['status']
    ]);
}
fclose($out);
exit;