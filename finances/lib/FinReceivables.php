<?php
// finances/lib/FinReceivables.php
//
// Receivables: what customers owe. Invoices and credit notes are written in
// Quotes & Invoices; Finance shows the money side — open and late invoices,
// customers with their balances, aging as at any date, statements, and
// recording receipts. Receipts go through QiActions::recordPayment, so a
// payment recorded here is exactly the same as one recorded on the invoice
// (posted Dr bank / Cr receivables, invoice balance and status updated,
// FX difference booked) — Finance never posts AR on its own.
//
// Rules shared with the tested dashboard and tie-out:
//   - open = issued, not deleted, not draft / paid / cancelled / written off /
//     uncollectible / refunded, balance_due > 0; ZAR at
//     COALESCE(NULLIF(exchange_rate,0),1),
//   - "late" is derived from the due date (the stored 'overdue' status is
//     not maintained anywhere),
//   - aging as at a date rebuilds each invoice's balance from payments,
//     posted credit notes and write-offs dated on or before that date.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/FinOverview.php';

final class FinReceivables
{
    private const RATE = "COALESCE(NULLIF(i.exchange_rate,0),1)";

    /** $f: status (open|overdue|due|paid|all), customer, q, from, to (issue date), page, sort (due|issued|balance|number), dir */
    public static function invoices(PDO $db, int $companyId, array $f, int $soonDays = 7): array
    {
        $today = date('Y-m-d');
        $where = ["i.company_id = ?", "i.deleted_at IS NULL", "i.status <> 'draft'"];
        $args = [$companyId];
        if ((int)($f['customer'] ?? 0)) { $where[] = 'i.customer_id = ?'; $args[] = (int)$f['customer']; }
        if ($d = Fin::ymd($f['from'] ?? '')) { $where[] = 'i.issue_date >= ?'; $args[] = $d; }
        if ($d = Fin::ymd($f['to'] ?? '')) { $where[] = 'i.issue_date <= ?'; $args[] = $d; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . Fin::likeEscape($q) . '%';
            $c = preg_match('/^[R\s\-\d,.]+$/i', $q) ? Fin::cents($q) : null;
            $where[] = "(i.invoice_number LIKE ? OR i.reference LIKE ? OR ca.name LIKE ?" . ($c ? " OR ROUND(i.total * 100) = ? OR ROUND(i.balance_due * 100) = ?" : '') . ')';
            array_push($args, $like, $like, $like);
            if ($c) array_push($args, abs($c), abs($c));
        }
        $open = "i.status NOT IN " . FinOverview::AR_CLOSED . " AND i.balance_due > 0";
        $base = implode(' AND ', $where);
        $soon = date('Y-m-d', strtotime("+$soonDays days"));
        $st = $db->prepare("SELECT SUM($open) o, SUM($open AND i.due_date < ?) l, SUM($open AND i.due_date BETWEEN ? AND ?) d, SUM(i.status = 'paid') p, COUNT(*) n
                              FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id WHERE $base");
        $st->execute(array_merge([$today, $today, $soon], $args));
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = ['open' => (int)$c['o'], 'overdue' => (int)$c['l'], 'due' => (int)$c['d'], 'paid' => (int)$c['p'], 'all' => (int)$c['n']];
        $status = (string)($f['status'] ?? 'open');
        if ($status === 'open') $where[] = $open;
        elseif ($status === 'overdue') { $where[] = "$open AND i.due_date < ?"; $args[] = $today; }
        elseif ($status === 'due') { $where[] = "$open AND i.due_date BETWEEN ? AND ?"; $args[] = $today; $args[] = $soon; }
        elseif ($status === 'paid') $where[] = "i.status = 'paid'";
        $sql = implode(' AND ', $where);
        $dir = strtolower((string)($f['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
        $order = ['due' => "i.due_date $dir, i.id", 'issued' => "i.issue_date $dir, i.id", 'balance' => "bal $dir, i.id", 'number' => "i.invoice_number $dir"][$f['sort'] ?? 'due'] ?? 'i.due_date ASC';
        $per = 50;
        $page = max(1, (int)($f['page'] ?? 1));
        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(i.balance_due * " . self::RATE . "), 0) FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id WHERE $sql");
        $st->execute($args);
        [$total, $sum] = $st->fetch(PDO::FETCH_NUM);
        $st = $db->prepare("SELECT i.id, i.invoice_number, i.customer_id, i.issue_date, i.due_date, i.status, i.total, i.balance_due, i.currency,
                                   i.balance_due * " . self::RATE . " bal, i.journal_id, i.sent_at, i.viewed_at, COALESCE(ca.name, '(deleted customer)') customer,
                                   (SELECT MAX(p.payment_date) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE pa.invoice_id = i.id) last_paid
                              FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
                             WHERE $sql ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($args);
        $rows = array_map(function ($r) use ($today) {
            $late = $r['due_date'] && $r['due_date'] < $today && (float)$r['balance_due'] > 0 && !in_array($r['status'], ['paid', 'cancelled', 'written_off', 'uncollectible', 'refunded'], true);
            return ['id' => (int)$r['id'], 'number' => (string)$r['invoice_number'], 'customer_id' => (int)$r['customer_id'], 'customer' => (string)$r['customer'],
                    'issued' => $r['issue_date'], 'due' => $r['due_date'], 'status' => $late ? 'overdue' : (string)$r['status'],
                    'total' => round((float)$r['total'], 2), 'balance' => round((float)$r['balance_due'], 2), 'balance_zar' => round((float)$r['bal'], 2),
                    'currency' => (string)($r['currency'] ?: 'ZAR'), 'days_late' => $late ? (int)((strtotime($today) - strtotime($r['due_date'])) / 86400) : 0,
                    'posted' => (bool)$r['journal_id'], 'sent' => $r['sent_at'], 'viewed' => $r['viewed_at'], 'last_paid' => $r['last_paid']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => (int)$total, 'sum' => round((float)$sum, 2), 'page' => $page, 'per' => $per, 'counts' => $counts];
    }

    /** Customers with something open, worst first. */
    public static function customers(PDO $db, int $companyId, string $q = ''): array
    {
        $today = date('Y-m-d');
        $args = [$today, $today, $companyId];
        $like = '';
        if (trim($q) !== '') { $like = ' AND ca.name LIKE ?'; $args[] = '%' . Fin::likeEscape(trim($q)) . '%'; }
        $st = $db->prepare("SELECT ca.id, ca.name, ca.email, ca.phone, COUNT(i.id) n,
                                   COALESCE(SUM(i.balance_due * " . self::RATE . "), 0) bal,
                                   COALESCE(SUM(CASE WHEN i.due_date < ? THEN i.balance_due * " . self::RATE . " END), 0) late,
                                   MAX(DATEDIFF(?, i.due_date)) worst, MIN(i.due_date) oldest_due
                              FROM invoices i JOIN crm_accounts ca ON ca.id = i.customer_id
                             WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.status NOT IN " . FinOverview::AR_CLOSED . " AND i.balance_due > 0$like
                          GROUP BY ca.id, ca.name, ca.email, ca.phone ORDER BY late DESC, bal DESC LIMIT 300");
        $st->execute($args);
        $pay = $db->prepare("SELECT MAX(p.payment_date) FROM payments p JOIN payment_allocations pa ON pa.payment_id = p.id JOIN invoices i ON i.id = pa.invoice_id
                              WHERE p.company_id = ? AND i.customer_id = ?");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pay->execute([$companyId, (int)$r['id']]);
            $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'email' => (string)($r['email'] ?? ''), 'phone' => (string)($r['phone'] ?? ''),
                      'invoices' => (int)$r['n'], 'balance' => round((float)$r['bal'], 2), 'late' => round((float)$r['late'], 2),
                      'worst_days' => max(0, (int)$r['worst']), 'last_paid' => $pay->fetchColumn() ?: null];
        }
        return $out;
    }

    /** Aging as at a date, per customer (ZAR). */
    public static function aging(PDO $db, int $companyId, string $asOf): array
    {
        $st = $db->prepare("
            SELECT i.id, i.customer_id, i.due_date, i.invoice_number,
                   (i.total - COALESCE(pay.t, 0) - COALESCE(cr.t, 0)
                     - CASE WHEN i.write_off_at IS NOT NULL AND DATE(i.write_off_at) <= ? THEN COALESCE(i.write_off_amount, 0) ELSE 0 END) * " . self::RATE . " bal
              FROM invoices i
         LEFT JOIN (SELECT pa.invoice_id, SUM(pa.amount) t FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                     WHERE p.company_id = ? AND p.payment_date <= ? GROUP BY pa.invoice_id) pay ON pay.invoice_id = i.id
         LEFT JOIN (SELECT cna.invoice_id, SUM(cna.amount) t FROM credit_note_allocations cna JOIN credit_notes cn ON cn.id = cna.credit_note_id
                     WHERE cna.company_id = ? AND cn.issue_date <= ? AND cn.status NOT IN ('draft','cancelled') AND cn.journal_id IS NOT NULL GROUP BY cna.invoice_id) cr ON cr.invoice_id = i.id
             WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.issue_date <= ?
               AND i.status NOT IN ('draft','cancelled','uncollectible','refunded')
               AND (i.status <> 'written_off' OR i.write_off_at IS NULL OR DATE(i.write_off_at) > ?)");
        $st->execute([$asOf, $companyId, $asOf, $companyId, $asOf, $companyId, $asOf, $asOf]);
        return self::bucketise($db, $companyId, $st->fetchAll(PDO::FETCH_ASSOC), $asOf, 'customer_id');
    }

    /** Group invoice / bill balances into aging buckets per party. */
    public static function bucketise(PDO $db, int $companyId, array $rows, string $asOf, string $partyKey): array
    {
        $parties = [];
        $tot = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0];
        $asOfTs = strtotime($asOf);
        foreach ($rows as $r) {
            $bal = (int)round((float)$r['bal'] * 100);
            if ($bal <= 0) continue;
            $pid = (int)$r[$partyKey];
            $days = $r['due_date'] ? (int)floor(($asOfTs - strtotime($r['due_date'])) / 86400) : 0;
            $b = $days <= 0 ? 'current' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'd90p')));
            if (!isset($parties[$pid])) $parties[$pid] = ['id' => $pid, 'name' => '', 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0, 'docs' => 0];
            $parties[$pid][$b] += $bal;
            $parties[$pid]['total'] += $bal;
            $parties[$pid]['docs']++;
            $tot[$b] += $bal;
            $tot['total'] += $bal;
        }
        if ($parties) {
            $ids = array_keys($parties);
            $st = $db->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute(array_merge([$companyId], $ids));
            $names = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($parties as $id => &$p) $p['name'] = (string)($names[$id] ?? '(deleted)');
            unset($p);
        }
        $toRand = function (array $x) { foreach (['current', 'd30', 'd60', 'd90', 'd90p', 'total'] as $k) $x[$k] = $x[$k] / 100; return $x; };
        $list = array_map($toRand, array_values($parties));
        usort($list, fn($a, $b) => $b['total'] <=> $a['total']);
        return ['as_of' => $asOf, 'rows' => $list, 'totals' => $toRand($tot)];
    }

    /**
     * A customer statement: opening balance, every invoice, receipt, refund, credit note and
     * write-off in the period, running balance. In the customer's currency when they are
     * billed in one foreign currency only, otherwise in rand.
     */
    public static function statement(PDO $db, int $companyId, int $customerId, string $from, string $to): array
    {
        $st = $db->prepare("SELECT id, name, email FROM crm_accounts WHERE id = ? AND company_id = ?");
        $st->execute([$customerId, $companyId]);
        $cust = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cust) throw new FinUserError('That customer was not found.');
        $st = $db->prepare("SELECT DISTINCT COALESCE(NULLIF(currency, ''), 'ZAR') FROM invoices WHERE company_id = ? AND customer_id = ? AND deleted_at IS NULL AND status NOT IN ('draft','cancelled')");
        $st->execute([$companyId, $customerId]);
        $curs = $st->fetchAll(PDO::FETCH_COLUMN);
        $own = count($curs) === 1 ? (string)$curs[0] : 'ZAR';
        $fx = $own === 'ZAR' ? 'COALESCE(NULLIF(i.exchange_rate,0),1)' : '1';
        $fxc = $own === 'ZAR' ? 'COALESCE(NULLIF(cn.exchange_rate,0),1)' : '1';
        $sql = "SELECT d, sort_k, kind, ref, descr, debit, credit FROM (
                  SELECT i.issue_date d, 1 sort_k, 'invoice' kind, i.invoice_number ref, CONCAT('Invoice ', i.invoice_number) descr, i.total * $fx debit, 0 credit
                    FROM invoices i WHERE i.company_id = ? AND i.customer_id = ? AND i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
                  UNION ALL
                  SELECT p.payment_date, 2, 'payment', COALESCE(NULLIF(p.reference, ''), CONCAT('Receipt ', p.id)), CONCAT('Payment · ', i.invoice_number), 0, pa.amount * $fx
                    FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id JOIN invoices i ON i.id = pa.invoice_id
                   WHERE p.company_id = ? AND i.customer_id = ? AND i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled')
                  UNION ALL
                  SELECT DATE(p.refunded_at), 3, 'refund', COALESCE(NULLIF(p.refund_reference, ''), CONCAT('Refund ', p.id)), CONCAT('Refund · ', i.invoice_number), pa.amount * $fx, 0
                    FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id JOIN invoices i ON i.id = pa.invoice_id
                   WHERE p.company_id = ? AND i.customer_id = ? AND p.refunded_at IS NOT NULL AND i.deleted_at IS NULL
                  UNION ALL
                  SELECT cn.issue_date, 4, 'credit_note', cn.credit_note_number, CONCAT('Credit note ', cn.credit_note_number), 0, cn.total * $fxc
                    FROM credit_notes cn WHERE cn.company_id = ? AND cn.customer_id = ? AND cn.status NOT IN ('draft','cancelled') AND cn.journal_id IS NOT NULL
                  UNION ALL
                  SELECT DATE(i.write_off_at), 5, 'write_off', i.invoice_number, CONCAT('Written off · ', i.invoice_number), 0, i.write_off_amount * $fx
                    FROM invoices i WHERE i.company_id = ? AND i.customer_id = ? AND i.write_off_at IS NOT NULL AND COALESCE(i.write_off_amount, 0) > 0 AND i.deleted_at IS NULL
                ) t ORDER BY d, sort_k, ref";
        $st = $db->prepare($sql);
        $st->execute([$companyId, $customerId, $companyId, $customerId, $companyId, $customerId, $companyId, $customerId, $companyId, $customerId]);
        $open = 0;
        $lines = [];
        $run = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dC = (int)round((float)$r['debit'] * 100);
            $cC = (int)round((float)$r['credit'] * 100);
            if ($r['d'] < $from) { $open += $dC - $cC; continue; }
            if ($r['d'] > $to) continue;
            if (!$lines) $run = $open;
            $run += $dC - $cC;
            $lines[] = ['date' => $r['d'], 'kind' => (string)$r['kind'], 'reference' => (string)$r['ref'], 'description' => (string)$r['descr'], 'debit' => $dC / 100, 'credit' => $cC / 100, 'balance' => $run / 100];
        }
        if (!$lines) $run = $open;
        $aging = self::aging($db, $companyId, $to);
        $mine = array_values(array_filter($aging['rows'], fn($r) => $r['id'] === $customerId))[0] ?? null;
        return ['customer' => ['id' => (int)$cust['id'], 'name' => (string)$cust['name'], 'email' => (string)($cust['email'] ?? '')],
                'currency' => $own, 'from' => $from, 'to' => $to, 'opening' => $open / 100, 'closing' => $run / 100, 'lines' => $lines, 'aging' => $own === 'ZAR' ? $mine : null];
    }

    /** One invoice's money side: amounts, payments, credit notes, journals, reminders sent. */
    public static function invoice(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT i.*, COALESCE(ca.name, '(deleted customer)') customer FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id
                             WHERE i.id = ? AND i.company_id = ? AND i.deleted_at IS NULL");
        $st->execute([$id, $companyId]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) throw new FinUserError('That invoice was not found.');
        $st = $db->prepare("SELECT p.id, p.payment_date, p.method, p.reference, pa.amount, p.journal_id, p.refunded_at FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                             WHERE pa.invoice_id = ? AND p.company_id = ? ORDER BY p.payment_date, p.id");
        $st->execute([$id, $companyId]);
        $pays = array_map(fn($p) => ['id' => (int)$p['id'], 'date' => $p['payment_date'], 'method' => (string)$p['method'], 'reference' => (string)($p['reference'] ?? ''),
                                     'amount' => round((float)$p['amount'], 2), 'journal_id' => $p['journal_id'] ? (int)$p['journal_id'] : null, 'refunded' => (bool)$p['refunded_at']], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT cn.id, cn.credit_note_number, cn.issue_date, cna.amount, cn.status FROM credit_note_allocations cna JOIN credit_notes cn ON cn.id = cna.credit_note_id
                             WHERE cna.invoice_id = ? AND cna.company_id = ?");
        $credits = [];
        try { $st->execute([$id, $companyId]); $credits = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $credits = []; }
        $st = $db->prepare("SELECT id, entry_date, reference, status, reversed_by_journal_id, reverses_journal_id FROM journal_entries
                             WHERE company_id = ? AND ((ref_type IN ('invoice','invoice_write_off') AND ref_id = ?) OR (source_type = 'invoice' AND source_id = ?)) ORDER BY id");
        $st->execute([$companyId, $id, $id]);
        $journals = array_map(fn($j) => ['id' => (int)$j['id'], 'date' => $j['entry_date'], 'reference' => (string)$j['reference'], 'status' => FinJournalsStatus::of($j)], $st->fetchAll(PDO::FETCH_ASSOC));
        $mails = [];
        try {
            $st = $db->prepare("SELECT entity_type kind, to_email recipient, status, sent_at created_at FROM qi_email_log WHERE company_id = ? AND entity_type IN ('invoice','reminder') AND entity_id = ? ORDER BY id DESC LIMIT 10");
            $st->execute([$companyId, $id]);
            $mails = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $mails = [];
        }
        $today = date('Y-m-d');
        $late = $i['due_date'] && $i['due_date'] < $today && (float)$i['balance_due'] > 0 && !in_array($i['status'], ['paid', 'cancelled', 'written_off', 'uncollectible', 'refunded', 'draft'], true);
        return [
            'invoice' => ['id' => (int)$i['id'], 'number' => (string)$i['invoice_number'], 'customer_id' => (int)$i['customer_id'], 'customer' => (string)$i['customer'],
                          'issued' => $i['issue_date'], 'due' => $i['due_date'], 'status' => $late ? 'overdue' : (string)$i['status'], 'currency' => (string)($i['currency'] ?: 'ZAR'),
                          'subtotal' => round((float)$i['subtotal'], 2), 'tax' => round((float)$i['tax'], 2), 'total' => round((float)$i['total'], 2), 'balance' => round((float)$i['balance_due'], 2),
                          'rate' => (float)($i['exchange_rate'] ?: 1), 'written_off' => round((float)($i['write_off_amount'] ?? 0), 2),
                          'days_late' => $late ? (int)((strtotime($today) - strtotime($i['due_date'])) / 86400) : 0, 'payable' => in_array($i['status'], ['sent', 'viewed', 'part-paid', 'overdue'], true) && (float)$i['balance_due'] > 0],
            'payments' => $pays,
            'credits' => array_map(fn($c) => ['id' => (int)$c['id'], 'number' => (string)$c['credit_note_number'], 'date' => $c['issue_date'], 'amount' => round((float)$c['amount'], 2)], $credits),
            'journals' => $journals,
            'emails' => array_map(fn($m) => ['kind' => (string)$m['kind'], 'to' => (string)$m['recipient'], 'status' => (string)$m['status'], 'at' => $m['created_at']], $mails),
            'qi_url' => '/qi/view.php?type=invoice&id=' . (int)$i['id'],
        ];
    }

    /**
     * Record money received against one or more invoices of a customer. $in: customer_id, date, method, reference,
     * bank_account_id?, allocations[{invoice_id, amount}], key (idempotency). Each invoice gets its receipt through Quotes & Invoices.
     */
    public static function receive(PDO $db, int $companyId, int $userId, string $role, array $in): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        require_once dirname(__DIR__, 2) . '/qi/lib/QiSchema.php';
        require_once dirname(__DIR__, 2) . '/qi/lib/QiActions.php';
        QiSchema::ensure($db);
        $cust = (int)($in['customer_id'] ?? 0);
        $date = Fin::ymd($in['date'] ?? '') ?: date('Y-m-d');
        $key = substr(preg_replace('/[^A-Za-z0-9:._-]/', '', (string)($in['key'] ?? '')), 0, 40);
        $allocs = [];
        foreach ((array)($in['allocations'] ?? []) as $a) {
            $inv = (int)($a['invoice_id'] ?? 0);
            $c = Fin::cents($a['amount'] ?? '') ?? 0;
            if ($inv && $c > 0) $allocs[$inv] = ($allocs[$inv] ?? 0) + $c;
        }
        if (!$allocs) throw new FinUserError('Enter the amount received against at least one invoice.');
        $bank = (int)($in['bank_account_id'] ?? 0) ?: null;
        if ($bank) {
            $st = $db->prepare("SELECT 1 FROM gl_bank_accounts WHERE id = ? AND company_id = ? AND is_active = 1");
            $st->execute([$bank, $companyId]);
            if (!$st->fetchColumn()) throw new FinUserError('Choose one of your bank accounts.');
        }
        $st = $db->prepare("SELECT id, customer_id, invoice_number FROM invoices WHERE company_id = ? AND id IN (" . implode(',', array_fill(0, count($allocs), '?')) . ")");
        $st->execute(array_merge([$companyId], array_keys($allocs)));
        $found = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($found) !== count($allocs)) throw new FinUserError('One of those invoices was not found.');
        foreach ($found as $f) if ($cust && (int)$f['customer_id'] !== $cust) throw new FinUserError('Invoice ' . $f['invoice_number'] . ' belongs to another customer.');
        $done = [];
        foreach ($allocs as $inv => $c) {
            try {
                $r = QiActions::recordPayment($db, $companyId, $userId, $inv, [
                    'amount' => $c / 100, 'date' => $date, 'method' => (string)($in['method'] ?? 'eft'), 'reference' => (string)($in['reference'] ?? ''),
                    'notes' => (string)($in['notes'] ?? ''), 'idempotency_key' => $key !== '' ? $key . ':' . $inv : '', 'bank_account_id' => $bank,
                ]);
            } catch (QiInvalid $e) {
                throw new FinUserError(($done ? count($done) . ' receipt(s) were recorded; then ' : '') . 'invoice ' . self::number($found, $inv) . ': ' . $e->getMessage());
            }
            $done[] = ['invoice_id' => $inv, 'payment_id' => (int)$r['payment_id'], 'balance' => $r['balance'], 'status' => $r['status'], 'duplicate' => !empty($r['duplicate'])];
        }
        Fin::audit($db, $companyId, $userId, 'customer_receipt_recorded', 'customer', $cust ?: null, ['allocations' => $allocs, 'date' => $date, 'payments' => array_column($done, 'payment_id')]);
        return $done;
    }

    private static function number(array $found, int $id): string
    {
        foreach ($found as $f) if ((int)$f['id'] === $id) return (string)$f['invoice_number'];
        return '#' . $id;
    }

    /** Email a payment reminder for a late invoice (through Quotes & Invoices' mailer). */
    public static function remind(PDO $db, int $companyId, int $userId, string $role, int $invoiceId, array $opts = []): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        require_once dirname(__DIR__, 2) . '/qi/lib/QiSchema.php';
        require_once dirname(__DIR__, 2) . '/qi/services/QiMail.php';
        QiSchema::ensure($db);
        $st = $db->prepare("SELECT id FROM invoices WHERE id = ? AND company_id = ? AND deleted_at IS NULL AND status NOT IN ('draft','paid','cancelled','written_off','uncollectible','refunded')");
        $st->execute([$invoiceId, $companyId]);
        if (!$st->fetchColumn()) throw new FinUserError('Only an open invoice can be chased.');
        $r = QiMail::sendDocument($db, $companyId, $userId, 'reminder', $invoiceId, $opts);
        if (empty($r['ok'])) throw new FinUserError($r['error'] ?? 'The reminder could not be sent.');
        Fin::audit($db, $companyId, $userId, 'invoice_reminder_sent', 'invoice', $invoiceId, ['to' => $r['to']]);
        return $r;
    }

    public static function reminderDraft(PDO $db, int $companyId, int $userId, int $invoiceId): array
    {
        require_once dirname(__DIR__, 2) . '/qi/lib/QiSchema.php';
        require_once dirname(__DIR__, 2) . '/qi/services/QiMail.php';
        QiSchema::ensure($db);
        $c = QiMail::compose($db, $companyId, $userId, 'reminder', $invoiceId);
        if (!$c) throw new FinUserError('That invoice was not found.');
        return $c;
    }
}
