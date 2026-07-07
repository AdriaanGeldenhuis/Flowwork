<?php

require_once __DIR__ . '/../../lib/http.php';
require_method('GET');
// /finances/ap/api/ap_statement.php – Generate a supplier statement for a date range
// Returns JSON with opening balance and transaction lines (debits increase
// payable, credits decrease payable).

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;

$supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$startDate  = $_GET['start_date'] ?? '';
$endDate    = $_GET['end_date'] ?? '';

if (!$companyId || !$supplierId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate date formats
if ($startDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid start date']);
    exit;
}
if ($endDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid end date']);
    exit;
}

try {
    // Opening balance (bills - payments - vendor credits) before start
    $openingDebit  = 0.0; // bills
    $openingCredit = 0.0; // payments + vendor credits
    if ($startDate) {
        // Sum of bills. Only GL-posted payables count ('posted'/'paid');
        // draft/review/approved were never posted and cancelled/blocked are
        // excluded (rule matches ap_aging.php and Tieout::apSubledger intent).
        $stmt = $DB->prepare(
            "SELECT SUM(total) FROM ap_bills WHERE company_id = ? AND supplier_id = ? AND issue_date < ? AND status IN ('posted','paid')"
        );
        $stmt->execute([$companyId, $supplierId, $startDate]);
        $openingDebit = floatval($stmt->fetchColumn());
        // Sum of payments
        $stmt = $DB->prepare(
            "SELECT SUM(apa.amount) FROM ap_payment_allocations apa
             JOIN ap_payments p ON apa.ap_payment_id = p.id
             WHERE p.company_id = ? AND p.supplier_id = ? AND p.payment_date < ?"
        );
        $stmt->execute([$companyId, $supplierId, $startDate]);
        $openingCredit += floatval($stmt->fetchColumn());
        // Sum of vendor credits: only the ALLOCATED portion reduces the
        // supplier statement (mirrors bill balance = total - payments -
        // allocated credits), so unapplied remainders don't distort it.
        $stmt = $DB->prepare(
            "SELECT SUM(vca.amount) FROM vendor_credit_allocations vca
             JOIN vendor_credits vc ON vc.id = vca.credit_id
             WHERE vc.company_id = ? AND vc.supplier_id = ? AND vc.issue_date < ? AND vc.status != 'cancelled'"
        );
        $stmt->execute([$companyId, $supplierId, $startDate]);
        $openingCredit += floatval($stmt->fetchColumn());
    }
    $openingBalance = $openingDebit - $openingCredit;
    // Build date filter
    $params = [];
    $dateSql = '';
    if ($startDate) {
        $dateSql .= " AND t_date >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $dateSql .= " AND t_date <= ?";
        $params[] = $endDate;
    }
    // Build union of bills, payments, vendor credits.
    // Bills: only GL-posted payables ('posted'/'paid') appear on the
    // statement — same status rule as the opening balance and ap_aging.php.
    // Vendor credits: only the ALLOCATED portion of each credit is shown so
    // the closing balance equals bills - payments - allocated credits.
    $sql = "
        SELECT t_date, t_type, ref, description, debit, credit FROM (
            -- Bills (increase payable)
            SELECT b.issue_date AS t_date,
                   'Bill' AS t_type,
                   b.vendor_invoice_number AS ref,
                   CONCAT('Bill ', b.vendor_invoice_number) AS description,
                   b.total AS debit,
                   0 AS credit
            FROM ap_bills b
            WHERE b.company_id = ? AND b.supplier_id = ? AND b.status IN ('posted','paid')
            UNION ALL
            -- Payments (reduce payable)
            SELECT p.payment_date AS t_date,
                   'Payment' AS t_type,
                   COALESCE(p.reference, CONCAT('PAY', p.id)) AS ref,
                   'Payment' AS description,
                   0 AS debit,
                   apa.amount AS credit
            FROM ap_payment_allocations apa
            JOIN ap_payments p ON apa.ap_payment_id = p.id
            WHERE p.company_id = ? AND p.supplier_id = ?
            UNION ALL
            -- Vendor credits (reduce payable by their allocated amount only)
            SELECT vc.issue_date AS t_date,
                   'Vendor Credit' AS t_type,
                   vc.credit_number AS ref,
                   'Vendor Credit' AS description,
                   0 AS debit,
                   SUM(vca.amount) AS credit
            FROM vendor_credits vc
            JOIN vendor_credit_allocations vca ON vca.credit_id = vc.id
            WHERE vc.company_id = ? AND vc.supplier_id = ? AND vc.status != 'cancelled'
            GROUP BY vc.id, vc.issue_date, vc.credit_number
        ) AS all_txn
        WHERE 1=1 " . $dateSql . "
        ORDER BY t_date, ref";
    // Prepare parameters: duplicates for union
    $unionParams = [$companyId, $supplierId, $companyId, $supplierId, $companyId, $supplierId];
    $stmt = $DB->prepare($sql);
    $stmt->execute(array_merge($unionParams, $params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Compute running balance
    $balance = $openingBalance;
    $lines   = [];
    foreach ($rows as $r) {
        $debit  = floatval($r['debit']);
        $credit = floatval($r['credit']);
        if ($debit > 0) {
            $balance += $debit;
        } elseif ($credit > 0) {
            $balance -= $credit;
        }
        $lines[] = [
            'date'        => $r['t_date'],
            'type'        => $r['t_type'],
            'reference'   => $r['ref'],
            'description' => $r['description'],
            'debit'       => $debit,
            'credit'      => $credit,
            'balance'     => $balance
        ];
    }
    echo json_encode([
        'ok' => true,
        'opening_balance' => $openingBalance,
        'data' => $lines
    ]);
} catch (Exception $e) {
    error_log('AP statement error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}