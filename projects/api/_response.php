<?php
/**
 * Standardized API Response Functions
 * All APIs must use these for consistency
 */

function api_success($data = [], $message = 'Success', $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    
    while (ob_get_level()) ob_end_clean();
    
    echo json_encode([
        'ok' => true,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    exit;
}

function api_error($message, $code = 'ERROR', $httpCode = 400) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    
    while (ob_get_level()) ob_end_clean();
    
    echo json_encode([
        'ok' => false,
        'error' => $message,
        'code' => $code
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    exit;
}

function api_validate_csrf() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        api_error('Invalid CSRF token', 'CSRF_ERROR', 403);
    }
}

function api_require_auth() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
        api_error('Authentication required', 'AUTH_ERROR', 401);
    }
}