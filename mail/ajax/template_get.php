<?php
// /mail/ajax/template_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$templateId) {
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT * FROM email_templates
        WHERE template_id = ? AND company_id = ?
    ");
    $stmt->execute([$templateId, $companyId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        echo json_encode(['ok' => false, 'error' => 'Template not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'template' => $template]);

} catch (Exception $e) {
    error_log("Template get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load template']);
}