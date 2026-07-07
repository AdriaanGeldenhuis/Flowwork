<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_ap_aging.php
// Generate an accounts payable aging report for a given date.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/report_helpers.php';

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$asOf      = $_GET['date'] ?? date('Y-m-d');

// Validate date format
$dt = DateTime::createFromFormat('Y-m-d', $asOf);
if (!$dt || $dt->format('Y-m-d') !== $asOf) {
    $asOf = date('Y-m-d');
}

try {
    $asOfDate = new DateTime($asOf);
    // As-at balance per bill: total minus payments made on or before the
    // as-of date (ap_payments.payment_date) minus vendor credits issued on or
    // before it (vendor_credits.issue_date — the allocation tables carry no
    // date of their own). Bills issued after the as-of date are excluded, as
    // are never-posted/cancelled statuses (draft/review are pre-posting,
    // cancelled/void/blocked never owe anything). Currently-settled bills
    // still appear when their settling payment falls after the as-of date.
    $sql = "SELECT b.id, b.supplier_id, b.due_date,
                   (b.total - IFNULL(pa.paid_tot,0) - IFNULL(vc.cred_tot,0)) AS balance
            FROM ap_bills b
            LEFT JOIN (
                SELECT apa.bill_id, SUM(apa.amount) AS paid_tot
                FROM ap_payment_allocations apa
                JOIN ap_payments p ON p.id = apa.ap_payment_id
                WHERE p.company_id = ? AND p.payment_date <= ?
                GROUP BY apa.bill_id
            ) pa ON pa.bill_id = b.id
            LEFT JOIN (
                SELECT vca.bill_id, SUM(vca.amount) AS cred_tot
                FROM vendor_credit_allocations vca
                JOIN vendor_credits vcn ON vcn.id = vca.credit_id
                WHERE vcn.company_id = ? AND vcn.issue_date <= ?
                GROUP BY vca.bill_id
            ) vc ON vc.bill_id = b.id
            WHERE b.company_id = ?
              AND b.status IN ('posted','paid') AND b.journal_id IS NOT NULL
              AND b.issue_date <= ?";
    $stmt = $DB->prepare($sql);
    $stmt->execute([$companyId, $asOf, $companyId, $asOf, $companyId, $asOf]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $suppliers = [];
    foreach ($rows as $row) {
        $balance = round((float)$row['balance'], 2);
        if ($balance <= 0.005) continue; // settled on or before the as-of date
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
        $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id IN ($placeholders) AND company_id = ?");
        $stmt->execute(array_merge($ids, [$companyId]));
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
    $meta = getReportMeta($DB, $companyId, $userId);
    echo json_encode(['ok' => true, 'data' => $data, 'report_meta' => $meta]);
} catch (Exception $e) {
    error_log('AP aging report error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to generate aging report']);
}