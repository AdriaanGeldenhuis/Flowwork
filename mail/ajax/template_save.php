<?php
// /mail/ajax/template_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$templateId = isset($input['template_id']) ? (int)$input['template_id'] : 0;
$name = trim($input['name'] ?? '');
$subject = trim($input['subject'] ?? '');
$bodyHtml = trim($input['body_html'] ?? '');
$category = trim($input['category'] ?? '');

if (!$name || !$bodyHtml) {
    echo json_encode(['ok' => false, 'error' => 'Name and body are required']);
    exit;
}

try {
    if ($templateId) {
        // Update (tenant check)
        $stmt = $DB->prepare("SELECT template_id FROM email_templates WHERE template_id = ? AND company_id = ?");
        $stmt->execute([$templateId, $companyId]);
        if (!$stmt->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Template not found']);
            exit;
        }

        $stmt = $DB->prepare("
            UPDATE email_templates SET
                name = ?, subject = ?, body_html = ?, category = ?, updated_at = NOW()
            WHERE template_id = ?
        ");
        $stmt->execute([$name, $subject, $bodyHtml, $category, $templateId]);

    } else {
        // Create
        $stmt = $DB->prepare("
            INSERT INTO email_templates (company_id, name, subject, body_html, category, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $name, $subject, $bodyHtml, $category, $userId]);
        $templateId = $DB->lastInsertId();
    }

    echo json_encode(['ok' => true, 'template_id' => $templateId]);

} catch (Exception $e) {
    error_log("Template save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save template']);
}