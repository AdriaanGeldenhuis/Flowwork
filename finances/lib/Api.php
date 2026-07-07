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

/**
 * Emit a JSON error for a caught exception without leaking internals:
 * business-rule Exceptions pass their message through; PDO/engine failures
 * are logged and replaced with a generic message. Use inside endpoint
 * catch-blocks instead of echoing $e->getMessage() directly.
 */
if (!function_exists('json_exception')) {
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

// Catch uncaught exceptions inside API scripts and emit JSON. Business-rule
// failures are thrown as plain Exception and their message is safe to show;
// database/engine failures (PDOException, Error) carry internal detail (SQL
// text, file paths) that must never reach the client — log those and return
// a generic message.
set_exception_handler(function($e) {
    if ($e instanceof PDOException || $e instanceof Error) {
        error_log('API exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        json_error('Internal server error', 500);
    }
    $code = $e->getCode();
    if (!is_int($code) || $code < 400 || $code > 599) $code = 400;
    json_error($e->getMessage(), $code);
});
