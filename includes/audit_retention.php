<?php
// /includes/audit_retention.php — Admin › Data & privacy › "Keep the audit
// trail for". Run hourly by the background runner (notifications/api/tick.php):
// for every company that chose a limit (company_settings audit_retention_days
// > 0; the default 0 keeps everything), audit entries older than that are
// removed, a bounded batch at a time, and the pruning itself is recorded.

function fw_audit_retention_cleanup(PDO $db, int $batch = 5000): int
{
    $removed = 0;
    $st = $db->query("SELECT company_id, CAST(setting_value AS UNSIGNED) AS days FROM company_settings
                       WHERE setting_key = 'audit_retention_days' AND setting_value REGEXP '^[0-9]+$' AND CAST(setting_value AS UNSIGNED) >= 365");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $companyId = (int)$row['company_id'];
        $days = (int)$row['days'];
        $del = $db->prepare("DELETE FROM audit_log WHERE company_id = ? AND COALESCE(`timestamp`, created_at) < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT " . (int)$batch);
        $del->execute([$companyId, $days]);
        $n = $del->rowCount();
        if ($n > 0) {
            $removed += $n;
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, details, timestamp, created_at) VALUES (?, NULL, 'audit_pruned', ?, NOW(), NOW())")
               ->execute([$companyId, json_encode(['removed' => $n, 'older than' => $days . ' days'])]);
        }
    }
    return $removed;
}
