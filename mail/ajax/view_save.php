<?php
// /mail/ajax/view_save.php
// Save or update a mail saved view
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$viewId    = isset($input['id']) ? (int)$input['id'] : 0;
$name      = trim($input['name'] ?? '');
$filters   = $input['filters'] ?? null;

if (!$name || !$filters || !is_array($filters)) {
    echo json_encode(['ok' => false, 'error' => 'Name and filters are required']);
    exit;
}

try {
    // Ensure the table exists; if not, attempt to create it
    $stmt = $DB->prepare("SHOW TABLES LIKE 'mail_saved_views'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        // Create table dynamically
        $DB->exec("CREATE TABLE IF NOT EXISTS mail_saved_views (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            filters_json TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_company_user (company_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $filtersJson = json_encode($filters);
    if ($viewId) {
        // Update existing
        $stmt = $DB->prepare("UPDATE mail_saved_views SET name = ?, filters_json = ?, updated_at = NOW() WHERE id = ? AND company_id = ? AND user_id = ?");
        $stmt->execute([$name, $filtersJson, $viewId, $companyId, $userId]);
    } else {
        // Insert new
        $stmt = $DB->prepare("INSERT INTO mail_saved_views (company_id, user_id, name, filters_json, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$companyId, $userId, $name, $filtersJson]);
        $viewId = (int)$DB->lastInsertId();
    }
    echo json_encode(['ok' => true, 'id' => $viewId]);
} catch (Exception $e) {
    error_log('View save error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save view']);
}