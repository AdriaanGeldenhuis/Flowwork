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
    
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    $USER_ID = $_SESSION['user_id'];
    
    $boardId = (int)($_GET['board_id'] ?? 0);
    
    if (!$boardId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID required']));
    }
    
    // Get views
    $stmt = $DB->prepare("
        SELECT 
            bsv.*,
            u.first_name,
            u.last_name
        FROM board_saved_views bsv
        LEFT JOIN users u ON bsv.user_id = u.id
        WHERE bsv.board_id = ? 
            AND (bsv.user_id = ? OR bsv.is_shared = 1)
        ORDER BY bsv.created_at DESC
    ");
    $stmt->execute([$boardId, $USER_ID]);
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse JSON fields
    foreach ($views as &$view) {
        $view['filters'] = json_decode($view['filters'], true) ?: [];
        $view['sorts'] = json_decode($view['sorts'], true) ?: [];
        $view['visible_columns'] = json_decode($view['visible_columns'], true) ?: [];
        $view['is_owner'] = ($view['user_id'] == $USER_ID);
    }
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Views loaded',
        'data' => ['views' => $views]
    ]);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("List Views Error: " . $e->getMessage());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;