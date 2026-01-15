<?php
// /mail/ajax/account_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$accountId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$accountId) {
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $stmt = $DB->prepare("
        SELECT account_id, account_name, email_address, 
               imap_server, imap_port, imap_encryption,
               smtp_server, smtp_port, smtp_encryption,
               username, is_active
        FROM email_accounts
        WHERE account_id = ? AND company_id = ? AND user_id = ?
    ");
    $stmt->execute([$accountId, $companyId, $userId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo json_encode(['ok' => false, 'error' => 'Account not found']);
        exit;
    }

    // Don't send password
    unset($account['password_encrypted']);

    echo json_encode(['ok' => true, 'account' => $account]);

} catch (Exception $e) {
    error_log("Account get error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load account']);
}