<?php
// finances/lib/Api.php
// Uniform JSON responses for AJAX/API endpoints.

function json_ok($data = null, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400, $meta = null): void {
    http_response_code($status);
    header('Content-Type: application/json');
    $payload = ['ok' => false, 'error' => $message];
    if (!is_null($meta)) $payload['meta'] = $meta;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Optional: catch uncaught exceptions inside API scripts and emit JSON.
set_exception_handler(function($e) {
    $code = $e->getCode();
    if (!is_int($code) || $code < 400 || $code > 599) $code = 500;
    json_error($e->getMessage(), $code);
});
