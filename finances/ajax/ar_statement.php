<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/ar_statement.php – Generate a customer statement for a date range
// Returns JSON with opening balance and transaction lines (debits/credits).

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;

// Capture query parameters
$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$startDate  = $_GET['start_date'] ?? '';
$endDate    = $_GET['end_date'] ?? '';

if (!$companyId || !$customerId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Validate dates (optional)
if ($startDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid start date']);
    exit;
}
if ($endDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid end date']);
    exit;
}

try {
    // Opening balance prior to start date
    $openDebit = 0.0;
    $openCredit = 0.0;
    if ($startDate) {
        // Sum of invoice totals before start date
        $stmt = $DB->prepare(
            "SELECT SUM(total) FROM invoices WHERE company_id = ? AND customer_id = ? AND issue_date < ?"
        );
        $stmt->execute([$companyId, $customerId, $startDate]);
        $openDebit = floatval($stmt->fetchColumn());
        // Sum of payments applied to this customer's invoices before start date
        $stmt = $DB->prepare(
            "SELECT SUM(pa.amount) FROM payment_allocations pa
             JOIN invoices i ON pa.invoice_id = i.id
             JOIN payments p ON pa.payment_id = p.id
             WHERE i.company_id = ? AND i.customer_id = ? AND p.payment_date < ?"
        );
        $stmt->execute([$companyId, $customerId, $startDate]);
        $openCredit += floatval($stmt->fetchColumn());
        // Sum of credit notes totals for this customer before start date
        $stmt = $DB->prepare(
            "SELECT SUM(total) FROM credit_notes WHERE company_id = ? AND customer_id = ? AND issue_date < ? AND status != 'cancelled'"
        );
        $stmt->execute([$companyId, $customerId, $startDate]);
        $openCredit += floatval($stmt->fetchColumn());
    }
    $openingBalance = $openDebit - $openCredit;

    // Fetch transactions within the date range
    $params = [$companyId, $customerId];
    $dateFilterSql = '';
    if ($startDate) {
        $dateFilterSql .= " AND t_date >= ?";
        $params[] = $startDate;
    }
    if ($endDate) {
        $dateFilterSql .= " AND t_date <= ?";
        $params[] = $endDate;
    }

    // Build union of invoices, payments, credit notes
    $sql = "
        SELECT t_date, t_type, ref, description, debit, credit FROM (
            -- Invoices
            SELECT i.issue_date AS t_date,
                   'Invoice' AS t_type,
                   i.invoice_number AS ref,
                   CONCAT('Invoice ', i.invoice_number) AS description,
                   i.total AS debit,
                   0 AS credit
            FROM invoices i
            WHERE i.company_id = ? AND i.customer_id = ?
            UNION ALL
            -- Payments
            SELECT p.payment_date AS t_date,
                   'Payment' AS t_type,
                   COALESCE(p.reference, CONCAT('PAY', p.id)) AS ref,
                   CONCAT('Payment for ', COALESCE(i.invoice_number, 'Invoice')) AS description,
                   0 AS debit,
                   pa.amount AS credit
            FROM payment_allocations pa
            JOIN payments p ON pa.payment_id = p.id
            JOIN invoices i ON pa.invoice_id = i.id
            WHERE i.company_id = ? AND i.customer_id = ?
            UNION ALL
            -- Credit notes
            SELECT cn.issue_date AS t_date,
                   'Credit Note' AS t_type,
                   cn.credit_note_number AS ref,
                   CONCAT('Credit Note ', cn.credit_note_number) AS description,
                   0 AS debit,
                   cn.total AS credit
            FROM credit_notes cn
            WHERE cn.company_id = ? AND cn.customer_id = ? AND cn.status != 'cancelled'
        ) AS all_txn
        WHERE 1=1 " . $dateFilterSql . "
        ORDER BY t_date, ref
    ";
    // Prepare parameters for union (6 placeholders for company_id/customer_id pairs)
    $unionParams = [$companyId, $customerId, $companyId, $customerId, $companyId, $customerId];
    $stmt = $DB->prepare($sql);
    $stmt->execute(array_merge($unionParams, $params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Compute running balance
    $balance = $openingBalance;
    $lines = [];
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
    error_log('AR statement error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}