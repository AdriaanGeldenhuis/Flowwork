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

require_project_role($cost['project_id'], 'manager');

try {
    $stmt = $DB->prepare("DELETE FROM cost_items WHERE id = ? AND company_id = ?");
    $stmt->execute([$costId, $COMPANY_ID]);
    
    respond_ok(['deleted' => true]);
    
} catch (Exception $e) {
    error_log("Cost delete error: " . $e->getMessage());
    respond_error('Failed to delete cost', 500);
}