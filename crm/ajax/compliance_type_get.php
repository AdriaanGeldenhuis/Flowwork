<?php
// /crm/ajax/compliance_type_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$typeId = (int)($_GET['id'] ?? 0);

try {
    $stmt = $DB->prepare("
        SELECT * FROM crm_compliance_types 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$typeId, $companyId]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$type) {
        throw new Exception('Document type not found');
    }

    echo json_encode(['ok' => true, 'type' => $type]);

} catch (Throwable $e) {
    error_log("CRM compliance_type_get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}