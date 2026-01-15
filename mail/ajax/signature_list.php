<?php
// /mail/ajax/signature_list.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $stmt = $DB->prepare("
        SELECT signature_id, name, content_html, is_default
        FROM email_signatures
        WHERE company_id = ? AND (user_id = ? OR user_id IS NULL)
        ORDER BY is_default DESC, name
    ");
    $stmt->execute([$companyId, $userId]);
    $signatures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'signatures' => $signatures]);

} catch (Exception $e) {
    error_log("Signature list error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load signatures']);
}