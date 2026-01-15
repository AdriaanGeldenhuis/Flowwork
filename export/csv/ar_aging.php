<?php
// CSV Accounts Receivable Aging Report
//
// Generates a CSV report showing outstanding invoice balances grouped by
// aging buckets for the current company. Requires authentication and uses
// the current date as the aging cut‑off. Buckets: current (not yet due),
// 1‑30 days past due, 31‑60 days past due, 61‑90 days past due and 90+ days.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ar_aging.csv"');

// Pull open invoices with a positive balance
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
    // Determine bucket by comparing due date to today
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

// Write CSV
$out = fopen('php://output', 'w');
fputcsv($out, ['Customer', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total']);
foreach ($buckets as $cid => $vals) {
    $custName = $names[$cid] ?? '';
    fputcsv($out, [
        $custName,
        number_format($vals['current'], 2, '.', ''),
        number_format($vals['days_1_30'], 2, '.', ''),
        number_format($vals['days_31_60'], 2, '.', ''),
        number_format($vals['days_61_90'], 2, '.', ''),
        number_format($vals['days_90_plus'], 2, '.', ''),
        number_format($vals['total'], 2, '.', '')
    ]);
}
fclose($out);
exit;