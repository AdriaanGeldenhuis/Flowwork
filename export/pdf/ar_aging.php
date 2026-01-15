<?php
// PDF Accounts Receivable Aging Report
//
// Generates a simple PDF report listing outstanding invoice balances per customer
// grouped into aging buckets. This script computes the same data as the CSV
// version but renders it into a plain text PDF. A minimal PDF generator is
// implemented inline to avoid external dependencies.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

// Compute aging buckets
$stmt = $DB->prepare("SELECT id, customer_id, due_date, balance_due FROM invoices WHERE company_id = ? AND status != 'paid' AND balance_due > 0");
$stmt->execute([$companyId]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$asOf   = new DateTimeImmutable('today');
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
$lines[] = 'Accounts Receivable Aging Report';
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

// Helper to escape PDF text
function pdf_escape($str)
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
}

// Generate a minimal PDF document with the given lines of text. Each line
// is rendered at a fixed vertical offset using the Courier font. This
// generator produces a basic PDF with a single page.
function generate_simple_pdf_content(array $lines): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    // 1: Catalog
    $offsets[] = strlen($pdf);
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    // 2: Pages
    $offsets[] = strlen($pdf);
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    // 3: Page
    $offsets[] = strlen($pdf);
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>\nendobj\n";
    // 4: Font
    $offsets[] = strlen($pdf);
    $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>\nendobj\n";
    // Prepare content stream
    $yPos = 812; // Starting y coordinate (offset from top)
    $content = "BT\n/F1 12 Tf\n";
    foreach ($lines as $line) {
        $content .= sprintf("36 %.2f Td (%s) Tj\n", $yPos, pdf_escape($line));
        $yPos -= 14;
    }
    $content .= "ET";
    // 5: Content stream
    $offsets[] = strlen($pdf);
    $pdf .= "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n";
    $pdf .= $content . "\nendstream\nendobj\n";
    // Cross‑reference table
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    // Trailer
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}

$pdfContent = generate_simple_pdf_content($lines);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="ar_aging.pdf"');
echo $pdfContent;
exit;