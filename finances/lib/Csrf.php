<?php
// /finances/lib/Csrf.php
class Csrf {
    public static function token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    
    public static function validate(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
        if (!isset($_SESSION['csrf_token'], $token) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    }
}