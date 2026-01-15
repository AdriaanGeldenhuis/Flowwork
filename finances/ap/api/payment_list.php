<?php

require_once __DIR__ . '/../../lib/http.php';
require_method('GET');
// /finances/ap/api/payment_list.php
// Returns a list of supplier payments for the current company. Allows
// optional supplier filter via GET. Responds with JSON.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];

$supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

try {
    $query = "SELECT p.id, p.supplier_id, p.amount, p.payment_date, p.method, p.reference, p.journal_id,\n"
           . "c.name AS supplier_name\n"
           . "FROM ap_payments p\n"
           . "LEFT JOIN crm_accounts c ON p.supplier_id = c.id\n"
           . "WHERE p.company_id = ?";
    $params = [$companyId];
    if ($supplierId) {
        $query .= " AND p.supplier_id = ?";
        $params[] = $supplierId;
    }
    $query .= " ORDER BY p.payment_date DESC LIMIT 200";
    $stmt = $DB->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $payments]);
} catch (Exception $e) {
    error_log('AP payment list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load payments']);
}