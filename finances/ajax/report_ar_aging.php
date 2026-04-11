<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_ar_aging.php
// Generate an accounts receivable aging report for a given date.

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
    // Fetch open invoices with balance due
    $stmt = $DB->prepare("SELECT id, customer_id, due_date, balance_due FROM invoices WHERE company_id = ? AND status != 'paid' AND balance_due > 0");
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $customers = [];
    foreach ($rows as $inv) {
        $custId  = (int)$inv['customer_id'];
        $dueDate = $inv['due_date'] ? new DateTime($inv['due_date']) : null;
        $balance = (float)$inv['balance_due'];
        // Initialise customer bucket
        if (!isset($customers[$custId])) {
            $customers[$custId] = [
                'customer_id'   => $custId,
                'customer_name' => '',
                'current'       => 0.0,
                'days_1_30'     => 0.0,
                'days_31_60'    => 0.0,
                'days_61_90'    => 0.0,
                'days_90_plus'  => 0.0,
                'total'         => 0.0
            ];
        }
        // Determine aging bucket
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
        $customers[$custId][$bucket] += $balance;
        $customers[$custId]['total']  += $balance;
    }

    // Fetch customer names
    if ($customers) {
        $ids = array_keys($customers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($customers as $cid => &$row) {
            $row['customer_name'] = $names[$cid] ?? '';
        }
    }
    // Sort by total descending
    $data = array_values($customers);
    usort($data, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    $meta = getReportMeta($DB, $companyId, $userId);
    echo json_encode(['ok' => true, 'data' => $data, 'report_meta' => $meta]);
} catch (Exception $e) {
    error_log('AR aging report error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to generate aging report']);
}