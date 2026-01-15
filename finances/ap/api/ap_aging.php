<?php
// /finances/ap/api/ap_aging.php
// Generates an accounts payable aging report grouped by supplier.
// Returns JSON with outstanding balances across age buckets.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'No company selected']);
    exit;
}

try {
    // Build subquery to compute remaining balance per bill
    // We exclude bills with no outstanding balance or cancelled status
    $sql = "SELECT 
                b.id,
                b.supplier_id,
                b.due_date,
                (b.total - IFNULL(paid_tot,0) - IFNULL(cred_tot,0)) AS balance
            FROM ap_bills b
            LEFT JOIN (
                SELECT bill_id, SUM(amount) AS paid_tot
                FROM ap_payment_allocations
                GROUP BY bill_id
            ) pa ON pa.bill_id = b.id
            LEFT JOIN (
                SELECT bill_id, SUM(amount) AS cred_tot
                FROM vendor_credit_allocations
                GROUP BY bill_id
            ) vc ON vc.bill_id = b.id
            WHERE b.company_id = ? AND b.status != 'cancelled'
            HAVING balance > 0";
    $stmt = $DB->prepare($sql);
    $stmt->execute([$companyId]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Aggregate by supplier into buckets
    $suppliers = [];
    $today = new DateTime();
    foreach ($bills as $b) {
        $supId = (int)$b['supplier_id'];
        $dueDate = $b['due_date'] ?: null;
        $balance = floatval($b['balance']);
        if (!isset($suppliers[$supId])) {
            $suppliers[$supId] = [
                'supplier_id' => $supId,
                'supplier_name' => '',
                'current' => 0.0,
                'days_1_30' => 0.0,
                'days_31_60' => 0.0,
                'days_61_90' => 0.0,
                'days_90_plus' => 0.0,
                'total' => 0.0
            ];
        }
        // Determine bucket
        $bucket = 'current';
        if ($dueDate) {
            $dd = new DateTime($dueDate);
            $diff = (int)$today->diff($dd)->format('%R%a');
            // diff positive means due date is in future (current), negative means past due
            if ($diff < 0) {
                $daysPast = abs($diff);
                if ($daysPast >= 1 && $daysPast <= 30) $bucket = 'days_1_30';
                elseif ($daysPast <= 60) $bucket = 'days_31_60';
                elseif ($daysPast <= 90) $bucket = 'days_61_90';
                else $bucket = 'days_90_plus';
            }
        }
        $suppliers[$supId][$bucket] += $balance;
        $suppliers[$supId]['total'] += $balance;
    }
    // Fetch supplier names
    if ($suppliers) {
        $ids = array_keys($suppliers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($suppliers as $sid => &$row) {
            $row['supplier_name'] = $names[$sid] ?? '';
        }
    }
    // Convert to array
    $result = array_values($suppliers);
    // Sort by total desc
    usort($result, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    echo json_encode(['ok' => true, 'data' => $result]);
} catch (Exception $e) {
    error_log('AP aging error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to generate aging report']);
}