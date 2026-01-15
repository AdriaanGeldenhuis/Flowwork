<?php

require_once __DIR__ . '/../../lib/http.php';
require_method('GET');
// /finances/ap/api/bill_list.php
// Returns a list of AP bills for the current company. Supports
// optional status filtering via GET parameter. Responds with JSON.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];

$status = isset($_GET['status']) ? trim($_GET['status']) : '';

try {
    // Query bills with aggregated paid and credited amounts to compute balance
    $query = "SELECT 
                b.id,
                b.vendor_invoice_number,
                b.supplier_id,
                b.issue_date,
                b.due_date,
                b.status,
                b.total,
                b.journal_id,
                c.name AS supplier_name,
                COALESCE(SUM(pa.amount),0) AS paid,
                COALESCE(SUM(vca.amount),0) AS credited,
                (b.total - COALESCE(SUM(pa.amount),0) - COALESCE(SUM(vca.amount),0)) AS balance
            FROM ap_bills b
            LEFT JOIN crm_accounts c ON b.supplier_id = c.id
            LEFT JOIN ap_payment_allocations pa ON pa.bill_id = b.id
            LEFT JOIN vendor_credit_allocations vca ON vca.bill_id = b.id
            WHERE b.company_id = ?";
    $params = [$companyId];
    if ($status !== '') {
        $query .= " AND b.status = ?";
        $params[] = $status;
    }
    $query .= " GROUP BY b.id
                ORDER BY b.issue_date DESC
                LIMIT 200";
    $stmt = $DB->prepare($query);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $bills]);
} catch (Exception $e) {
    error_log('AP bill list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load bills']);
}