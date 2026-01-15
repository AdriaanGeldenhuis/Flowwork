<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/RulesEngine.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true);
$subject = $in['subject'] ?? '';
$body    = $in['body'] ?? '';
$intent  = RulesEngine::detectIntent($subject, $body);
echo json_encode(['ok'=>true,'intent'=>$intent]);