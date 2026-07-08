<?php

require_once __DIR__ . '/../../lib/http.php';
require_method('GET');
// /finances/ap/api/bill_list.php
// Returns a list of AP bills for the current company. Supports
// optional status filtering via GET parameter. Responds with JSON.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin','bookkeeper','viewer']);

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
                COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = b.id), 0) AS paid,
                COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = b.id), 0) AS credited,
                (b.total
                    - COALESCE((SELECT SUM(amount) FROM ap_payment_allocations WHERE bill_id = b.id), 0)
                    - COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE bill_id = b.id), 0)
                ) AS balance
            FROM ap_bills b
            LEFT JOIN crm_accounts c ON b.supplier_id = c.id
            WHERE b.company_id = ?";
    $params = [$companyId];
    if ($status !== '') {
        $query .= " AND b.status = ?";
        $params[] = $status;
    }
    $query .= " ORDER BY b.issue_date DESC
                LIMIT 200";
    $stmt = $DB->prepare($query);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $bills]);
} catch (Exception $e) {
    error_log('AP bill list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load bills']);
}