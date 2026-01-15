<?php
// /crm/ajax/contact_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$contactId = (int)($_GET['id'] ?? 0);

try {
    $stmt = $DB->prepare("
        SELECT * FROM crm_contacts 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$contactId, $companyId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact) {
        throw new Exception('Contact not found');
    }

    echo json_encode(['ok' => true, 'contact' => $contact]);

} catch (Exception $e) {
    error_log("CRM contact_get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}