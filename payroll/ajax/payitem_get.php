<?php
// /payroll/ajax/payitem_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$id = $_GET['id'] ?? 0;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'Missing ID']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT * FROM payitems 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$id, $companyId]);
    $payitem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payitem) {
        echo json_encode(['ok' => false, 'error' => 'Pay item not found']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'payitem' => $payitem
    ]);
} catch (Exception $e) {
    error_log("Payitem get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}