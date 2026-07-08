<?php

require_once __DIR__ . '/../../lib/http.php';
require_method('GET');
// /finances/ap/api/payment_list.php
// Returns a list of supplier payments for the current company. Allows
// optional supplier filter via GET. Responds with JSON.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin','bookkeeper','viewer']);

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];

$supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

// Pagination (mirrors journal_list.php)
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

try {
    $where  = "WHERE p.company_id = ?";
    $params = [$companyId];
    if ($supplierId) {
        $where .= " AND p.supplier_id = ?";
        $params[] = $supplierId;
    }

    $countStmt = $DB->prepare("SELECT COUNT(*) FROM ap_payments p $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $query = "SELECT p.id, p.supplier_id, p.amount, p.payment_date, p.method, p.reference, p.journal_id,\n"
           . "c.name AS supplier_name\n"
           . "FROM ap_payments p\n"
           . "LEFT JOIN crm_accounts c ON p.supplier_id = c.id\n"
           . "$where\n"
           . "ORDER BY p.payment_date DESC, p.id DESC LIMIT $perPage OFFSET $offset";
    $stmt = $DB->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok' => true, 'data' => $payments,
        'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages
    ]);
} catch (Exception $e) {
    error_log('AP payment list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load payments']);
}