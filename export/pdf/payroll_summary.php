<?php
// PDF Payroll Summary Report
//
// Renders a simple PDF listing payroll runs with counts of employees,
// aggregated gross pay, PAYE, UIF, SDL, net pay and status. Useful for
// management to review payroll history.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
requireRoles(['admin', 'bookkeeper']); // payroll detail: finance staff only, not viewers
require_once __DIR__ . '/../../includes/pdf/text_report_pdf.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

// Fetch payroll runs with aggregates
// Fetch company info for header
$stmtC = $DB->prepare("SELECT name, reg_number, vat_number FROM companies WHERE id = ?");
$stmtC->execute([$companyId]);
$company = $stmtC->fetch(PDO::FETCH_ASSOC);

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

$lines = [];
$lines[] = ($company['name'] ?? '') . ' - Payroll Summary Report';
if (!empty($company['reg_number'])) $lines[] = 'Reg No: ' . $company['reg_number'];
if (!empty($company['vat_number'])) $lines[] = 'VAT No: ' . $company['vat_number'];
$lines[] = 'As of ' . (new DateTimeImmutable('today'))->format('Y-m-d');
$lines[] = '';
$lines[] = sprintf("%-8s %-12s %-21s %10s %12s %10s %10s %10s %8s", 'Run #', 'Pay Date', 'Period', 'Emp', 'Gross', 'PAYE', 'UIF', 'SDL', 'Net');

foreach ($runs as $run) {
    $period = '';
    if ($run['period_start'] && $run['period_end']) {
        $period = $run['period_start'] . ' to ' . $run['period_end'];
    }
    $lines[] = sprintf(
        "%-8s %-12s %-21s %10d %12s %10s %10s %10s %8s",
        $run['id'],
        $run['pay_date'],
        mb_strimwidth($period, 0, 21, '…'),
        (int)$run['employees'],
        number_format((float)$run['gross_cents'] / 100, 2, '.', ''),
        number_format((float)$run['paye_cents'] / 100, 2, '.', ''),
        number_format((float)$run['uif_cents'] / 100, 2, '.', ''),
        number_format((float)$run['sdl_cents'] / 100, 2, '.', ''),
        number_format((float)$run['net_cents'] / 100, 2, '.', '')
    );
}

$pdfContent = fw_text_report_pdf($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="payroll_summary.pdf"');
echo $pdfContent;
exit;