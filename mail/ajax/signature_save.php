<?php
// /mail/ajax/signature_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$signatureId = isset($input['signature_id']) ? (int)$input['signature_id'] : 0;
$name = trim($input['name'] ?? '');
$contentHtml = trim($input['content_html'] ?? '');
$isDefault = isset($input['is_default']) ? 1 : 0;

if (!$name || !$contentHtml) {
    echo json_encode(['ok' => false, 'error' => 'Name and content are required']);
    exit;
}

try {
    // If setting as default, unset other defaults
    if ($isDefault) {
        $stmt = $DB->prepare("UPDATE email_signatures SET is_default = 0 WHERE company_id = ? AND user_id = ?");
        $stmt->execute([$companyId, $userId]);
    }

    if ($signatureId) {
        // Update
        $stmt = $DB->prepare("SELECT signature_id FROM email_signatures WHERE signature_id = ? AND company_id = ?");
        $stmt->execute([$signatureId, $companyId]);
        if (!$stmt->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'Signature not found']);
            exit;
        }

        $stmt = $DB->prepare("
            UPDATE email_signatures SET
                name = ?, content_html = ?, is_default = ?, updated_at = NOW()
            WHERE signature_id = ?
        ");
        $stmt->execute([$name, $contentHtml, $isDefault, $signatureId]);

    } else {
        // Create
        $stmt = $DB->prepare("
            INSERT INTO email_signatures (company_id, user_id, name, content_html, is_default, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, $name, $contentHtml, $isDefault]);
        $signatureId = $DB->lastInsertId();
    }

    echo json_encode(['ok' => true, 'signature_id' => $signatureId]);

} catch (Exception $e) {
    error_log("Signature save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save signature']);
}