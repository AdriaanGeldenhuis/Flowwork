<?php
// /includes/audit_retention.php — Admin › Data & privacy › "Keep sign-in
// records for". Run hourly by the background runner (notifications/api/tick.php):
// for every company that chose a limit (company_settings signin_retention_days
// > 0; the default 0 keeps everything), sign-in records older than that —
// sign-ins, sign-outs and company switches, which carry IP addresses and
// devices — are removed, a bounded batch at a time, and the pruning is
// recorded. Nothing else in the audit trail is ever removed here: settings,
// people, invoices and the books (Finance's own audit entries) stay.

const FW_SIGNIN_ACTIONS = ['user_login', 'user_logout', 'company_switch'];

function fw_audit_retention_cleanup(PDO $db, int $batch = 5000): int
{
    $removed = 0;
    $in = implode(',', array_fill(0, count(FW_SIGNIN_ACTIONS), '?'));
    $st = $db->query("SELECT company_id, CAST(setting_value AS UNSIGNED) AS days FROM company_settings
                       WHERE setting_key = 'signin_retention_days' AND setting_value REGEXP '^[0-9]+$' AND CAST(setting_value AS UNSIGNED) >= 30");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $companyId = (int)$row['company_id'];
        $days = (int)$row['days'];
        $del = $db->prepare("DELETE FROM audit_log WHERE company_id = ? AND action IN ($in)
                              AND COALESCE(`timestamp`, created_at) < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT " . (int)$batch);
        $del->execute(array_merge([$companyId], FW_SIGNIN_ACTIONS, [$days]));
        $n = $del->rowCount();
        if ($n > 0) {
            $removed += $n;
            $db->prepare("INSERT INTO audit_log (company_id, user_id, action, details, timestamp, created_at) VALUES (?, NULL, 'audit_pruned', ?, NOW(), NOW())")
               ->execute([$companyId, json_encode(['sign-in records removed' => $n, 'older than' => $days . ' days'])]);
        }
    }
    return $removed;
}
