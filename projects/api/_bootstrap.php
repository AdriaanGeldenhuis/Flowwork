<?php
// /projects/api/_bootstrap.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Begin output buffering early to capture any unintended output.  This
// ensures that JSON responses are not contaminated with warnings or
// whitespace emitted before respond_ok/respond_error flushes the buffer.
ob_start();

header('Content-Type: application/json; charset=utf-8');

// CSRF check for POST/PUT/DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Extract session vars
$COMPANY_ID = $_SESSION['company_id'] ?? 0;
$USER_ID = $_SESSION['user_id'] ?? 0;
$USER_ROLE = $_SESSION['role'] ?? 'viewer';

if (!$COMPANY_ID || !$USER_ID) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}