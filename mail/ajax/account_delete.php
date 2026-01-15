<?php
// /mail/ajax/account_delete.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$accountId = isset($input['account_id']) ? (int)$input['account_id'] : 0;

if (!$accountId) {
    echo json_encode(['ok' => false, 'error' => 'Missing account_id']);
    exit;
}

try {
    // Verify ownership
    $stmt = $DB->prepare("SELECT account_id FROM email_accounts WHERE account_id = ? AND company_id = ? AND user_id = ?");
    $stmt->execute([$accountId, $companyId, $userId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'Account not found']);
        exit;
    }

    // Delete emails (cascades via foreign keys if set, or manual cleanup)
    $stmt = $DB->prepare("DELETE FROM emails WHERE account_id = ?");
    $stmt->execute([$accountId]);

    // Delete account
    $stmt = $DB->prepare("DELETE FROM email_accounts WHERE account_id = ?");
    $stmt->execute([$accountId]);

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, timestamp)
        VALUES (?, ?, 'mail_account_deleted', ?, NOW())
    ");
    $stmt->execute([$companyId, $userId, json_encode(['account_id' => $accountId])]);

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    error_log("Account delete error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to delete account']);
}