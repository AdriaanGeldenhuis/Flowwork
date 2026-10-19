<?php
// crm/lib/CrmOverview.php
//
// The CRM overview: a short brief, key numbers, the pipeline funnel, a
// forecast (won per month behind, weighted close dates ahead), the person's
// tasks, deals that need attention, valuable customers going cold, top
// customers, compliance and recent activity. Only the panels the company
// switched on (and whose part of the CRM is on) are computed.

require_once __DIR__ . '/Crm.php';
require_once __DIR__ . '/CrmTasks.php';
require_once __DIR__ . '/CrmDeals.php';
require_once __DIR__ . '/CrmActivity.php';
require_once __DIR__ . '/CrmLinked.php';
require_once __DIR__ . '/CrmCompliance.php';

final class CrmOverview
{
    public static function build(PDO $db, int $companyId, int $userId, string $role): array
    {
        $cfg = Crm::config($db, $companyId);
        $on = fn(string $panel) => !in_array($panel, $cfg['overview_off'], true);
        $m = $cfg['modules'];
        $out = ['panels' => []];
        $stages = Crm::stages($db, $companyId);
        $openKeys = Crm::openStageKeys($db, $companyId);

        // ---- counts used by the brief and the key numbers
        $st = $db->prepare("SELECT SUM(type = 'customer') AS customers, SUM(type = 'supplier') AS suppliers,
                                   SUM(type = 'customer' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS new_customers,
                                   SUM(owner_id = ?) AS mine
                              FROM crm_accounts WHERE company_id = ? AND deleted_at IS NULL");
        $st->execute([$userId, $companyId]);
        $acc = array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: []);

        $deals = ['open_count' => 0, 'open_value' => 0.0, 'weighted' => 0.0, 'won_month' => 0.0, 'won_last_month' => 0.0, 'won_n' => 0, 'lost_n' => 0, 'closing_month' => 0, 'closing_value' => 0.0, 'overdue' => 0];
        if ($m['pipeline']) {
            $st = $db->prepare("SELECT
                    SUM(o.stage NOT IN ('won','lost')) AS open_count,
                    COALESCE(SUM(CASE WHEN o.stage NOT IN ('won','lost') THEN o.amount END), 0) AS open_value,
                    COALESCE(SUM(CASE WHEN o.stage NOT IN ('won','lost') THEN o.amount * o.probability / 100 END), 0) AS weighted,
                    COALESCE(SUM(CASE WHEN o.stage = 'won' AND COALESCE(o.stage_changed_at, o.updated_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN o.amount END), 0) AS won_month,
                    COALESCE(SUM(CASE WHEN o.stage = 'won' AND COALESCE(o.stage_changed_at, o.updated_at) >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                                       AND COALESCE(o.stage_changed_at, o.updated_at) < DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN o.amount END), 0) AS won_last_month,
                    SUM(o.stage = 'won' AND COALESCE(o.stage_changed_at, o.updated_at) >= CURDATE() - INTERVAL 12 MONTH) AS won_n,
                    SUM(o.stage = 'lost' AND COALESCE(o.stage_changed_at, o.updated_at) >= CURDATE() - INTERVAL 12 MONTH) AS lost_n,
                    SUM(o.stage NOT IN ('won','lost') AND o.close_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())) AS closing_month,
                    COALESCE(SUM(CASE WHEN o.stage NOT IN ('won','lost') AND o.close_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()) THEN o.amount END), 0) AS closing_value,
                    SUM(o.stage NOT IN ('won','lost') AND o.close_date < CURDATE()) AS overdue
                  FROM crm_opportunities o JOIN crm_accounts a ON a.id = o.account_id AND a.deleted_at IS NULL
                 WHERE o.company_id = ?");
            $st->execute([$companyId]);
            foreach (($st->fetch(PDO::FETCH_ASSOC) ?: []) as $k => $v) $deals[$k] = in_array($k, ['open_count', 'won_n', 'lost_n', 'closing_month', 'overdue'], true) ? (int)$v : round((float)$v, 2);
        }
        $tasks = ['overdue' => 0, 'today' => 0, 'week' => 0];
        if ($m['tasks']) {
            $t = CrmTasks::list($db, $companyId, $userId, ['scope' => 'mine', 'when' => 'open', 'limit' => 1]);
            $tasks = ['overdue' => $t['counts']['overdue'] ?? 0, 'today' => $t['counts']['today'] ?? 0, 'week' => $t['counts']['week'] ?? 0];
        }
        $receivables = ['overdue' => 0.0, 'overdue_count' => 0];
        try {
            $del = isset(CrmSchema::columns($db, 'invoices')['deleted_at']) ? ' AND i.deleted_at IS NULL' : '';
            $rate = isset(CrmSchema::columns($db, 'invoices')['exchange_rate']) ? 'COALESCE(NULLIF(i.exchange_rate, 0), 1)' : '1';
            $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(i.balance_due * $rate), 0) v FROM invoices i
                                 WHERE i.company_id = ? AND i.status IN ('sent','viewed','part-paid','overdue') AND i.balance_due > 0 AND i.due_date < CURDATE()$del");
            $st->execute([$companyId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $receivables = ['overdue' => round((float)$r['v'], 2), 'overdue_count' => (int)$r['n']];
        } catch (Throwable $e) { }
        $compliance = null;
        if ($m['compliance'] && $m['suppliers']) {
            CrmCompliance::refreshStored($db, $companyId);
            $policy = CrmCompliance::policy($db, $companyId);
            $st = $db->prepare("SELECT SUM(d.expiry_date < CURDATE()) AS expired,
                                       SUM(d.expiry_date >= CURDATE() AND d.expiry_date <= CURDATE() + INTERVAL ? DAY) AS expiring, COUNT(*) AS total
                                  FROM crm_compliance_docs d JOIN crm_accounts a ON a.id = d.account_id AND a.deleted_at IS NULL WHERE d.company_id = ?");
            $st->execute([$policy['window'], $companyId]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $compliance = ['expired' => (int)($c['expired'] ?? 0), 'expiring' => (int)($c['expiring'] ?? 0), 'total' => (int)($c['total'] ?? 0), 'window' => $policy['window']];
        }

        // ---- brief
        $brief = [];
        if ($tasks['overdue']) $brief[] = ['tone' => 'danger', 'text' => $tasks['overdue'] . ' overdue ' . ($tasks['overdue'] === 1 ? 'task' : 'tasks'), 'href' => '#tasks?when=overdue'];
        if ($tasks['today']) $brief[] = ['tone' => 'gold', 'text' => $tasks['today'] . ' ' . ($tasks['today'] === 1 ? 'task' : 'tasks') . ' for today', 'href' => '#tasks?when=today'];
        if ($m['pipeline'] && $deals['closing_month']) $brief[] = ['tone' => 'info', 'text' => $deals['closing_month'] . ' ' . ($deals['closing_month'] === 1 ? 'deal' : 'deals') . ' expected to close this month', 'href' => '#pipeline?close=month'];
        if ($m['pipeline'] && $deals['overdue']) $brief[] = ['tone' => 'warning', 'text' => $deals['overdue'] . ' ' . ($deals['overdue'] === 1 ? 'deal is' : 'deals are') . ' past the expected close date', 'href' => '#pipeline?close=overdue'];
        if ($receivables['overdue_count']) $brief[] = ['tone' => 'danger', 'text' => $receivables['overdue_count'] . ' overdue ' . ($receivables['overdue_count'] === 1 ? 'invoice' : 'invoices'), 'href' => '/qi/#invoices?status=overdue'];
        if ($compliance && $compliance['expired']) $brief[] = ['tone' => 'danger', 'text' => $compliance['expired'] . ' expired compliance ' . ($compliance['expired'] === 1 ? 'document' : 'documents'), 'href' => '#compliance'];
        $out['brief'] = $brief;
        $out['counts'] = $acc;

        if ($on('kpis')) {
            $winRate = ($deals['won_n'] + $deals['lost_n']) > 0 ? (int)round(100 * $deals['won_n'] / ($deals['won_n'] + $deals['lost_n'])) : null;
            $kpis = [];
            if ($m['pipeline']) {
                $kpis[] = ['key' => 'pipeline', 'label' => 'Open pipeline', 'value' => $deals['open_value'], 'money' => true, 'note' => $deals['open_count'] . ' open ' . ($deals['open_count'] === 1 ? 'deal' : 'deals'), 'href' => '#pipeline', 'color' => 'var(--gold)'];
                $kpis[] = ['key' => 'weighted', 'label' => 'Weighted forecast', 'value' => $deals['weighted'], 'money' => true, 'note' => 'Value × probability', 'href' => '#pipeline?view=list', 'color' => 'var(--info)'];
                $change = $deals['won_last_month'] > 0 ? (int)round(($deals['won_month'] - $deals['won_last_month']) / $deals['won_last_month'] * 100) : null;
                $kpis[] = ['key' => 'won', 'label' => 'Won this month', 'value' => $deals['won_month'], 'money' => true, 'change' => $change, 'note' => $winRate !== null ? $winRate . '% win rate · 12 months' : 'No closed deals yet', 'href' => '#pipeline?include=won', 'color' => 'var(--success)'];
            }
            if ($m['tasks']) {
                $kpis[] = ['key' => 'tasks', 'label' => 'My tasks', 'value' => $tasks['overdue'] + $tasks['today'], 'money' => false, 'note' => $tasks['overdue'] ? $tasks['overdue'] . ' overdue' : ($tasks['week'] ? $tasks['week'] . ' more this week' : 'All caught up'), 'href' => '#tasks', 'color' => $tasks['overdue'] ? 'var(--danger)' : 'var(--accent)'];
            }
            if (!$m['pipeline'] || count($kpis) < 4) {
                $kpis[] = ['key' => 'customers', 'label' => $cfg['labels']['customers'], 'value' => $acc['customers'] ?? 0, 'money' => false, 'note' => ($acc['new_customers'] ?? 0) . ' new this month', 'href' => '#customers', 'color' => 'var(--accent)'];
            }
            $out['panels']['kpis'] = array_slice($kpis, 0, 4);
        }

        if ($m['pipeline'] && $on('funnel')) {
            $st = $db->prepare("SELECT o.stage, COUNT(*) n, COALESCE(SUM(o.amount), 0) v FROM crm_opportunities o JOIN crm_accounts a ON a.id = o.account_id AND a.deleted_at IS NULL
                                 WHERE o.company_id = ? AND o.stage NOT IN ('won','lost') GROUP BY o.stage");
            $st->execute([$companyId]);
            $by = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['stage']] = ['count' => (int)$r['n'], 'value' => round((float)$r['v'], 2)];
            $funnel = [];
            foreach ($stages as $s) {
                if ($s['kind'] !== 'open') continue;
                $funnel[] = ['key' => $s['key'], 'label' => $s['label'], 'color' => $s['color'], 'probability' => $s['probability']] + ($by[$s['key']] ?? ['count' => 0, 'value' => 0.0]);
            }
            // deals on stages that no longer exist count in the first column
            foreach ($by as $k => $v) {
                if (!in_array($k, $openKeys, true) && $funnel) { $funnel[0]['count'] += $v['count']; $funnel[0]['value'] += $v['value']; }
            }
            $out['panels']['funnel'] = $funnel;
        }

        if ($m['pipeline'] && $on('forecast')) {
            $months = [];
            for ($i = -5; $i <= 3; $i++) {
                $ts = strtotime(date('Y-m-01') . " $i months");
                $months[date('Y-m', $ts)] = ['key' => date('Y-m', $ts), 'label' => date('M', $ts), 'year' => (int)date('Y', $ts), 'future' => $i > 0, 'current' => $i === 0, 'won' => 0.0, 'weighted' => 0.0, 'total' => 0.0];
            }
            $first = array_key_first($months) . '-01';
            $last = date('Y-m-t', strtotime(array_key_last($months) . '-01'));
            $st = $db->prepare("SELECT DATE_FORMAT(COALESCE(o.stage_changed_at, o.updated_at), '%Y-%m') ym, SUM(o.amount) v FROM crm_opportunities o
                                 WHERE o.company_id = ? AND o.stage = 'won' AND COALESCE(o.stage_changed_at, o.updated_at) >= ? GROUP BY ym");
            $st->execute([$companyId, $first]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($months[$r['ym']])) $months[$r['ym']]['won'] = round((float)$r['v'], 2);
            $st = $db->prepare("SELECT DATE_FORMAT(o.close_date, '%Y-%m') ym, SUM(o.amount * o.probability / 100) w, SUM(o.amount) t FROM crm_opportunities o
                                  JOIN crm_accounts a ON a.id = o.account_id AND a.deleted_at IS NULL
                                 WHERE o.company_id = ? AND o.stage NOT IN ('won','lost') AND o.close_date BETWEEN ? AND ? GROUP BY ym");
            $st->execute([$companyId, date('Y-m-01'), $last]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($months[$r['ym']])) { $months[$r['ym']]['weighted'] = round((float)$r['w'], 2); $months[$r['ym']]['total'] = round((float)$r['t'], 2); }
            $out['panels']['forecast'] = array_values($months);
        }

        if ($m['tasks'] && $on('tasks')) {
            $list = CrmTasks::list($db, $companyId, $userId, ['scope' => 'mine', 'when' => 'open', 'limit' => 8]);
            $out['panels']['tasks'] = ['rows' => $list['rows'], 'counts' => $list['counts']];
        }

        if ($m['pipeline'] && $on('attention')) {
            $stale = (int)$cfg['stale_days'];
            $st = $db->prepare("SELECT o.*, a.name AS account_name, NULL AS contact_name FROM crm_opportunities o
                                  JOIN crm_accounts a ON a.id = o.account_id AND a.company_id = o.company_id AND a.deleted_at IS NULL
                                 WHERE o.company_id = ? AND o.stage NOT IN ('won','lost')
                                   AND (o.close_date < CURDATE() OR COALESCE(o.stage_changed_at, o.created_at) < NOW() - INTERVAL ? DAY)
                              ORDER BY (o.close_date < CURDATE()) DESC, o.amount DESC LIMIT 6");
            $st->execute([$companyId, $stale]);
            $out['panels']['attention'] = array_map(function ($o) use ($db, $companyId) {
                $d = CrmDeals::shape($db, $companyId, $o);
                $d['why'] = $d['close_date'] && $d['close_date'] < date('Y-m-d')
                    ? 'Expected to close ' . self::ago($d['close_date'])
                    : 'In ' . $d['stage_label'] . ' for ' . $d['days_in_stage'] . ' days';
                return $d;
            }, $st->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($m['customers'] && $on('cold')) {
            $cut = date('Y-m-d H:i:s', strtotime('-' . $cfg['cold_days'] . ' days'));
            $st = $db->prepare("SELECT a.id, a.name, a.owner_id, x.last_at FROM crm_accounts a
                             LEFT JOIN (SELECT account_id, MAX(created_at) last_at FROM crm_interactions WHERE company_id = ? GROUP BY account_id) x ON x.account_id = a.id
                                 WHERE a.company_id = ? AND a.type = 'customer' AND a.deleted_at IS NULL AND a.status IN ('active','prospect')
                                   AND a.created_at < ? AND COALESCE(x.last_at, '1000-01-01') < ?
                                 LIMIT 300");
            $st->execute([$companyId, $companyId, $cut, $cut]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $money = CrmLinked::money($db, $companyId, 'customer', array_column($rows, 'id'));
            foreach ($rows as &$r) { $r['billed12'] = $money[(int)$r['id']]['billed12'] ?? 0; }
            unset($r);
            usort($rows, fn($a, $b) => $b['billed12'] <=> $a['billed12'] ?: strcmp((string)$a['last_at'], (string)$b['last_at']));
            $out['panels']['cold'] = array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => (string)$r['name'], 'billed12' => (float)$r['billed12'], 'last_at' => $r['last_at'],
                'owner' => $r['owner_id'] ? Crm::personName($db, $companyId, (int)$r['owner_id']) : '',
            ], array_slice($rows, 0, 6));
            $out['panels']['cold_total'] = count($rows);
        }

        if ($m['customers'] && $on('top')) {
            $top = [];
            try {
                $del = isset(CrmSchema::columns($db, 'invoices')['deleted_at']) ? ' AND i.deleted_at IS NULL' : '';
                $rate = isset(CrmSchema::columns($db, 'invoices')['exchange_rate']) ? 'COALESCE(NULLIF(i.exchange_rate, 0), 1)' : '1';
                $st = $db->prepare("SELECT a.id, a.name, SUM(i.total * $rate) billed,
                                           SUM(CASE WHEN i.status IN ('sent','viewed','part-paid','overdue') THEN i.balance_due * $rate ELSE 0 END) balance
                                      FROM invoices i JOIN crm_accounts a ON a.id = i.customer_id AND a.company_id = i.company_id AND a.deleted_at IS NULL
                                     WHERE i.company_id = ? AND i.status NOT IN ('draft','cancelled') AND i.issue_date >= CURDATE() - INTERVAL 12 MONTH$del
                                  GROUP BY a.id, a.name ORDER BY billed DESC LIMIT 6");
                $st->execute([$companyId]);
                $top = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'billed' => round((float)$r['billed'], 2), 'balance' => round((float)$r['balance'], 2)],
                                 $st->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e) { }
            $out['panels']['top'] = $top;
        }

        if ($compliance && $on('compliance')) {
            $ov = CrmCompliance::overview($db, $companyId);
            $out['panels']['compliance'] = ['counts' => $ov['counts'], 'soon' => array_slice($ov['soon'], 0, 6), 'window' => $compliance['window']];
        }

        if ($m['activity'] && $on('activity')) {
            $out['panels']['activity'] = CrmActivity::feed($db, $companyId, $userId, ['limit' => 10])['rows'];
        }
        $out['receivables'] = $receivables;
        $out['deals'] = $deals;
        $out['tasks'] = $tasks;
        return $out;
    }

    private static function ago(string $date): string
    {
        $d = (int)floor((strtotime(date('Y-m-d')) - strtotime($date)) / 86400);
        return $d <= 0 ? 'today' : ($d === 1 ? 'yesterday' : $d . ' days ago');
    }
}
