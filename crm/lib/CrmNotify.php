<?php
// crm/lib/CrmNotify.php
//
// The CRM's background reminders, run from notifications/api/tick.php (and
// crm/cron/reminders.php where the host has a real cron):
//   - task reminders: at the time a task asked for, a bell + phone
//     notification to the person it is assigned to (once),
//   - compliance documents: when a document reaches one of the company's
//     reminder days (default 30, 14 and 7 days before expiry) and when it
//     expires, the account owner hears about it (once per step).
// Anything that fell due more than two days ago (a long outage, the first
// run after deployment) is marked as handled without buzzing anyone.
// Also here, once a day: the supplier delivery figures on supplier pages
// (on time, average hours to delivery, cancelled deliveries).

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmCompliance.php';

final class CrmNotify
{
    /** @return array{tasks: int, compliance: int} */
    public static function tick(PDO $db, float $deadline): array
    {
        require_once __DIR__ . '/../../includes/notify.php';
        CrmSchema::ensure($db);
        return ['tasks' => self::tasks($db, $deadline), 'compliance' => microtime(true) < $deadline ? self::compliance($db, $deadline) : 0];
    }

    public static function tasks(PDO $db, float $deadline): int
    {
        $now = date('Y-m-d H:i:s');
        // Too old to be useful: mark handled quietly.
        $db->prepare("UPDATE crm_tasks SET reminded_at = NOW() WHERE reminded_at IS NULL AND remind_at < ? AND done_at IS NULL")
           ->execute([date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $db->exec("UPDATE crm_tasks SET reminded_at = NOW() WHERE reminded_at IS NULL AND remind_at IS NOT NULL AND done_at IS NOT NULL");
        $st = $db->prepare("SELECT t.*, a.name AS account_name FROM crm_tasks t
                         LEFT JOIN crm_accounts a ON a.id = t.account_id AND a.company_id = t.company_id
                             WHERE t.reminded_at IS NULL AND t.remind_at IS NOT NULL AND t.remind_at <= ? AND t.done_at IS NULL AND t.assigned_to IS NOT NULL
                          ORDER BY t.remind_at LIMIT 200");
        $st->execute([$now]);
        $mark = $db->prepare("UPDATE crm_tasks SET reminded_at = NOW() WHERE id = ? AND reminded_at IS NULL");
        $sent = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (microtime(true) > $deadline) break;
            $mark->execute([(int)$t['id']]);
            if ($mark->rowCount() === 0) continue; // another tick took it
            $when = $t['due_at'] ? ((int)$t['due_all_day'] ? 'today' : 'at ' . date('H:i', strtotime((string)$t['due_at']))) : '';
            if ($t['due_at'] && substr((string)$t['due_at'], 0, 10) !== date('Y-m-d')) $when = 'on ' . date('j M', strtotime((string)$t['due_at'])) . ((int)$t['due_all_day'] ? '' : ' at ' . date('H:i', strtotime((string)$t['due_at'])));
            $url = $t['opportunity_id'] ? '/crm/opp_view.php?id=' . (int)$t['opportunity_id'] : ($t['account_id'] ? '/crm/account_view.php?id=' . (int)$t['account_id'] . '#tasks' : '/crm/#tasks');
            fw_notify($db, (int)$t['company_id'], (int)$t['assigned_to'], 'crm_task_due', 'Reminder: ' . $t['title'],
                      trim(($t['account_name'] ? $t['account_name'] . ' · ' : '') . ($when !== '' ? 'Due ' . $when : ''), ' ·'), $url,
                      ['ch' => 'general', 'tag' => 'crm-task-' . (int)$t['id']]);
            $sent++;
        }
        return $sent;
    }

    /**
     * Supplier delivery figures from purchase orders and goods received notes:
     * on time = deliveries ÷ orders (at most 100%), the average hours from order
     * to delivery, and the share of deliveries that were cancelled.
     * Returns how many suppliers were updated.
     */
    public static function supplierKpis(PDO $db): int
    {
        $orders = [];
        foreach ($db->query("SELECT company_id, supplier_id, COUNT(*) AS n FROM purchase_orders WHERE supplier_id IS NOT NULL GROUP BY company_id, supplier_id") as $r) {
            $orders[(int)$r['company_id'] . ':' . (int)$r['supplier_id']] = (int)$r['n'];
        }
        $grn = [];
        foreach ($db->query("SELECT p.company_id, p.supplier_id, COUNT(*) AS total, SUM(g.status = 'cancelled') AS cancelled,
                                    AVG(CASE WHEN g.received_date IS NOT NULL THEN TIMESTAMPDIFF(HOUR, p.created_at, g.received_date) END) AS hours
                               FROM purchase_orders p JOIN goods_received_notes g ON g.po_id = p.id AND g.company_id = p.company_id
                              WHERE p.supplier_id IS NOT NULL GROUP BY p.company_id, p.supplier_id") as $r) {
            $grn[(int)$r['company_id'] . ':' . (int)$r['supplier_id']] = $r;
        }
        $upd = $db->prepare("UPDATE crm_accounts SET on_time_percent = ?, avg_response_hours = ?, defect_rate_percent = ? WHERE company_id = ? AND id = ? AND type = 'supplier'");
        $n = 0;
        foreach ($orders as $key => $count) {
            [$cid, $sid] = array_map('intval', explode(':', $key));
            $g = $grn[$key] ?? null;
            $total = $g ? (int)$g['total'] : 0;
            $onTime = $count > 0 ? min(100.0, round($total / $count * 100, 2)) : null;
            $hours = $g && $g['hours'] !== null ? round((float)$g['hours'], 2) : null;
            $defects = $total > 0 ? round((int)$g['cancelled'] / $total * 100, 2) : null;
            $upd->execute([$onTime, $hours, $defects, $cid, $sid]);
            $n++;
        }
        // Suppliers without orders show no figures.
        $db->exec("UPDATE crm_accounts a SET a.on_time_percent = NULL, a.avg_response_hours = NULL, a.defect_rate_percent = NULL
                    WHERE a.type = 'supplier' AND (a.on_time_percent IS NOT NULL OR a.avg_response_hours IS NOT NULL OR a.defect_rate_percent IS NOT NULL)
                      AND NOT EXISTS (SELECT 1 FROM purchase_orders p WHERE p.company_id = a.company_id AND p.supplier_id = a.id)");
        return $n;
    }

    public static function compliance(PDO $db, float $deadline): int
    {
        $sent = 0;
        $companies = $db->query("SELECT DISTINCT company_id FROM crm_compliance_docs WHERE expiry_date IS NOT NULL
                                  AND expiry_date BETWEEN CURDATE() - INTERVAL 3 DAY AND CURDATE() + INTERVAL 366 DAY")->fetchAll(PDO::FETCH_COLUMN);
        $logged = $db->prepare("SELECT mark FROM crm_notify_log WHERE company_id = ? AND kind = 'compliance' AND ref_id = ?");
        $log = $db->prepare("INSERT IGNORE INTO crm_notify_log (company_id, kind, ref_id, mark, created_at) VALUES (?, 'compliance', ?, ?, NOW())");
        foreach ($companies as $cid) {
            $cid = (int)$cid;
            if (microtime(true) > $deadline) break;
            $policy = CrmCompliance::policy($db, $cid);
            $days = $policy['reminder_days'];
            $st = $db->prepare("SELECT d.id, d.account_id, d.expiry_date, d.created_by, t.name AS type_name, a.name AS account_name, a.owner_id, a.created_by AS account_creator
                                  FROM crm_compliance_docs d JOIN crm_compliance_types t ON t.id = d.type_id
                                  JOIN crm_accounts a ON a.id = d.account_id AND a.deleted_at IS NULL
                                 WHERE d.company_id = ? AND d.expiry_date IS NOT NULL AND d.expiry_date BETWEEN CURDATE() - INTERVAL 3 DAY AND CURDATE() + INTERVAL ? DAY
                                   AND NOT EXISTS (SELECT 1 FROM crm_compliance_docs n WHERE n.company_id = d.company_id AND n.account_id = d.account_id
                                                      AND n.type_id = d.type_id AND n.id <> d.id AND COALESCE(n.expiry_date, '9999-12-31') > d.expiry_date)
                                 LIMIT 500");
            $st->execute([$cid, max($days)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $left = (int)floor((strtotime((string)$d['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
                $logged->execute([$cid, (int)$d['id']]);
                $done = array_flip($logged->fetchAll(PDO::FETCH_COLUMN));
                if ($left < 0) {
                    $mark = 'expired';
                } else {
                    $hit = array_values(array_filter($days, fn($x) => $left <= $x));
                    if (!$hit) continue;
                    $mark = 'd' . min($hit);
                }
                if (isset($done[$mark])) continue;
                // Every larger step counts as handled too, so a late first run sends one message, not three.
                foreach ($days as $x) if ($left <= $x) $log->execute([$cid, (int)$d['id'], 'd' . $x]);
                $log->execute([$cid, (int)$d['id'], $mark]);
                $to = (int)($d['owner_id'] ?: ($d['created_by'] ?: $d['account_creator']));
                if ($to <= 0) continue;
                $title = $left < 0 ? $d['type_name'] . ' expired — ' . $d['account_name']
                                   : $d['type_name'] . ' expires ' . ($left === 0 ? 'today' : ($left === 1 ? 'tomorrow' : 'in ' . $left . ' days')) . ' — ' . $d['account_name'];
                fw_notify($db, $cid, $to, 'crm_compliance', $title, 'Ask for an updated document and upload it on the account.',
                          '/crm/account_view.php?id=' . (int)$d['account_id'] . '#compliance', ['ch' => 'general']);
                $sent++;
            }
        }
        return $sent;
    }
}
