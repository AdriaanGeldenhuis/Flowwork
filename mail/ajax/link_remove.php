<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
header('Content-Type: application/json');
$companyId = $_SESSION['company_id'] ?? 0;
$in = json_decode(file_get_contents('php://input'), true);
$emailId = (int)($in['email_id'] ?? 0);
$type    = trim($in['linked_type'] ?? '');
$lid     = (int)($in['linked_id'] ?? 0);
if (!$emailId || !$type || !$lid) { echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
$DB->prepare("DELETE FROM email_links WHERE company_id=? AND email_id=? AND linked_type=? AND linked_id=? LIMIT 1")
   ->execute([$companyId,$emailId,$type,$lid]);
echo json_encode(['ok'=>true]);