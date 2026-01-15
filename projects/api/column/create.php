<?php
/**
 * API: Create new column
 * POST /projects/api/column/create.php
 * 
 * Body:
 *   board_id: int
 *   name: string
 *   type: string (text, number, status, people, date, priority, supplier, etc.)
 *   width: int (optional, default 150)
 *   config: json (optional)
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    // ✅ USE CORRECT PATH METHOD
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    require_once $basePath . '/init.php';
    require_once $basePath . '/auth_gate.php';
    
    // Clear output buffer
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/json; charset=utf-8');
    
    // CSRF validation
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
    }
    
    if (empty($_SESSION['company_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401);
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }
    
    $COMPANY_ID = $_SESSION['company_id'];
    $USER_ID = $_SESSION['user_id'];
    
    $boardId = (int)($_POST['board_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? 'text');
    $width = (int)($_POST['width'] ?? 150);
    $config = isset($_POST['config']) ? trim($_POST['config']) : null;
    
    // Log received data for debugging
    error_log("Column Create - Board ID: $boardId, Name: $name, Type: $type");
    
    // Validation
    if (!$boardId) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Board ID required']));
    }
    
    if (empty($name)) {
        http_response_code(400);
        die(json_encode(['ok' => false, 'error' => 'Column name required']));
    }
    
    // ✅ COMPLETE LIST OF ALLOWED TYPES
    $allowedTypes = [
        'text', 'longtext', 'number', 
        'status', 'priority', 'progress',
        'people', 'supplier',
        'date', 'timeline', 
        'dropdown', 'checkbox', 'tags', 
        'link', 'email', 'phone', 
        'formula', 'files'
    ];
    
    if (!in_array($type, $allowedTypes)) {
        http_response_code(400);
        error_log("Invalid column type: $type. Allowed: " . implode(', ', $allowedTypes));
        die(json_encode([
            'ok' => false, 
            'error' => "Invalid column type: $type",
            'allowed_types' => $allowedTypes
        ]));
    }
    
    // Verify board belongs to company
    $stmt = $DB->prepare("
        SELECT board_id FROM project_boards
        WHERE board_id = ? AND company_id = ?
    ");
    $stmt->execute([$boardId, $COMPANY_ID]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        die(json_encode(['ok' => false, 'error' => 'Board not found']));
    }
    
    // Get next position
    $stmt = $DB->prepare("
        SELECT COALESCE(MAX(position), -1) + 1 as next_position
        FROM board_columns
        WHERE board_id = ?
    ");
    $stmt->execute([$boardId]);
    $nextPosition = (int)$stmt->fetchColumn();
    
    // Set default config based on type if not provided
    if ($config === null || $config === '') {
        if ($type === 'number') {
            $config = json_encode(['agg' => 'sum', 'precision' => 2]);
        } elseif ($type === 'formula') {
            $config = json_encode(['formula' => '', 'agg' => 'sum', 'precision' => 2]);
        } elseif ($type === 'dropdown') {
            $config = json_encode(['options' => ['Option 1', 'Option 2', 'Option 3']]);
        } elseif ($type === 'status') {
            $config = json_encode([
                'labels' => [
                    'To Do' => '#64748b',
                    'Working on it' => '#fbbf24',
                    'Done' => '#22c55e',
                    'Stuck' => '#ef4444'
                ]
            ]);
        }
    }
    
    // Validate JSON config if provided
    if ($config !== null && $config !== '') {
        $decoded = json_decode($config);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            die(json_encode(['ok' => false, 'error' => 'Invalid JSON config: ' . json_last_error_msg()]));
        }
    }
    
    // Insert column
    $stmt = $DB->prepare("
        INSERT INTO board_columns (
            board_id, 
            company_id, 
            name, 
            type, 
            width, 
            position, 
            visible, 
            config, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())
    ");
    
    $result = $stmt->execute([
        $boardId,
        $COMPANY_ID,
        $name,
        $type,
        $width,
        $nextPosition,
        $config
    ]);
    
    if (!$result) {
        throw new Exception('Failed to insert column: ' . implode(', ', $stmt->errorInfo()));
    }
    
    $columnId = (int)$DB->lastInsertId();
    
    error_log("Column created successfully: ID $columnId, Type $type, Name $name");
    
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'column_id' => $columnId,
        'message' => 'Column created successfully',
        'data' => [
            'column_id' => $columnId,
            'name' => $name,
            'type' => $type,
            'position' => $nextPosition
        ]
    ]);
    
} catch (PDOException $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Column Create DB Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    while (ob_get_level()) ob_end_clean();
    error_log("Column Create Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to create column: ' . $e->getMessage()
    ]);
}
exit;