<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/companies.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$companyId = (int)($_POST['company_id'] ?? 0);

if ($companyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing company_id']);
    exit;
}

if (!fw_set_active_company($userId, $companyId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have access to this company.']);
    exit;
}

try {
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, created_at)
         VALUES (?, ?, 'company_switch', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['company_id' => $companyId]),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
} catch (Exception $e) {
    error_log('company_switch audit log error: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'company_id' => $companyId]);
