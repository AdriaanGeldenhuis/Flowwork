<?php
// CSV Payroll Summary Report
//
// Provides a CSV summary of each payroll run including number of
// employees, gross pay, PAYE, UIF, SDL, net pay and status. Useful for
// management review of payroll history.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
requireRoles(['admin', 'bookkeeper']); // payroll detail: finance staff only, not viewers

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
            COALESCE(SUM(pre.gross_cents),0) AS gross_cents,
            COALESCE(SUM(pre.paye_cents),0) AS paye_cents,
            COALESCE(SUM(pre.uif_employee_cents),0) AS uif_cents,
            COALESCE(SUM(pre.sdl_cents),0) AS sdl_cents,
            COALESCE(SUM(pre.net_cents),0) AS net_cents
       FROM pay_runs pr
  LEFT JOIN pay_run_employees pre ON pre.run_id = pr.id
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
        number_format((float)$run['gross_cents'] / 100, 2, '.', ''),
        number_format((float)$run['paye_cents'] / 100, 2, '.', ''),
        number_format((float)$run['uif_cents'] / 100, 2, '.', ''),
        number_format((float)$run['sdl_cents'] / 100, 2, '.', ''),
        number_format((float)$run['net_cents'] / 100, 2, '.', ''),
        $run['status']
    ]);
}
fclose($out);
exit;