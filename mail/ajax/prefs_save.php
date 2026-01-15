<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
header('Content-Type: application/json');
$companyId = $_SESSION['company_id'] ?? 0;
$userId    = $_SESSION['user_id'] ?? 0;
$in = json_decode(file_get_contents('php://input'), true);
$slaHours = (int)($in['sla_default_hours'] ?? 24);
$blockImg = !empty($in['block_external_images']) ? 1 : 0;
$notify   = !empty($in['notification_enabled']) ? 1 : 0;
$notifyJson = isset($in['notify_channels_json']) ? json_encode($in['notify_channels_json']) : null;
$quietJson  = isset($in['quiet_hours_json']) ? json_encode($in['quiet_hours_json']) : null;
$sql = "INSERT INTO user_mail_prefs (company_id,user_id,sla_default_hours,block_external_images,notification_enabled,notify_channels_json,quiet_hours_json)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE sla_default_hours=VALUES(sla_default_hours),
                                block_external_images=VALUES(block_external_images),
                                notification_enabled=VALUES(notification_enabled),
                                notify_channels_json=VALUES(notify_channels_json),
                                quiet_hours_json=VALUES(quiet_hours_json)";
$DB->prepare($sql)->execute([$companyId,$userId,$slaHours,$blockImg,$notify,$notifyJson,$quietJson]);
echo json_encode(['ok'=>true]);