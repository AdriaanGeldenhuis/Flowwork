<?php
// /mail/ajax/signature_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

$input = json_decode(file_get_contents('php://input'), true);
$signatureId = isset($input['signature_id']) ? (int)$input['signature_id'] : 0;

if (!$signatureId) {
    echo json_encode(['ok' => false, 'error' => 'Missing signature_id']);
    exit;
}

try {
    $stmt = $DB->prepare("DELETE FROM email_signatures WHERE signature_id = ? AND company_id = ?");
    $stmt->execute([$signatureId, $companyId]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Signature delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to delete signature']);
}