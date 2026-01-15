<?php
// /qi/ajax/delete_recurring.php - COMPLETE FILE
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$recurringId = $input['id'] ?? null;

if (!$recurringId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    // Delete recurring invoice (cascade will delete lines)
    $stmt = $DB->prepare("DELETE FROM recurring_invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$recurringId, $companyId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Recurring invoice not found');
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'recurring_invoice_deleted', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['recurring_id' => $recurringId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Delete recurring error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}