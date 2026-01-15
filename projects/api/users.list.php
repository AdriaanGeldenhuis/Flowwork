<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_respond.php';

try {
    $stmt = $DB->prepare("
        SELECT id, first_name, last_name, email, role
        FROM users
        WHERE company_id = ? AND status = 'active'
        ORDER BY first_name, last_name
    ");
    $stmt->execute([$COMPANY_ID]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    respond_ok(['users' => $users]);
    
} catch (Exception $e) {
    error_log("Users list error: " . $e->getMessage());
    respond_error('Failed to load users', 500);
}