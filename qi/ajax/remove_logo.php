<?php
// /qi/ajax/remove_logo.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    // Fetch current logo URL
    $stmt = $DB->prepare("SELECT logo_url FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();

    if ($company && $company['logo_url']) {
        // Delete physical file
        $filepath = __DIR__ . '/../..' . $company['logo_url'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }
    }

    // Update database
    $stmt = $DB->prepare("UPDATE companies SET logo_url = NULL, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$companyId]);

    echo json_encode([
        'ok' => true,
        'message' => 'Logo removed successfully'
    ]);

} catch (Exception $e) {
    error_log("Logo removal error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}