<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    // Validate CSRF
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    if (empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    $USER_ID = $_SESSION['user_id'];
    
    $boardId = (int)($_POST['board_id'] ?? 0);
    $groupId = (int)($_POST['group_id'] ?? 0);
    
    if (!$boardId || !$groupId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID and Group ID required']));
    }
    
    if (!isset($_FILES['csv_file'])) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'No file uploaded']));
    }
    
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'File upload error: ' . $file['error']]));
    }
    
    // Check access
    $stmt = $DB->prepare("
        SELECT board_id FROM project_boards
        WHERE board_id = ? AND company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Check group
    $stmt = $DB->prepare("
        SELECT id FROM board_groups
        WHERE id = ? AND board_id = ?
    ");
    $stmt->execute([$groupId, $boardId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Group not found']));
    }
    
    // Read CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        http_response_code(500);
        die(json_encode(['ok' => false, 'error' => 'Cannot read file']));
    }
    
    $headers = fgetcsv($handle);
    $imported = 0;
    $errors = [];
    
    // Get max position
    $stmt = $DB->prepare("
        SELECT COALESCE(MAX(position), -1) + 1 AS next_pos
        FROM board_items
        WHERE board_id = ? AND group_id = ?
    ");
    $stmt->execute([$boardId, $groupId]);
    $position = (int)$stmt->fetchColumn();
    
    while (($data = fgetcsv($handle)) !== false) {
        try {
            if (empty($data[0])) continue;
            
            $title = $data[0];
            
            $stmt = $DB->prepare("
                INSERT INTO board_items (board_id, group_id, company_id, title, position, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$boardId, $groupId, $COMPANY_ID, $title, $position, $USER_ID]);
            
            $position++;
            $imported++;
            
        } catch (Exception $e) {
            $errors[] = "Row " . ($imported + 1) . ": " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => "Imported {$imported} items",
        'data' => [
            'imported' => $imported,
            'errors' => $errors
        ]
    ]);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Import Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;