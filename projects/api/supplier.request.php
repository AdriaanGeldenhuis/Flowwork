<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$costItemId = (int)($_POST['cost_item_id'] ?? 0);
if (!$costItemId) respond_error('cost_item_id required');

// Get cost item
$stmt = $DB->prepare("SELECT project_id, supplier_id FROM cost_items WHERE id = ? AND company_id = ?");
$stmt->execute([$costItemId, $COMPANY_ID]);
$cost = $stmt->fetch();
if (!$cost) respond_error('Cost item not found', 404);

require_project_role($cost['project_id'], 'manager');

try {
    // Create supplier quote request
    $stmt = $DB->prepare("
        INSERT INTO supplier_quotes (company_id, supplier_id, project_id, item_id, status, data_json, created_at)
        VALUES (?, ?, ?, ?, 'requested', '{}', NOW())
    ");
    $stmt->execute([$COMPANY_ID, $cost['supplier_id'], $cost['project_id'], $costItemId]);
    
    respond_ok(['quote_id' => $DB->lastInsertId()]);
    
} catch (Exception $e) {
    error_log("Supplier request error: " . $e->getMessage());
    respond_error('Failed to create supplier quote request', 500);
}