<?php
// GET /qi/api/list.php — the Quotes, Invoices, Credit notes and Recurring lists.
//   type   quote | invoice | credit_note | recurring
//   status a chip key (see CHIPS), default 'all'
//   q      search: number, reference, customer
//   from / to   issue-date range (Y-m-d); next run for recurring
//   sort   number | customer | date | due | amount | balance | status ; dir asc|desc
//   page (1-based), per (10..100)
// Returns rows, the chip counts for the current search, totals and paging.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QiDocument.php';
require_once __DIR__ . '/../lib/Currencies.php';

$in = $_GET;
$type = in_array($in['type'] ?? '', ['quote', 'invoice', 'credit_note', 'recurring'], true) ? $in['type'] : 'invoice';
$status = qi_str($in, 'status', 20) ?: 'all';
$q = qi_str($in, 'q', 100);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['from'] ?? '') ? $in['from'] : null;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['to'] ?? '') ? $in['to'] : null;
$page = max(1, qi_int($in, 'page'));
$per = max(10, min(100, qi_int($in, 'per') ?: 30));
$dir = strtolower($in['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
$cid = $QI_COMPANY_ID;

// ------------------------------------------------------------------ per type
if ($type === 'recurring') {
    $chips = [
        'all' => '1=1',
        'active' => "r.active = 1 AND (r.end_date IS NULL OR r.end_date < '1000-01-02' OR r.end_date >= CURDATE())",
        'paused' => "r.active = 0",
        'ended' => "r.active = 1 AND r.end_date >= '1000-01-02' AND r.end_date < CURDATE()",
    ];
    $base = "FROM recurring_invoices r LEFT JOIN crm_accounts ca ON ca.id = r.customer_id AND ca.company_id = r.company_id WHERE r.company_id = ?";
    $params = [$cid];
    if ($q !== '') {
        $base .= " AND (r.template_name LIKE ? OR ca.name LIKE ?)";
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        array_push($params, $like, $like);
    }
    if ($from) { $base .= " AND r.next_run_date >= ?"; $params[] = $from; }
    if ($to) { $base .= " AND r.next_run_date <= ?"; $params[] = $to; }
    $counts = [];
    foreach ($chips as $k => $cond) {
        $st = $DB->prepare("SELECT COUNT(*) $base AND $cond");
        $st->execute($params);
        $counts[$k] = (int)$st->fetchColumn();
    }
    $cond = $chips[$status] ?? '1=1';
    $sorts = ['number' => 'r.template_name', 'customer' => 'ca.name', 'date' => 'r.next_run_date', 'due' => 'r.next_run_date', 'status' => 'r.active'];
    $order = ($sorts[$in['sort'] ?? ''] ?? 'r.next_run_date') . ' ' . (isset($sorts[$in['sort'] ?? '']) ? $dir : 'ASC') . ', r.id DESC';
    $st = $DB->prepare("SELECT COUNT(*) $base AND $cond");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $st = $DB->prepare("SELECT r.*, ca.name AS customer_name $base AND $cond ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $amounts = [];
    $generated = [];
    if ($ids) {
        $in_ = implode(',', $ids);
        foreach ($DB->query("SELECT recurring_invoice_id AS id, quantity, unit_price, discount, tax_rate FROM recurring_invoice_lines WHERE recurring_invoice_id IN ($in_)")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $line = QiMoney::line($l['quantity'], $l['unit_price'], $l['discount'], $l['tax_rate']);
            $amounts[(int)$l['id']] = ($amounts[(int)$l['id']] ?? 0) + $line['gross'];
        }
        try {
            $st2 = $DB->prepare("SELECT recurring_invoice_id AS id, COUNT(*) n FROM invoices WHERE company_id = ? AND recurring_invoice_id IN ($in_) AND deleted_at IS NULL GROUP BY recurring_invoice_id");
            $st2->execute([$cid]);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $g) $generated[(int)$g['id']] = (int)$g['n'];
        } catch (Throwable $e) { /* column not provisioned yet */ }
    }
    $out = [];
    foreach ($rows as $r) {
        $cur = Currencies::isValid($r['currency'] ?? null) ? strtoupper($r['currency']) : Currencies::BASE;
        $ended = !empty($r['end_date']) && $r['end_date'] >= '1000-01-02' && $r['end_date'] < date('Y-m-d');
        $every = (int)$r['interval_count'] > 1 ? 'Every ' . (int)$r['interval_count'] . ' ' . ['weekly' => 'weeks', 'monthly' => 'months', 'quarterly' => 'quarters', 'yearly' => 'years'][$r['frequency']] : ucfirst($r['frequency']);
        $out[] = [
            'id' => (int)$r['id'], 'type' => 'recurring', 'number' => (string)($r['template_name'] ?: 'Recurring invoice #' . $r['id']),
            'customer' => (string)($r['customer_name'] ?? ''), 'customer_id' => (int)$r['customer_id'],
            'frequency' => $every, 'next_run' => $r['next_run_date'], 'end_date' => ($r['end_date'] ?? '') >= '1000-01-02' ? $r['end_date'] : null,
            'last_generated' => $r['last_generated_date'] ?? null, 'generated' => $generated[(int)$r['id']] ?? null,
            'total' => ($amounts[(int)$r['id']] ?? 0) / 100, 'currency' => $cur, 'symbol' => Currencies::symbol($cur),
            'status' => !$r['active'] ? ['key' => 'paused', 'label' => 'Paused', 'tone' => 'muted'] : ($ended ? ['key' => 'ended', 'label' => 'Ended', 'tone' => 'muted'] : ['key' => 'active', 'label' => 'Active', 'tone' => 'success']),
            'auto_send' => !empty($r['auto_send']),
        ];
    }
    qi_json(['type' => $type, 'rows' => $out, 'counts' => $counts, 'total' => $total, 'page' => $page, 'per' => $per]);
}

$table = QiDocument::table($type);
$numCol = ['quote' => 'quote_number', 'invoice' => 'invoice_number', 'credit_note' => 'credit_note_number'][$type];
$rate = "COALESCE(NULLIF(d.exchange_rate, 0), 1)";
if ($type === 'quote') {
    $chips = [
        'all' => "1=1",
        'draft' => "d.status = 'draft'",
        'open' => "d.status IN ('sent','viewed') AND (d.expiry_date IS NULL OR d.expiry_date >= CURDATE())",
        'accepted' => "d.status IN ('accepted','converted')",
        'declined' => "d.status = 'declined'",
        'expired' => "(d.status = 'expired' OR (d.status IN ('sent','viewed') AND d.expiry_date < CURDATE()))",
    ];
    $live = "1=1";
    $dueCol = 'd.expiry_date';
} elseif ($type === 'invoice') {
    $open = "d.status IN ('sent','viewed','part-paid','overdue') AND d.balance_due > 0";
    $chips = [
        'all' => "d.archived_at IS NULL",
        'draft' => "d.status = 'draft' AND d.archived_at IS NULL",
        'due' => "$open AND (d.due_date IS NULL OR d.due_date >= CURDATE()) AND d.archived_at IS NULL",
        'overdue' => "$open AND d.due_date < CURDATE() AND d.archived_at IS NULL",
        'paid' => "d.status = 'paid' AND d.archived_at IS NULL",
        'void' => "d.status IN ('cancelled','written_off','uncollectible','refunded') AND d.archived_at IS NULL",
        'archived' => "d.archived_at IS NOT NULL",
    ];
    $live = "d.deleted_at IS NULL";
    $dueCol = 'd.due_date';
} else {
    $chips = [
        'all' => "1=1",
        'draft' => "d.status = 'draft'",
        'approved' => "d.status IN ('approved','sent')",
        'applied' => "d.status = 'applied'",
        'cancelled' => "d.status = 'cancelled'",
    ];
    $live = "1=1";
    $dueCol = 'd.issue_date';
}

$base = "FROM `$table` d LEFT JOIN crm_accounts ca ON ca.id = d.customer_id AND ca.company_id = d.company_id WHERE d.company_id = ? AND $live";
$params = [$cid];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $refCol = isset(QiSchema::columns($DB, $table)['reference']) ? " OR d.reference LIKE ?" : '';
    $base .= " AND (d.`$numCol` LIKE ? OR ca.name LIKE ?$refCol)";
    array_push($params, $like, $like);
    if ($refCol) $params[] = $like;
}
if ($from) { $base .= " AND d.issue_date >= ?"; $params[] = $from; }
if ($to) { $base .= " AND d.issue_date <= ?"; $params[] = $to; }

$counts = [];
foreach ($chips as $k => $cond) {
    $st = $DB->prepare("SELECT COUNT(*) $base AND $cond");
    $st->execute($params);
    $counts[$k] = (int)$st->fetchColumn();
}
$cond = $chips[$status] ?? $chips['all'];

$sorts = [
    'number' => "d.`$numCol`", 'customer' => 'ca.name', 'date' => 'd.issue_date', 'due' => $dueCol,
    'amount' => "d.total * $rate", 'balance' => $type === 'invoice' ? "d.balance_due * $rate" : "d.total * $rate", 'status' => 'd.status',
];
$sortKey = $in['sort'] ?? '';
$order = isset($sorts[$sortKey]) ? $sorts[$sortKey] . ' ' . $dir . ', d.id DESC' : 'd.issue_date DESC, d.id DESC';

$st = $DB->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(d.total * $rate), 0) AS total_zar" . ($type === 'invoice' ? ", COALESCE(SUM(CASE WHEN d.status NOT IN ('draft','cancelled','written_off','uncollectible') THEN d.balance_due * $rate END), 0) AS balance_zar" : '') . " $base AND $cond");
$st->execute($params);
$agg = $st->fetch(PDO::FETCH_ASSOC);

$st = $DB->prepare("SELECT d.*, ca.name AS customer_name $base AND $cond ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $cur = Currencies::isValid($d['currency'] ?? null) ? strtoupper($d['currency']) : Currencies::BASE;
    $row = [
        'id' => (int)$d['id'], 'type' => $type, 'number' => (string)$d[$numCol],
        'customer' => (string)($d['customer_name'] ?? ''), 'customer_id' => (int)$d['customer_id'],
        'reference' => (string)($d['reference'] ?? ''), 'issue_date' => $d['issue_date'],
        'total' => (float)$d['total'], 'currency' => $cur, 'symbol' => Currencies::symbol($cur),
        'status' => QiDocument::status($type, $d),
    ];
    if ($type === 'quote') $row['expiry_date'] = $d['expiry_date'];
    if ($type === 'invoice') {
        $row['due_date'] = $d['due_date'];
        $row['balance'] = (float)$d['balance_due'];
        $row['archived'] = !empty($d['archived_at']);
    }
    if ($type === 'credit_note') $row['invoice_id'] = (int)($d['invoice_id'] ?? 0);
    $rows[] = $row;
}

qi_json([
    'type' => $type, 'rows' => $rows, 'counts' => $counts,
    'total' => (int)$agg['n'], 'sum_zar' => (float)$agg['total_zar'], 'balance_zar' => isset($agg['balance_zar']) ? (float)$agg['balance_zar'] : null,
    'page' => $page, 'per' => $per,
]);
