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
    // As-at balances: invoice total minus payments/credit notes allocated on
    // or before the as-of date (payments dated by payments.payment_date,
    // credit note allocations by the credit note's issue_date — the allocation
    // tables carry no date of their own). Invoices issued after the as-of
    // date are excluded, real issued documents only (no drafts, cancellations,
    // write-offs or soft deletes), and amounts are converted to ZAR at the
    // invoice's captured exchange rate. Currently-paid invoices still appear
    // when their settling payment falls after the as-of date.
    $stmt = $DB->prepare("
        SELECT i.id, i.customer_id, i.due_date,
               (i.total - COALESCE(pay.paid_tot, 0) - COALESCE(cred.cred_tot, 0)
                  - CASE WHEN i.write_off_at IS NOT NULL AND DATE(i.write_off_at) <= ?
                         THEN COALESCE(i.write_off_amount, 0) ELSE 0 END
               ) * COALESCE(NULLIF(i.exchange_rate,0),1) AS balance_zar
        FROM invoices i
        LEFT JOIN (
            SELECT pa.invoice_id, SUM(pa.amount) AS paid_tot
            FROM payment_allocations pa
            JOIN payments p ON p.id = pa.payment_id
            WHERE p.company_id = ? AND p.payment_date <= ?
            GROUP BY pa.invoice_id
        ) pay ON pay.invoice_id = i.id
        LEFT JOIN (
            SELECT cna.invoice_id, SUM(cna.amount) AS cred_tot
            FROM credit_note_allocations cna
            JOIN credit_notes cn ON cn.id = cna.credit_note_id
            WHERE cna.company_id = ? AND cn.issue_date <= ?
              AND cn.status NOT IN ('draft','cancelled') AND cn.journal_id IS NOT NULL
            GROUP BY cna.invoice_id
        ) cred ON cred.invoice_id = i.id
        WHERE i.company_id = ?
          AND i.deleted_at IS NULL
          AND i.status NOT IN ('draft','cancelled','written_off','uncollectible','refunded')
          AND i.issue_date <= ?
    ");
    // First ? is the write-off as-at gate in the SELECT; the rest follow the
    // subqueries and the outer WHERE in order.
    $stmt->execute([$asOf, $companyId, $asOf, $companyId, $asOf, $companyId, $asOf]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $customers = [];
    foreach ($rows as $inv) {
        $balance = round((float)$inv['balance_zar'], 2);
        if ($balance <= 0.005) {
            continue; // settled on or before the as-of date
        }
        $custId  = (int)$inv['customer_id'];
        $dueDate = $inv['due_date'] ? new DateTime($inv['due_date']) : null;
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
        $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE id IN ($placeholders) AND company_id = ?");
        $stmt->execute(array_merge($ids, [$companyId]));
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