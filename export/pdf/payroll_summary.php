<?php
// PDF Payroll Summary Report
//
// Renders a simple PDF listing payroll runs with counts of employees,
// aggregated gross pay, PAYE, UIF, SDL, net pay and status. Useful for
// management to review payroll history.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

// Fetch payroll runs with aggregates
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

$lines = [];
$lines[] = 'Payroll Summary Report';
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
        number_format((float)$run['gross_pay'], 2, '.', ''),
        number_format((float)$run['paye'], 2, '.', ''),
        number_format((float)$run['uif'], 2, '.', ''),
        number_format((float)$run['sdl'], 2, '.', ''),
        number_format((float)$run['net_pay'], 2, '.', '')
    );
}

function pdf_escape($str)
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
}

function generate_simple_pdf_content(array $lines): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $offsets[] = strlen($pdf);
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>\nendobj\n";
    // Content
    $yPos = 812;
    $content = "BT\n/F1 12 Tf\n";
    foreach ($lines as $line) {
        $content .= sprintf("36 %.2f Td (%s) Tj\n", $yPos, pdf_escape($line));
        $yPos -= 14;
    }
    $content .= "ET";
    $offsets[] = strlen($pdf);
    $pdf .= "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n";
    $pdf .= $content . "\nendstream\nendobj\n";
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}

$pdfContent = generate_simple_pdf_content($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="payroll_summary.pdf"');
echo $pdfContent;
exit;