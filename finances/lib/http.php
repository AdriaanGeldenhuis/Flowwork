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
if (!function_exists('json_exception')) {
    /**
     * JSON error for a caught exception without leaking internals: plain
     * (business-rule) Exception messages pass through; PDO/engine failures
     * are logged and replaced with a generic message.
     */
    function json_exception(Throwable $e, int $defaultStatus = 400): void {
        if ($e instanceof PDOException || $e instanceof Error) {
            error_log('API exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            json_error('Internal server error', 500);
        }
        $code = $e->getCode();
        if (!is_int($code) || $code < 400 || $code > 599) $code = $defaultStatus;
        json_error($e->getMessage(), $code);
    }
}
