<?php
// CSV Accounts Payable Aging Report
//
// Outputs a CSV showing outstanding supplier bills grouped by aging buckets
// (current, 1‑30, 31‑60, 61‑90, 90+ days past due) for the current company.
// Outstanding amounts are calculated as total minus payments and vendor credit
// allocations. Only bills not marked as paid or cancelled are considered.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ap_aging.csv"');

// Query each bill with its payments and credit allocations
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
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

$asOf = new DateTimeImmutable('today');
$suppliers = [];
foreach ($bills as $row) {
    $sid   = (int)$row['supplier_id'];
    $due   = $row['due_date'] ? new DateTimeImmutable($row['due_date']) : null;
    // Calculate outstanding amount
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

// Fetch supplier names
$names = [];
if ($suppliers) {
    $ids = array_keys($suppliers);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Sort by total descending
uasort($suppliers, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

$out = fopen('php://output', 'w');
fputcsv($out, ['Supplier', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total']);
foreach ($suppliers as $sid => $vals) {
    $suppName = $names[$sid] ?? '';
    fputcsv($out, [
        $suppName,
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