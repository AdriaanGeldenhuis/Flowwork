<?php
// /mail/ajax/template_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$input = json_decode(file_get_contents('php://input'), true);
$templateId = isset($input['template_id']) ? (int)$input['template_id'] : 0;

if (!$templateId) {
    echo json_encode(['ok' => false, 'error' => 'Missing template_id']);
    exit;
}

try {
    $stmt = $DB->prepare("DELETE FROM email_templates WHERE template_id = ? AND company_id = ?");
    $stmt->execute([$templateId, $companyId]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Template delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to delete template']);
}