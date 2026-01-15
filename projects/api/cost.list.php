<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$itemId = (int)($_GET['item_id'] ?? 0);
if (!$itemId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing item_id']);
    exit;
}

$COMPANY_ID = $_SESSION['company_id'];

// Join to CRM suppliers instead of non-existent suppliers table
$stmt = $DB->prepare("
    SELECT 
        c.id, c.type, c.description, c.qty, c.unit_cost, c.tax_code,
        c.supplier_id,
        s.name AS supplier_name,
        (c.qty * c.unit_cost) AS subtotal,
        u.first_name, u.last_name,
        c.created_at
    FROM cost_items c
    LEFT JOIN crm_accounts s ON c.supplier_id = s.id AND s.type = 'supplier'
    LEFT JOIN users u ON c.created_by = u.id
    WHERE c.item_id = ? AND c.company_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$itemId, $COMPANY_ID]);
$costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($costs, 'subtotal'));

echo json_encode(['ok' => true, 'costs' => $costs, 'total' => $total]);