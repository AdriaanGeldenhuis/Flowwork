<?php
// GET /qi/api/overview.php — the Quotes & Invoices overview.
// Money is normalised to ZAR through each document's exchange_rate; overdue and
// expired are derived from dates (nothing stores them); deleted invoices never
// count; collection uses real receipts only (not write-offs or credit notes).
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QiDocument.php';

$cid = $QI_COMPANY_ID;
$rate = "COALESCE(NULLIF(i.exchange_rate, 0), 1)";
$openInv = "i.company_id = ? AND i.deleted_at IS NULL AND i.status IN ('sent','viewed','part-paid','overdue') AND i.balance_due > 0";

// Receivables + aging
$st = $DB->prepare("SELECT COUNT(*) AS n,
        COALESCE(SUM(i.balance_due * $rate), 0) AS outstanding,
        SUM(i.due_date < CURDATE()) AS overdue_n,
        COALESCE(SUM(CASE WHEN i.due_date < CURDATE() THEN i.balance_due * $rate END), 0) AS overdue,
        COALESCE(SUM(CASE WHEN i.due_date >= CURDATE() OR i.due_date IS NULL THEN i.balance_due * $rate END), 0) AS b_current,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.balance_due * $rate END), 0) AS b30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.balance_due * $rate END), 0) AS b60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.balance_due * $rate END), 0) AS b90,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.balance_due * $rate END), 0) AS b90p,
        SUM(i.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS due_week
    FROM invoices i WHERE $openInv");
$st->execute([$cid]);
$recv = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// Cash collected: this month, last month, and a 12-month series (by payment date)
$months = [];
for ($k = 11; $k >= 0; $k--) {
    $ts = strtotime(date('Y-m-01') . " -$k months");
    $months[date('Y-m', $ts)] = ['label' => date('M', $ts), 'year' => date('Y', $ts), 'collected' => 0.0, 'invoiced' => 0.0];
}
$start = array_key_first($months) . '-01';
$st = $DB->prepare("SELECT DATE_FORMAT(p.payment_date, '%Y-%m') ym, COALESCE(SUM(pa.amount * $rate), 0) amt
                      FROM payments p
                      JOIN payment_allocations pa ON pa.payment_id = p.id
                      JOIN invoices i ON i.id = pa.invoice_id AND i.company_id = p.company_id
                     WHERE p.company_id = ? AND p.payment_date >= ? AND i.deleted_at IS NULL
                  GROUP BY ym");
$st->execute([$cid, $start]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($months[$r['ym']])) $months[$r['ym']]['collected'] = round((float)$r['amt'], 2);
$st = $DB->prepare("SELECT DATE_FORMAT(i.issue_date, '%Y-%m') ym, COALESCE(SUM(i.total * $rate), 0) amt
                      FROM invoices i WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled') AND i.issue_date >= ?
                  GROUP BY ym");
$st->execute([$cid, $start]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($months[$r['ym']])) $months[$r['ym']]['invoiced'] = round((float)$r['amt'], 2);
$series = array_values($months);
$thisMonth = $series[11]['collected'];
$lastMonth = $series[10]['collected'];

// Collection rate (90 days): receipts on invoices issued in the window / their totals
$st = $DB->prepare("SELECT COALESCE(SUM(i.total * $rate), 0) FROM invoices i
                     WHERE i.company_id = ? AND i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled') AND i.issue_date >= CURDATE() - INTERVAL 90 DAY");
$st->execute([$cid]);
$issued90 = (float)$st->fetchColumn();
$st = $DB->prepare("SELECT COALESCE(SUM(pa.amount * $rate), 0) FROM payment_allocations pa
                      JOIN payments p ON p.id = pa.payment_id AND p.company_id = ?
                      JOIN invoices i ON i.id = pa.invoice_id AND i.company_id = p.company_id
                     WHERE i.deleted_at IS NULL AND i.status NOT IN ('draft','cancelled') AND i.issue_date >= CURDATE() - INTERVAL 90 DAY");
$st->execute([$cid]);
$collected90 = (float)$st->fetchColumn();

// Quotes: open pipeline and win rate over decided quotes (12 months)
$qrate = "COALESCE(NULLIF(q.exchange_rate, 0), 1)";
$st = $DB->prepare("SELECT
        SUM(q.status IN ('sent','viewed') AND (q.expiry_date IS NULL OR q.expiry_date >= CURDATE())) AS open_n,
        COALESCE(SUM(CASE WHEN q.status IN ('sent','viewed') AND (q.expiry_date IS NULL OR q.expiry_date >= CURDATE()) THEN q.total * $qrate END), 0) AS open_value,
        SUM(q.status = 'draft') AS drafts,
        SUM(q.status IN ('accepted','converted') AND q.issue_date >= CURDATE() - INTERVAL 12 MONTH) AS won,
        SUM((q.status = 'declined' OR q.status = 'expired' OR (q.status IN ('sent','viewed') AND q.expiry_date < CURDATE())) AND q.issue_date >= CURDATE() - INTERVAL 12 MONTH) AS lost,
        SUM(q.status IN ('sent','viewed') AND q.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS expiring
    FROM quotes q WHERE q.company_id = ?");
$st->execute([$cid]);
$qk = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$decided = (int)$qk['won'] + (int)$qk['lost'];

$st = $DB->prepare("SELECT COUNT(*) FROM invoices WHERE company_id = ? AND deleted_at IS NULL AND status = 'draft'");
$st->execute([$cid]);
$draftInvoices = (int)$st->fetchColumn();

// Needs attention
$attention = [];
$st = $DB->prepare("SELECT i.id, i.invoice_number, i.due_date, i.balance_due, i.currency, ca.name AS customer
                      FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id AND ca.company_id = i.company_id
                     WHERE $openInv AND i.due_date < CURDATE() ORDER BY i.due_date ASC LIMIT 6");
$st->execute([$cid]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $attention[] = ['kind' => 'overdue', 'type' => 'invoice', 'id' => (int)$r['id'], 'number' => $r['invoice_number'], 'customer' => (string)$r['customer'],
                    'date' => $r['due_date'], 'amount' => (float)$r['balance_due'], 'symbol' => Currencies::symbol($r['currency'] ?: 'ZAR'),
                    'days' => (int)((strtotime(date('Y-m-d')) - strtotime($r['due_date'])) / 86400)];
}
$st = $DB->prepare("SELECT q.id, q.quote_number, q.expiry_date, q.total, q.currency, ca.name AS customer
                      FROM quotes q LEFT JOIN crm_accounts ca ON ca.id = q.customer_id AND ca.company_id = q.company_id
                     WHERE q.company_id = ? AND q.status IN ('sent','viewed') AND q.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY
                  ORDER BY q.expiry_date ASC LIMIT 4");
$st->execute([$cid]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $attention[] = ['kind' => 'expiring', 'type' => 'quote', 'id' => (int)$r['id'], 'number' => $r['quote_number'], 'customer' => (string)$r['customer'],
                    'date' => $r['expiry_date'], 'amount' => (float)$r['total'], 'symbol' => Currencies::symbol($r['currency'] ?: 'ZAR'),
                    'days' => (int)((strtotime($r['expiry_date']) - strtotime(date('Y-m-d'))) / 86400)];
}

// Recent documents
$recent = [];
$st = $DB->prepare("(SELECT 'invoice' t, i.id, i.invoice_number num, i.status, i.total, i.currency, i.issue_date, i.due_date, NULL expiry_date, i.balance_due, i.deleted_at, i.updated_at, ca.name customer
                       FROM invoices i LEFT JOIN crm_accounts ca ON ca.id = i.customer_id AND ca.company_id = i.company_id
                      WHERE i.company_id = ? AND i.deleted_at IS NULL ORDER BY i.updated_at DESC LIMIT 6)
                    UNION ALL
                    (SELECT 'quote' t, q.id, q.quote_number num, q.status, q.total, q.currency, q.issue_date, NULL due_date, q.expiry_date, NULL balance_due, NULL deleted_at, q.updated_at, ca.name customer
                       FROM quotes q LEFT JOIN crm_accounts ca ON ca.id = q.customer_id AND ca.company_id = q.company_id
                      WHERE q.company_id = ? ORDER BY q.updated_at DESC LIMIT 6)
                    ORDER BY updated_at DESC LIMIT 7");
$st->execute([$cid, $cid]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $recent[] = ['type' => $r['t'], 'id' => (int)$r['id'], 'number' => $r['num'], 'customer' => (string)$r['customer'], 'total' => (float)$r['total'],
                 'symbol' => Currencies::symbol($r['currency'] ?: 'ZAR'), 'updated_at' => $r['updated_at'],
                 'status' => QiDocument::status($r['t'], ['status' => $r['status'], 'due_date' => $r['due_date'], 'expiry_date' => $r['expiry_date'], 'balance_due' => $r['balance_due'], 'deleted_at' => $r['deleted_at']])];
}

// Top debtors
$st = $DB->prepare("SELECT ca.id, ca.name, COUNT(*) n, SUM(i.balance_due * $rate) amt, MIN(i.due_date) oldest
                      FROM invoices i JOIN crm_accounts ca ON ca.id = i.customer_id AND ca.company_id = i.company_id
                     WHERE $openInv GROUP BY ca.id, ca.name ORDER BY amt DESC LIMIT 5");
$st->execute([$cid]);
$debtors = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'count' => (int)$r['n'], 'amount' => round((float)$r['amt'], 2), 'oldest' => $r['oldest']], $st->fetchAll(PDO::FETCH_ASSOC));

$counts = [];
foreach (['quotes' => "SELECT COUNT(*) FROM quotes WHERE company_id = ?",
          'invoices' => "SELECT COUNT(*) FROM invoices WHERE company_id = ? AND deleted_at IS NULL AND archived_at IS NULL",
          'credit_notes' => "SELECT COUNT(*) FROM credit_notes WHERE company_id = ?",
          'recurring' => "SELECT COUNT(*) FROM recurring_invoices WHERE company_id = ? AND active = 1"] as $k => $sql) {
    $st = $DB->prepare($sql);
    $st->execute([$cid]);
    $counts[$k] = (int)$st->fetchColumn();
}

qi_json([
    'kpi' => [
        'outstanding' => round((float)$recv['outstanding'], 2), 'open_invoices' => (int)$recv['n'],
        'overdue' => round((float)$recv['overdue'], 2), 'overdue_count' => (int)$recv['overdue_n'], 'due_week' => (int)$recv['due_week'],
        'collected_month' => $thisMonth, 'collected_last_month' => $lastMonth,
        'collection_rate' => $issued90 > 0 ? round($collected90 / $issued90 * 100) : null,
        'pipeline' => round((float)$qk['open_value'], 2), 'open_quotes' => (int)$qk['open_n'], 'quote_drafts' => (int)$qk['drafts'],
        'win_rate' => $decided > 0 ? round((int)$qk['won'] / $decided * 100) : null, 'expiring' => (int)$qk['expiring'],
        'draft_invoices' => $draftInvoices,
    ],
    'aging' => [
        ['key' => 'current', 'label' => 'Not yet due', 'amount' => round((float)$recv['b_current'], 2)],
        ['key' => '30', 'label' => '1–30 days', 'amount' => round((float)$recv['b30'], 2)],
        ['key' => '60', 'label' => '31–60 days', 'amount' => round((float)$recv['b60'], 2)],
        ['key' => '90', 'label' => '61–90 days', 'amount' => round((float)$recv['b90'], 2)],
        ['key' => '90p', 'label' => '90+ days', 'amount' => round((float)$recv['b90p'], 2)],
    ],
    'series' => $series,
    'attention' => $attention,
    'recent' => $recent,
    'debtors' => $debtors,
    'counts' => $counts,
]);
