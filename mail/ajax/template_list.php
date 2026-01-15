<?php
// /mail/ajax/template_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT template_id, name, subject, category
        FROM email_templates
        WHERE company_id = ?
        ORDER BY category, name
    ");
    $stmt->execute([$companyId]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'templates' => $templates]);

} catch (Exception $e) {
    error_log("Template list error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load templates']);
}