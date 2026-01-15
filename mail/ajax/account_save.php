<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SecureVault.php';
header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$accountId = (int)($input['account_id'] ?? 0);
$fields = [
  'account_name','email_address',
  'imap_server','imap_port','imap_encryption',
  'smtp_server','smtp_port','smtp_encryption',
  'username','is_active'
];
$data = [];
foreach ($fields as $f) $data[$f] = $input[$f] ?? null;
$pass = $input['password'] ?? '';
$enc  = $pass !== '' ? SecureVault::encrypt($pass) : null;

try {
  if ($accountId > 0) {
    $sql = "UPDATE email_accounts SET
              account_name=?, email_address=?, imap_server=?, imap_port=?, imap_encryption=?,
              smtp_server=?, smtp_port=?, smtp_encryption=?, username=?, ".($enc !== null ? "password_encrypted=?," : "")."
              is_active=?
            WHERE account_id=? AND company_id=? AND user_id=?";
    $params = [
      $data['account_name'], $data['email_address'], $data['imap_server'], (int)$data['imap_port'], $data['imap_encryption'],
      $data['smtp_server'], (int)$data['smtp_port'], $data['smtp_encryption'], $data['username']
    ];
    if ($enc !== null) $params[] = $enc;
    $params[] = (int)$data['is_active'];
    $params[] = $accountId; $params[] = $companyId; $params[] = $userId;
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
  } else {
    $stmt = $DB->prepare("INSERT INTO email_accounts
      (company_id,user_id,account_name,email_address,imap_server,imap_port,imap_encryption,smtp_server,smtp_port,smtp_encryption,username,password_encrypted,is_active)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
      $companyId,$userId,$data['account_name'],$data['email_address'],$data['imap_server'],(int)$data['imap_port'],$data['imap_encryption'],
      $data['smtp_server'],(int)$data['smtp_port'],$data['smtp_encryption'],$data['username'],$enc,(int)$data['is_active']
    ]);
    $accountId = (int)$DB->lastInsertId();
  }
  echo json_encode(['ok'=>true,'account_id'=>$accountId]);
} catch (Exception $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); 
}