<?php
// /qi/ajax/get_milestones.php - Fetch milestones for a quote or invoice
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId  = $_SESSION['company_id'];
$entityType = $_GET['entity_type'] ?? '';
$entityId   = filter_input(INPUT_GET, 'entity_id', FILTER_VALIDATE_INT);

if (!in_array($entityType, ['quote', 'invoice']) || !$entityId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT id, label, percentage, amount, due_date, status, amount_paid, sort_order
        FROM payment_milestones
        WHERE entity_type = ? AND entity_id = ? AND company_id = ?
        ORDER BY sort_order
    ");
    $stmt->execute([$entityType, $entityId, $companyId]);
    $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'milestones' => $milestones]);
} catch (Exception $e) {
    error_log('Get milestones error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to fetch milestones']);
}
