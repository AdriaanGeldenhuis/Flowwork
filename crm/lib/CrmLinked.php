<?php
// crm/lib/CrmLinked.php
//
// What the rest of Flowwork knows about a customer or supplier:
//   - money: what a customer owes (open invoices) and was billed in the last
//     12 months; what the company owes a supplier (posted bills less payments
//     and vendor credits) and spent with them in the last 12 months,
//   - documents: quotes, invoices, credit notes, recurring invoices, supplier
//     bills, purchase orders and projects linked to an account.
// Every query is company-scoped and tolerant of modules that are not
// installed (missing tables or columns give empty results).

require_once __DIR__ . '/Crm.php';

final class CrmLinked
{
    private const RATE = "COALESCE(NULLIF(i.exchange_rate, 0), 1)";

    /**
     * Money per account for the given ids.
     * @return array<int, array{balance: float, overdue: float, billed12: float, overdue_count: int}>
     */
    public static function money(PDO $db, int $companyId, string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach ($ids as $id) $out[$id] = ['balance' => 0.0, 'overdue' => 0.0, 'billed12' => 0.0, 'overdue_count' => 0];
        if (!$ids) return $out;
        $in = implode(',', $ids);
        if ($type === 'customer') {
            try {
                $rate = self::invoiceRate($db);
                $st = $db->prepare("SELECT i.customer_id AS id,
                        COALESCE(SUM(CASE WHEN i.status IN ('sent','viewed','part-paid','overdue') AND i.balance_due > 0 THEN i.balance_due * $rate END), 0) AS balance,
                        COALESCE(SUM(CASE WHEN i.status IN ('sent','viewed','part-paid','overdue') AND i.balance_due > 0 AND i.due_date < CURDATE() THEN i.balance_due * $rate END), 0) AS overdue,
                        SUM(i.status IN ('sent','viewed','part-paid','overdue') AND i.balance_due > 0 AND i.due_date < CURDATE()) AS overdue_count,
                        COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') AND i.issue_date >= CURDATE() - INTERVAL 12 MONTH THEN i.total * $rate END), 0) AS billed12
                      FROM invoices i
                     WHERE i.company_id = ? AND i.customer_id IN ($in)" . self::liveInvoice($db) . "
                  GROUP BY i.customer_id");
                $st->execute([$companyId]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[(int)$r['id']] = ['balance' => round((float)$r['balance'], 2), 'overdue' => round((float)$r['overdue'], 2),
                                           'billed12' => round((float)$r['billed12'], 2), 'overdue_count' => (int)$r['overdue_count']];
                }
            } catch (Throwable $e) {
                error_log('[crm] customer money: ' . $e->getMessage());
            }
            return $out;
        }
        try {
            $st = $db->prepare("SELECT b.supplier_id AS id,
                    COALESCE(SUM(CASE WHEN b.status IN ('posted','paid') THEN GREATEST(b.total - COALESCE(pa.paid, 0) - COALESCE(vc.cred, 0), 0) END), 0) AS balance,
                    COALESCE(SUM(CASE WHEN b.status IN ('posted','paid') AND b.due_date < CURDATE() THEN GREATEST(b.total - COALESCE(pa.paid, 0) - COALESCE(vc.cred, 0), 0) END), 0) AS overdue,
                    SUM(b.status IN ('posted','paid') AND b.due_date < CURDATE() AND b.total - COALESCE(pa.paid, 0) - COALESCE(vc.cred, 0) > 0.004) AS overdue_count,
                    COALESCE(SUM(CASE WHEN b.status IN ('approved','posted','paid') AND b.issue_date >= CURDATE() - INTERVAL 12 MONTH THEN b.total END), 0) AS billed12
                  FROM ap_bills b
             LEFT JOIN (SELECT bill_id, SUM(amount) AS paid FROM ap_payment_allocations GROUP BY bill_id) pa ON pa.bill_id = b.id
             LEFT JOIN (SELECT bill_id, SUM(amount) AS cred FROM vendor_credit_allocations GROUP BY bill_id) vc ON vc.bill_id = b.id
                 WHERE b.company_id = ? AND b.supplier_id IN ($in)
              GROUP BY b.supplier_id");
            $st->execute([$companyId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['id']] = ['balance' => round((float)$r['balance'], 2), 'overdue' => round((float)$r['overdue'], 2),
                                       'billed12' => round((float)$r['billed12'], 2), 'overdue_count' => (int)$r['overdue_count']];
            }
        } catch (Throwable $e) {
            // Accounts payable not installed: no supplier balances.
        }
        return $out;
    }

    /** SQL expression for an account's balance (for sorting lists). */
    public static function balanceSql(PDO $db, string $type, string $alias = 'a'): string
    {
        if ($type === 'customer') {
            $rate = self::invoiceRate($db);
            return "(SELECT COALESCE(SUM(i.balance_due * $rate), 0) FROM invoices i
                      WHERE i.company_id = $alias.company_id AND i.customer_id = $alias.id
                        AND i.status IN ('sent','viewed','part-paid','overdue') AND i.balance_due > 0" . self::liveInvoice($db) . ")";
        }
        if (!self::hasTable($db, 'ap_bills')) return '0';
        return "(SELECT COALESCE(SUM(b.total), 0) FROM ap_bills b WHERE b.company_id = $alias.company_id AND b.supplier_id = $alias.id AND b.status = 'posted')";
    }

    public static function billedSql(PDO $db, string $type, string $alias = 'a'): string
    {
        if ($type === 'customer') {
            $rate = self::invoiceRate($db);
            return "(SELECT COALESCE(SUM(i.total * $rate), 0) FROM invoices i
                      WHERE i.company_id = $alias.company_id AND i.customer_id = $alias.id
                        AND i.status NOT IN ('draft','cancelled') AND i.issue_date >= CURDATE() - INTERVAL 12 MONTH" . self::liveInvoice($db) . ")";
        }
        if (!self::hasTable($db, 'ap_bills')) return '0';
        return "(SELECT COALESCE(SUM(b.total), 0) FROM ap_bills b WHERE b.company_id = $alias.company_id AND b.supplier_id = $alias.id
                   AND b.status IN ('approved','posted','paid') AND b.issue_date >= CURDATE() - INTERVAL 12 MONTH)";
    }

    /** Revenue per month (12 months, oldest first) for an account: invoiced and collected (customers) or billed (suppliers). */
    public static function series(PDO $db, int $companyId, string $type, int $accountId): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts = strtotime("first day of -{$i} months");
            $months[date('Y-m', $ts)] = ['key' => date('Y-m', $ts), 'label' => date('M', $ts), 'year' => (int)date('Y', $ts), 'billed' => 0.0, 'paid' => 0.0];
        }
        $start = array_key_first($months) . '-01';
        try {
            if ($type === 'customer') {
                $rate = self::invoiceRate($db);
                $st = $db->prepare("SELECT DATE_FORMAT(i.issue_date, '%Y-%m') ym, SUM(i.total * $rate) t FROM invoices i
                                     WHERE i.company_id = ? AND i.customer_id = ? AND i.issue_date >= ? AND i.status NOT IN ('draft','cancelled')" . self::liveInvoice($db) . "
                                  GROUP BY ym");
                $st->execute([$companyId, $accountId, $start]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($months[$r['ym']])) $months[$r['ym']]['billed'] = round((float)$r['t'], 2);
                if (self::hasTable($db, 'payment_allocations')) {
                    $st = $db->prepare("SELECT DATE_FORMAT(p.payment_date, '%Y-%m') ym, SUM(pa.amount * $rate) t
                                          FROM payments p
                                          JOIN payment_allocations pa ON pa.payment_id = p.id
                                          JOIN invoices i ON i.id = pa.invoice_id AND i.company_id = p.company_id
                                         WHERE p.company_id = ? AND i.customer_id = ? AND p.payment_date >= ?" . self::liveInvoice($db) . "
                                      GROUP BY ym");
                    $st->execute([$companyId, $accountId, $start]);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($months[$r['ym']])) $months[$r['ym']]['paid'] = round((float)$r['t'], 2);
                }
            } elseif (self::hasTable($db, 'ap_bills')) {
                $st = $db->prepare("SELECT DATE_FORMAT(issue_date, '%Y-%m') ym, SUM(total) t FROM ap_bills
                                     WHERE company_id = ? AND supplier_id = ? AND issue_date >= ? AND status IN ('approved','posted','paid') GROUP BY ym");
                $st->execute([$companyId, $accountId, $start]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($months[$r['ym']])) $months[$r['ym']]['billed'] = round((float)$r['t'], 2);
            }
        } catch (Throwable $e) {
            error_log('[crm] series: ' . $e->getMessage());
        }
        return array_values($months);
    }

    /** Lifetime totals for the account header. */
    public static function lifetime(PDO $db, int $companyId, string $type, int $accountId): array
    {
        $out = ['total' => 0.0, 'count' => 0, 'first' => null, 'last' => null];
        try {
            if ($type === 'customer') {
                $rate = self::invoiceRate($db);
                $st = $db->prepare("SELECT COALESCE(SUM(i.total * $rate), 0) t, COUNT(*) n, MIN(i.issue_date) f, MAX(i.issue_date) l FROM invoices i
                                     WHERE i.company_id = ? AND i.customer_id = ? AND i.status NOT IN ('draft','cancelled')" . self::liveInvoice($db));
            } elseif (self::hasTable($db, 'ap_bills')) {
                $st = $db->prepare("SELECT COALESCE(SUM(total), 0) t, COUNT(*) n, MIN(issue_date) f, MAX(issue_date) l FROM ap_bills
                                     WHERE company_id = ? AND supplier_id = ? AND status IN ('approved','posted','paid')");
            } else {
                return $out;
            }
            $st->execute([$companyId, $accountId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out = ['total' => round((float)($r['t'] ?? 0), 2), 'count' => (int)($r['n'] ?? 0), 'first' => $r['f'] ?? null, 'last' => $r['l'] ?? null];
        } catch (Throwable $e) {
            error_log('[crm] lifetime: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Documents linked to an account, newest first, per kind.
     * @return array{quotes: array, invoices: array, credit_notes: array, recurring: array, bills: array, purchase_orders: array, projects: array}
     */
    public static function documents(PDO $db, int $companyId, int $userId, string $role, array $account): array
    {
        $id = (int)$account['id'];
        $out = ['quotes' => [], 'invoices' => [], 'credit_notes' => [], 'recurring' => [], 'bills' => [], 'purchase_orders' => [], 'projects' => []];
        $qiDoc = is_file(__DIR__ . '/../../qi/lib/QiDocument.php');
        if ($qiDoc) require_once __DIR__ . '/../../qi/lib/QiDocument.php';
        $sym = function ($cur) {
            $cur = strtoupper((string)($cur ?: 'ZAR'));
            if (class_exists('Currencies') && method_exists('Currencies', 'symbol')) return Currencies::symbol($cur);
            return $cur === 'ZAR' ? 'R' : $cur;
        };
        if (is_file(__DIR__ . '/../../qi/lib/Currencies.php')) require_once __DIR__ . '/../../qi/lib/Currencies.php';

        // Quotes, invoices and credit notes go to anyone's account page; the
        // Quotes & Invoices pages themselves enforce who may change them.
        try {
            $st = $db->prepare("SELECT * FROM quotes WHERE company_id = ? AND customer_id = ? ORDER BY issue_date DESC, id DESC LIMIT 100");
            $st->execute([$companyId, $id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $out['quotes'][] = ['id' => (int)$d['id'], 'number' => $d['quote_number'], 'date' => $d['issue_date'], 'due' => $d['expiry_date'],
                    'total' => (float)$d['total'], 'symbol' => $sym($d['currency'] ?? 'ZAR'), 'currency' => $d['currency'] ?? 'ZAR',
                    'status' => $qiDoc ? QiDocument::status('quote', $d) : ['key' => $d['status'], 'label' => ucfirst((string)$d['status']), 'tone' => 'muted'],
                    'url' => '/qi/view.php?type=quote&id=' . (int)$d['id']];
            }
        } catch (Throwable $e) { }
        try {
            $st = $db->prepare("SELECT i.* FROM invoices i WHERE i.company_id = ? AND i.customer_id = ?" . self::liveInvoice($db) . " ORDER BY i.issue_date DESC, i.id DESC LIMIT 200");
            $st->execute([$companyId, $id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $out['invoices'][] = ['id' => (int)$d['id'], 'number' => $d['invoice_number'], 'date' => $d['issue_date'], 'due' => $d['due_date'],
                    'total' => (float)$d['total'], 'balance' => (float)$d['balance_due'], 'symbol' => $sym($d['currency'] ?? 'ZAR'), 'currency' => $d['currency'] ?? 'ZAR',
                    'archived' => !empty($d['archived_at']),
                    'status' => $qiDoc ? QiDocument::status('invoice', $d) : ['key' => $d['status'], 'label' => ucfirst((string)$d['status']), 'tone' => 'muted'],
                    'url' => '/qi/view.php?type=invoice&id=' . (int)$d['id']];
            }
        } catch (Throwable $e) { }
        try {
            $st = $db->prepare("SELECT * FROM credit_notes WHERE company_id = ? AND customer_id = ? ORDER BY issue_date DESC, id DESC LIMIT 100");
            $st->execute([$companyId, $id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $out['credit_notes'][] = ['id' => (int)$d['id'], 'number' => $d['credit_note_number'], 'date' => $d['issue_date'], 'due' => null,
                    'total' => (float)$d['total'], 'symbol' => $sym($d['currency'] ?? 'ZAR'), 'currency' => $d['currency'] ?? 'ZAR',
                    'status' => $qiDoc ? QiDocument::status('credit_note', $d) : ['key' => $d['status'], 'label' => ucfirst((string)$d['status']), 'tone' => 'muted'],
                    'url' => '/qi/view.php?type=credit_note&id=' . (int)$d['id']];
            }
        } catch (Throwable $e) { }
        try {
            $st = $db->prepare("SELECT * FROM recurring_invoices WHERE company_id = ? AND customer_id = ? ORDER BY id DESC LIMIT 50");
            $st->execute([$companyId, $id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ended = !empty($r['end_date']) && $r['end_date'] >= '1000-01-02' && $r['end_date'] < date('Y-m-d');
                $out['recurring'][] = ['id' => (int)$r['id'], 'number' => (string)($r['template_name'] ?: 'Recurring invoice #' . $r['id']),
                    'date' => $r['next_run_date'] ?? null, 'frequency' => ucfirst((string)($r['frequency'] ?? '')),
                    'status' => !$r['active'] ? ['key' => 'paused', 'label' => 'Paused', 'tone' => 'muted'] : ($ended ? ['key' => 'ended', 'label' => 'Ended', 'tone' => 'muted'] : ['key' => 'active', 'label' => 'Active', 'tone' => 'success']),
                    'url' => '/qi/edit.php?type=recurring&id=' . (int)$r['id']];
            }
        } catch (Throwable $e) { }

        // Supplier bills: Finance roles only (the bill pages enforce the same).
        if (in_array(strtolower($role), ['admin', 'bookkeeper', 'viewer', 'owner'], true)) {
            try {
                $st = $db->prepare("SELECT b.*, GREATEST(b.total - COALESCE(pa.paid, 0) - COALESCE(vc.cred, 0), 0) AS balance FROM ap_bills b
                                 LEFT JOIN (SELECT bill_id, SUM(amount) AS paid FROM ap_payment_allocations GROUP BY bill_id) pa ON pa.bill_id = b.id
                                 LEFT JOIN (SELECT bill_id, SUM(amount) AS cred FROM vendor_credit_allocations GROUP BY bill_id) vc ON vc.bill_id = b.id
                                     WHERE b.company_id = ? AND b.supplier_id = ? ORDER BY b.issue_date DESC, b.id DESC LIMIT 200");
                $st->execute([$companyId, $id]);
                $tones = ['draft' => 'muted', 'review' => 'warning', 'approved' => 'info', 'posted' => 'info', 'paid' => 'success', 'blocked' => 'danger'];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $status = (string)$b['status'];
                    $balance = in_array($status, ['posted', 'paid'], true) ? (float)$b['balance'] : 0.0;
                    if ($status === 'posted' && $balance <= 0.004) $status = 'paid';
                    $late = in_array($status, ['posted'], true) && $balance > 0.004 && !empty($b['due_date']) && $b['due_date'] < date('Y-m-d');
                    $out['bills'][] = ['id' => (int)$b['id'], 'number' => (string)($b['vendor_invoice_number'] ?: 'Bill #' . $b['id']), 'date' => $b['issue_date'],
                        'due' => $b['due_date'], 'total' => (float)$b['total'], 'balance' => $balance, 'symbol' => $sym($b['currency'] ?? 'ZAR'), 'currency' => $b['currency'] ?? 'ZAR',
                        'status' => $late ? ['key' => 'overdue', 'label' => 'Overdue', 'tone' => 'danger'] : ['key' => $status, 'label' => $status === 'posted' ? 'Unpaid' : ucfirst($status), 'tone' => $tones[$status] ?? 'muted'],
                        'url' => '/finances/#payables?status=all&id=' . (int)$b['id']];
                }
            } catch (Throwable $e) { }
        }
        try {
            $st = $db->prepare("SELECT * FROM purchase_orders WHERE company_id = ? AND supplier_id = ? ORDER BY created_at DESC, id DESC LIMIT 100");
            $st->execute([$companyId, $id]);
            $tones = ['draft' => 'muted', 'approved' => 'info', 'partial' => 'warning', 'complete' => 'success', 'cancelled' => 'muted'];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out['purchase_orders'][] = ['id' => (int)$p['id'], 'number' => $p['po_number'], 'date' => substr((string)$p['created_at'], 0, 10),
                    'total' => (float)$p['total'], 'symbol' => 'R', 'currency' => 'ZAR',
                    'status' => ['key' => $p['status'], 'label' => $p['status'] === 'partial' ? 'Part received' : ucfirst((string)$p['status']), 'tone' => $tones[$p['status']] ?? 'muted'],
                    'url' => '/procurement/po_view.php?id=' . (int)$p['id']];
            }
        } catch (Throwable $e) { }

        // Projects: only the ones this person may open.
        try {
            if (is_file(__DIR__ . '/../../projects/lib/autoload.php')) {
                require_once __DIR__ . '/../../projects/lib/autoload.php';
                $repo = ProjectLink::repo($db, $companyId, $userId, $role);
                [$visibleSql, $visibleParams] = array_pad((array)$repo->visibleSql('p'), 2, []);
                $st = $db->prepare("SELECT p.project_id, p.project_number, p.name, p.status, p.archived, p.budget, p.progress_percent, p.end_date, p.created_at
                                      FROM projects p WHERE p.company_id = ? AND p.client_id = ? AND $visibleSql ORDER BY p.created_at DESC LIMIT 50");
                $st->execute(array_merge([$companyId, $id], is_array($visibleParams) ? $visibleParams : []));
                $tones = ['draft' => 'muted', 'active' => 'info', 'on_hold' => 'warning', 'completed' => 'success', 'archived' => 'muted', 'cancelled' => 'muted'];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $status = !empty($p['archived']) ? 'archived' : (string)$p['status'];
                    $out['projects'][] = ['id' => (int)$p['project_id'], 'number' => (string)($p['project_number'] ?: ''), 'name' => $p['name'],
                        'date' => substr((string)$p['created_at'], 0, 10), 'due' => $p['end_date'], 'total' => (float)$p['budget'], 'symbol' => 'R', 'currency' => 'ZAR',
                        'progress' => (int)$p['progress_percent'],
                        'status' => ['key' => $status, 'label' => ProjectRepo::STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status)), 'tone' => $tones[$status] ?? 'muted'],
                        'url' => ProjectLink::projectUrl((int)$p['project_id'])];
                }
            }
        } catch (Throwable $e) {
            error_log('[crm] account projects: ' . $e->getMessage());
        }
        return $out;
    }

    /** Count of documents that tie an account to the books (blocks permanent deletion). */
    public static function financialLinks(PDO $db, int $companyId, int $accountId): int
    {
        $n = 0;
        foreach ([
            "SELECT COUNT(*) FROM quotes WHERE company_id = ? AND customer_id = ?",
            "SELECT COUNT(*) FROM invoices WHERE company_id = ? AND customer_id = ?",
            "SELECT COUNT(*) FROM credit_notes WHERE company_id = ? AND customer_id = ?",
            "SELECT COUNT(*) FROM recurring_invoices WHERE company_id = ? AND customer_id = ?",
            "SELECT COUNT(*) FROM ap_bills WHERE company_id = ? AND supplier_id = ?",
            "SELECT COUNT(*) FROM vendor_credits WHERE company_id = ? AND supplier_id = ?",
            "SELECT COUNT(*) FROM purchase_orders WHERE company_id = ? AND supplier_id = ?",
            "SELECT COUNT(*) FROM projects WHERE company_id = ? AND client_id = ?",
        ] as $sql) {
            try {
                $st = $db->prepare($sql);
                $st->execute([$companyId, $accountId]);
                $n += (int)$st->fetchColumn();
            } catch (Throwable $e) {
                // table not installed
            }
        }
        return $n;
    }

    private static function invoiceRate(PDO $db): string
    {
        static $has = null;
        if ($has === null) $has = isset(CrmSchema::columns($db, 'invoices')['exchange_rate']);
        return $has ? self::RATE : '1';
    }

    /** Live invoices: not deleted (archived invoices still count, as in Quotes & Invoices). */
    private static function liveInvoice(PDO $db): string
    {
        static $cols = null;
        if ($cols === null) $cols = CrmSchema::columns($db, 'invoices');
        return isset($cols['deleted_at']) ? ' AND i.deleted_at IS NULL' : '';
    }

    public static function hasTable(PDO $db, string $table): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) $cache[$table] = (bool)CrmSchema::columns($db, $table);
        return $cache[$table];
    }
}
