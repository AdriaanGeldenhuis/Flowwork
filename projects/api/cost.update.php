<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$costId = (int)($_POST['cost_id'] ?? 0);
if (!$costId) respond_error('Cost ID required');

$stmt = $DB->prepare("SELECT project_id FROM cost_items WHERE id = ? AND company_id = ?");
$stmt->execute([$costId, $COMPANY_ID]);
$cost = $stmt->fetch();
if (!$cost) respond_error('Cost not found', 404);

require_project_role($cost['project_id'], 'contributor');

$allowed = ['type', 'description', 'qty', 'unit_cost', 'tax_code', 'supplier_id', 'status'];
$updates = [];
$values = [];

foreach ($allowed as $field) {
    if (isset($_POST[$field])) {
        $updates[] = "$field = ?";
        $values[] = $_POST[$field];
    }
}

if (empty($updates)) respond_error('No fields to update');

$values[] = $costId;
$values[] = $COMPANY_ID;

try {
    $sql = "UPDATE cost_items SET " . implode(', ', $updates) . " WHERE id = ? AND company_id = ?";
    $stmt = $DB->prepare($sql);
    $stmt->execute($values);
    
    respond_ok(['updated' => $stmt->rowCount()]);
    
} catch (Exception $e) {
    error_log("Cost update error: " . $e->getMessage());
    respond_error('Failed to update cost', 500);
}