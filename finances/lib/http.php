<?php
// Lightweight HTTP helpers. Non-breaking. Reused by ajax/api endpoints.
if (!function_exists('require_method')) {
    function require_method(string $method): void {
        $req = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($req !== $method) {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
            exit;
        }
    }
}
if (!function_exists('json_error')) {
    function json_error(string $msg, int $code = 400): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }
}
