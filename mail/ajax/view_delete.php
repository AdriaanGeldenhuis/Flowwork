<?php
// /mail/ajax/view_delete.php
// Delete a saved mail view
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$viewId = isset($input['id']) ? (int)$input['id'] : 0;

if (!$viewId) {
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $stmt = $DB->prepare("DELETE FROM mail_saved_views WHERE id = ? AND company_id = ? AND user_id = ?");
    $stmt->execute([$viewId, $companyId, $userId]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    error_log('View delete error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to delete view']);
}