<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
header('Content-Type: application/json');
$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;
$in = json_decode(file_get_contents('php://input'), true);
$emailId = (int)($in['email_id'] ?? 0);
$type    = trim($in['linked_type'] ?? '');
$lid     = (int)($in['linked_id'] ?? 0);
if (!$emailId || !$type || !$lid) { echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
$DB->prepare("INSERT INTO email_links (company_id,email_id,linked_type,linked_id,created_by,created_at) VALUES (?,?,?,?,?,NOW())")
   ->execute([$companyId,$emailId,$type,$lid,$userId]);
echo json_encode(['ok'=>true]);