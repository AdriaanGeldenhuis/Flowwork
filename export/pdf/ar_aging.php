<?php
// PDF Accounts Receivable Aging Report
//
// Generates a simple PDF report listing outstanding invoice balances per customer
// grouped into aging buckets. This script computes the same data as the CSV
// version but renders it into a plain text PDF via the dependency-free
// generator in includes/pdf/text_report_pdf.php.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']); // gate this finance export
require_once __DIR__ . '/../../includes/pdf/text_report_pdf.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

// Fetch company info for header
$stmtC = $DB->prepare("SELECT name, reg_number, vat_number FROM companies WHERE id = ?");
$stmtC->execute([$companyId]);
$company = $stmtC->fetch(PDO::FETCH_ASSOC);

$asOfStr = $_GET['date'] ?? date('Y-m-d');

// Compute aging buckets
$stmt = $DB->prepare("SELECT id, customer_id, due_date, balance_due FROM invoices WHERE company_id = ? AND status != 'paid' AND balance_due > 0");
$stmt->execute([$companyId]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$asOf = new DateTimeImmutable($asOfStr);
$buckets = [];
foreach ($invoices as $inv) {
    $cid  = (int)$inv['customer_id'];
    $due  = $inv['due_date'] ? new DateTimeImmutable($inv['due_date']) : null;
    $bal  = (float)$inv['balance_due'];
    if (!isset($buckets[$cid])) {
        $buckets[$cid] = [
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
    $buckets[$cid][$bucket] += $bal;
    $buckets[$cid]['total'] += $bal;
}

// Fetch customer names
$names = [];
if ($buckets) {
    $ids = array_keys($buckets);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Sort by total descending
uasort($buckets, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

// Build lines for PDF
$lines = [];
$lines[] = ($company['name'] ?? '') . ' - Accounts Receivable Aging Report';
if (!empty($company['reg_number'])) $lines[] = 'Reg No: ' . $company['reg_number'];
if (!empty($company['vat_number'])) $lines[] = 'VAT No: ' . $company['vat_number'];
$lines[] = 'As of ' . $asOf->format('Y-m-d');
$lines[] = '';
$lines[] = sprintf("%-30s %10s %10s %10s %10s %10s %12s", 'Customer', 'Current', '1-30', '31-60', '61-90', '90+', 'Total');
$totalBuckets = [
    'current'      => 0.0,
    'days_1_30'    => 0.0,
    'days_31_60'   => 0.0,
    'days_61_90'   => 0.0,
    'days_90_plus' => 0.0,
    'total'        => 0.0
];
foreach ($buckets as $cid => $vals) {
    $name = $names[$cid] ?? '';
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
    foreach ($totalBuckets as $key => $t) {
        $totalBuckets[$key] += $vals[$key];
    }
}
$lines[] = str_repeat('-', 104);
$lines[] = sprintf(
    "%-30s %10s %10s %10s %10s %10s %12s",
    'TOTAL',
    number_format($totalBuckets['current'], 2, '.', ''),
    number_format($totalBuckets['days_1_30'], 2, '.', ''),
    number_format($totalBuckets['days_31_60'], 2, '.', ''),
    number_format($totalBuckets['days_61_90'], 2, '.', ''),
    number_format($totalBuckets['days_90_plus'], 2, '.', ''),
    number_format($totalBuckets['total'], 2, '.', '')
);

$pdfContent = fw_text_report_pdf($lines);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="ar_aging.pdf"');
echo $pdfContent;
exit;