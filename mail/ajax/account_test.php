<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SecureVault.php';
header('Content-Type: application/json');

$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }

$stmt = $DB->prepare("SELECT * FROM email_accounts WHERE account_id=? AND company_id=? AND user_id=?");
$stmt->execute([$id,$companyId,$userId]);
$acc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$acc) { echo json_encode(['ok'=>false,'error'=>'Account not found']); exit; }

$pass = SecureVault::decrypt($acc['password_encrypted'] ?? '');
$host = $acc['imap_server']; $port = (int)$acc['imap_port']; $enc = strtolower($acc['imap_encryption'] ?? 'ssl');
$mbx = sprintf("{%s:%d/imap/%s}INBOX", $host, $port, $enc==='ssl'?'ssl':($enc==='tls'?'tls':'novalidate-cert'));
if (!function_exists('imap_open')) { echo json_encode(['ok'=>false,'error'=>'IMAP extension not enabled']); exit; }
$inbox = @imap_open($mbx, $acc['username'], $pass, OP_HALFOPEN, 1);
if ($inbox === false) { echo json_encode(['ok'=>false,'error'=>imap_last_error()]); exit; }
imap_close($inbox);
echo json_encode(['ok'=>true,'message'=>'IMAP connect ok']);