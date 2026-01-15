<?php
// /mail/ajax/signature_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$signatureId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$signatureId) {
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT * FROM email_signatures
        WHERE signature_id = ? AND company_id = ? AND (user_id = ? OR user_id IS NULL)
    ");
    $stmt->execute([$signatureId, $companyId, $userId]);
    $signature = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$signature) {
        echo json_encode(['ok' => false, 'error' => 'Signature not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'signature' => $signature]);

} catch (Exception $e) {
    error_log("Signature get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load signature']);
}