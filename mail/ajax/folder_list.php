<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
header('Content-Type: application/json');
$companyId = $_SESSION['company_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;

$folders = ['INBOX','Sent','Archive','Drafts','Trash'];
$sql = "SELECT e.folder, SUM(CASE WHEN e.is_read=0 THEN 1 ELSE 0 END) AS unread
        FROM emails e
        JOIN email_accounts a ON a.account_id=e.account_id
        WHERE a.company_id=? AND a.user_id=?
        GROUP BY e.folder";
$stmt = $DB->prepare($sql); $stmt->execute([$companyId,$userId]);
$map = []; foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $map[$r['folder']] = (int)$r['unread']; }
$out = []; foreach ($folders as $f) $out[] = ['name'=>$f,'unread'=>$map[$f] ?? 0];
echo json_encode(['ok'=>true,'folders'=>$out]);