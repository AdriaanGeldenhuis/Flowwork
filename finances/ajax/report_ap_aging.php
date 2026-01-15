<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_ap_aging.php
// Generate an accounts payable aging report for a given date.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$asOf      = $_GET['date'] ?? date('Y-m-d');

try {
    $asOfDate = new DateTime($asOf);
    // Compute remaining balance per bill (total minus payments minus credits)
    $sql = "SELECT b.id, b.supplier_id, b.due_date, (b.total - IFNULL(pa.paid_tot,0) - IFNULL(vc.cred_tot,0)) AS balance\n            FROM ap_bills b\n            LEFT JOIN (SELECT bill_id, SUM(amount) AS paid_tot FROM ap_payment_allocations GROUP BY bill_id) pa ON pa.bill_id = b.id\n            LEFT JOIN (SELECT bill_id, SUM(amount) AS cred_tot FROM vendor_credit_allocations GROUP BY bill_id) vc ON vc.bill_id = b.id\n            WHERE b.company_id = ? AND b.status != 'cancelled'";
    $stmt = $DB->prepare($sql);
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $suppliers = [];
    foreach ($rows as $row) {
        $balance = (float)$row['balance'];
        if ($balance <= 0) continue;
        $supId   = (int)$row['supplier_id'];
        $dueDate = $row['due_date'] ? new DateTime($row['due_date']) : null;
        if (!isset($suppliers[$supId])) {
            $suppliers[$supId] = [
                'supplier_id'   => $supId,
                'supplier_name' => '',
                'current'       => 0.0,
                'days_1_30'     => 0.0,
                'days_31_60'    => 0.0,
                'days_61_90'    => 0.0,
                'days_90_plus'  => 0.0,
                'total'         => 0.0
            ];
        }
        $bucket = 'current';
        if ($dueDate) {
            $diff = $asOfDate->diff($dueDate)->format('%R%a');
            $days = (int)$diff;
            if ($days < 0) {
                $past = abs($days);
                if ($past <= 30) $bucket = 'days_1_30';
                elseif ($past <= 60) $bucket = 'days_31_60';
                elseif ($past <= 90) $bucket = 'days_61_90';
                else $bucket = 'days_90_plus';
            }
        }
        $suppliers[$supId][$bucket] += $balance;
        $suppliers[$supId]['total']  += $balance;
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
    // Sort by total desc
    $data = array_values($suppliers);
    usort($data, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    echo json_encode(['ok' => true, 'data' => $data]);
} catch (Exception $e) {
    error_log('AP aging report error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to generate aging report']);
}