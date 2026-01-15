<?php
// /payroll/ajax/payitem_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$type = $_GET['type'] ?? '';

$sql = "SELECT id, code, name, type, taxable, uif_subject, sdl_subject, 
               gl_account_code, active
        FROM payitems
        WHERE company_id = ?";

$params = [$companyId];

if ($type) {
    $sql .= " AND type = ?";
    $params[] = $type;
}

$sql .= " ORDER BY type ASC, name ASC";

try {
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $payitems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'payitems' => $payitems
    ]);
} catch (Exception $e) {
    error_log("Payitem list error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Database error'
    ]);
}