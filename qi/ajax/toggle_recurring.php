<?php
// /qi/ajax/toggle_recurring.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$recurringId = $input['id'] ?? null;
$active = isset($input['active']) ? (int)$input['active'] : null;

if ($recurringId === null || $active === null) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $stmt = $DB->prepare("
        UPDATE recurring_invoices 
        SET active = ?
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$active, $recurringId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'recurring_invoice_toggled', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['recurring_id' => $recurringId, 'active' => $active]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Toggle recurring error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed']);
}