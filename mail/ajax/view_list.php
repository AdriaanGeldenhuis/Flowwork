<?php
// /mail/ajax/view_list.php
// Returns a list of saved mail views for the current user
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

try {
    // Ensure the table exists; if not, return empty list
    $stmt = $DB->prepare("SHOW TABLES LIKE 'mail_saved_views'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => true, 'views' => []]);
        exit;
    }
    $stmt = $DB->prepare("SELECT id, name, filters_json FROM mail_saved_views WHERE company_id = ? AND user_id = ? ORDER BY name");
    $stmt->execute([$companyId, $userId]);
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'views' => $views]);
} catch (Exception $e) {
    error_log('View list error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load views']);
}