<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SecureVault.php';
require_once __DIR__ . '/../lib/MailSyncService.php';
header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;

$stmt = $DB->prepare("SELECT * FROM email_accounts WHERE company_id=? AND user_id=? AND is_active=1");
$stmt->execute([$companyId,$userId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0; $errors = [];
foreach ($accounts as $acc) {
  try {
    if ($acc['imap_server']) {
      $total += MailSyncService::syncImapAccount($DB, $acc, $companyId);
    }
    // POP3 stub ignored
  } catch (Exception $e) {
    $errors[] = $e->getMessage();
  }
}
echo json_encode(['ok'=>true,'synced'=>$total,'errors'=>$errors]);
