<?php

require_once __DIR__ . '/../../lib/http.php';
require_method('GET');
// /finances/ap/api/vendor_credit_list.php
// Returns a list of vendor credits for the current company. Supports
// optional supplier_id and status filters. Responds with JSON.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];

$supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$status     = isset($_GET['status']) ? trim($_GET['status']) : '';

try {
    $query = "SELECT
                vc.id,
                vc.credit_number,
                vc.supplier_id,
                vc.issue_date,
                vc.status,
                vc.subtotal,
                vc.tax,
                vc.total,
                vc.journal_id,
                c.name AS supplier_name,
                COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE credit_id = vc.id), 0) AS allocated,
                (vc.total - COALESCE((SELECT SUM(amount) FROM vendor_credit_allocations WHERE credit_id = vc.id), 0)) AS remaining
            FROM vendor_credits vc
            LEFT JOIN crm_accounts c ON vc.supplier_id = c.id
            WHERE vc.company_id = ?";
    $params = [$companyId];
    if ($supplierId) {
        $query .= " AND vc.supplier_id = ?";
        $params[] = $supplierId;
    }
    if ($status !== '') {
        $query .= " AND vc.status = ?";
        $params[] = $status;
    }
    $query .= " ORDER BY vc.issue_date DESC LIMIT 200";
    $stmt = $DB->prepare($query);
    $stmt->execute($params);
    $credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $credits]);
} catch (Exception $e) {
    error_log('Vendor credit list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load vendor credits']);
}
