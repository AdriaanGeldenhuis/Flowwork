<?php
// PDF Accounts Payable Aging Report
//
// Generates a simple PDF report listing outstanding supplier bills grouped by
// aging buckets. Calculations account for payments and vendor credit
// allocations to determine the remaining balances. Buckets mirror the
// standard AP aging report: current, 1‑30 days, 31‑60 days, 61‑90 days and
// 90+ days past due.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

// Fetch bills and compute outstanding balances
$stmt = $DB->prepare(
    "SELECT b.id, b.supplier_id, b.due_date, b.total,
            COALESCE(paid.total_paid, 0) AS total_paid,
            COALESCE(vc.total_credit, 0) AS total_credit
       FROM ap_bills b
  LEFT JOIN (
            SELECT pa.bill_id, SUM(pa.amount) AS total_paid
              FROM ap_payment_allocations pa
              JOIN ap_payments p ON p.id = pa.ap_payment_id AND p.company_id = ?
             GROUP BY pa.bill_id
           ) paid ON paid.bill_id = b.id
  LEFT JOIN (
            SELECT vca.bill_id, SUM(vca.amount) AS total_credit
              FROM vendor_credit_allocations vca
              JOIN vendor_credits vc ON vc.id = vca.credit_id AND vc.company_id = ?
             GROUP BY vca.bill_id
           ) vc ON vc.bill_id = b.id
      WHERE b.company_id = ? AND b.status NOT IN ('paid','cancelled')"
);
$stmt->execute([$companyId, $companyId, $companyId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$asOf     = new DateTimeImmutable('today');
$suppliers = [];
foreach ($rows as $row) {
    $sid = (int)$row['supplier_id'];
    $due = $row['due_date'] ? new DateTimeImmutable($row['due_date']) : null;
    $balance = (float)$row['total'] - (float)$row['total_paid'] - (float)$row['total_credit'];
    if ($balance <= 0) {
        continue;
    }
    if (!isset($suppliers[$sid])) {
        $suppliers[$sid] = [
            'current'      => 0.0,
            'days_1_30'    => 0.0,
            'days_31_60'   => 0.0,
            'days_61_90'   => 0.0,
            'days_90_plus' => 0.0,
            'total'        => 0.0
        ];
    }
    $bucket = 'current';
    if ($due) {
        $diff = $asOf->diff($due)->format('%R%a');
        $days = (int)$diff;
        if ($days < 0) {
            $past = abs($days);
            if ($past <= 30) {
                $bucket = 'days_1_30';
            } elseif ($past <= 60) {
                $bucket = 'days_31_60';
            } elseif ($past <= 90) {
                $bucket = 'days_61_90';
            } else {
                $bucket = 'days_90_plus';
            }
        }
    }
    $suppliers[$sid][$bucket] += $balance;
    $suppliers[$sid]['total']  += $balance;
}

// Get supplier names
$names = [];
if ($suppliers) {
    $ids = array_keys($suppliers);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Sort by total
uasort($suppliers, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

// Build lines for PDF
$lines = [];
$lines[] = 'Accounts Payable Aging Report';
$lines[] = 'As of ' . $asOf->format('Y-m-d');
$lines[] = '';
$lines[] = sprintf("%-30s %10s %10s %10s %10s %10s %12s", 'Supplier', 'Current', '1-30', '31-60', '61-90', '90+', 'Total');
$totals = [
    'current'      => 0.0,
    'days_1_30'    => 0.0,
    'days_31_60'   => 0.0,
    'days_61_90'   => 0.0,
    'days_90_plus' => 0.0,
    'total'        => 0.0
];

foreach ($suppliers as $sid => $vals) {
    $name = $names[$sid] ?? '';
    $lines[] = sprintf(
        "%-30s %10s %10s %10s %10s %10s %12s",
        mb_strimwidth($name, 0, 30, '…'),
        number_format($vals['current'], 2, '.', ''),
        number_format($vals['days_1_30'], 2, '.', ''),
        number_format($vals['days_31_60'], 2, '.', ''),
        number_format($vals['days_61_90'], 2, '.', ''),
        number_format($vals['days_90_plus'], 2, '.', ''),
        number_format($vals['total'], 2, '.', '')
    );
    foreach ($totals as $k => $t) {
        $totals[$k] += $vals[$k];
    }
}
$lines[] = str_repeat('-', 104);
$lines[] = sprintf(
    "%-30s %10s %10s %10s %10s %10s %12s",
    'TOTAL',
    number_format($totals['current'], 2, '.', ''),
    number_format($totals['days_1_30'], 2, '.', ''),
    number_format($totals['days_31_60'], 2, '.', ''),
    number_format($totals['days_61_90'], 2, '.', ''),
    number_format($totals['days_90_plus'], 2, '.', ''),
    number_format($totals['total'], 2, '.', '')
);

// Escape PDF text
function pdf_escape($str)
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
}

// Minimal PDF generator reused from AR aging
function generate_simple_pdf_content(array $lines): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    // Catalog
    $offsets[] = strlen($pdf);
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    // Pages
    $offsets[] = strlen($pdf);
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    // Page
    $offsets[] = strlen($pdf);
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>\nendobj\n";
    // Font
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
    // xref
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
header('Content-Disposition: attachment; filename="ap_aging.pdf"');
echo $pdfContent;
exit;