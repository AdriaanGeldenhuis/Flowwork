<?php
// /finances/tools/health.php
// Finance Health Check – read-only diagnostiek

/* -----------------------------------------------------------
 * 1) Bootstrap: vind init.php / auth_gate.php / permissions.php
 * ----------------------------------------------------------- */
$rootDir = realpath(__DIR__ . '/../../'); // finances/tools -> project root

$loaded = false;
if ($rootDir && file_exists($rootDir . '/app/init.php')) {
    require_once $rootDir . '/app/init.php';
    require_once $rootDir . '/app/auth_gate.php';
    $permPath = $rootDir . '/app/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
    $loaded = true;
}
if (!$loaded) {
    // Fall back na root-vlak
    if ($rootDir && file_exists($rootDir . '/init.php'))   require_once $rootDir . '/init.php';
    if ($rootDir && file_exists($rootDir . '/auth_gate.php')) require_once $rootDir . '/auth_gate.php';
    $permPath = $rootDir . '/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
}

// As requireRoles() nie beskikbaar is nie, wys vriendelike fout i.p.v. 500
if (!function_exists('requireRoles')) {
    echo '<!doctype html><html><body><p style="color:#b91c1c">
    Error: permissions helper (requireRoles) not loaded. 
    Maak seker <code>app/finances/permissions.php</code> of <code>finances/permissions.php</code> bestaan.
    </p></body></html>';
    exit;
}

// Rol-toegang
requireRoles(['admin','bookkeeper']);

// Sessiekonteks
$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    echo '<!doctype html><html><body><p style="color:#b91c1c">Error: Company context missing.</p></body></html>';
    exit;
}

/* -----------------------
 * 2) Helpers
 * ----------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* -----------------------
 * 3) Checks
 * ----------------------- */
$checks = [];

// 3.1 Finance settings mapping – vereis AR/AP/VAT/Sales/COGS/Inventory/Bank
try {
    $required = [
        'finance_ar_account_id',
        'finance_ap_account_id',
        'finance_vat_output_account_id',
        'finance_vat_input_account_id',
        'finance_sales_account_id',
        'finance_cogs_account_id',
        'finance_inventory_account_id',
        'finance_bank_account_id' // mag leeg wees, maar rapporteer as “Missing”
    ];
    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $stmt = $DB->prepare("SELECT setting_key, setting_value
                            FROM company_settings
                           WHERE company_id = ?
                             AND setting_key IN ($placeholders)");
    $params = array_merge([$companyId], $required);
    $stmt->execute($params);
    $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $missing = [];
    foreach ($required as $rk) {
        if (!array_key_exists($rk, $map) || $map[$rk] === '' || $map[$rk] === null) {
            $missing[] = $rk;
        }
    }
    $checks[] = [
        'name'   => 'Finance settings mapping',
        'status' => $missing ? ('Missing: ' . implode(', ', $missing)) : 'OK',
        'count'  => count($missing)
    ];
} catch (Exception $e) {
    $checks[] = ['name'=>'Finance settings mapping','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.2 Invoice journal references – volgens jou enum (GEEN 'posted' status)
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM invoices
          WHERE company_id = ?
            AND status IN ('sent','viewed','paid','overdue')
            AND (journal_id IS NULL OR journal_id = 0)"
    );
    $stmt->execute([$companyId]);
    $missing = (int)$stmt->fetchColumn();

    $stmt = $DB->prepare(
        "SELECT COUNT(*) 
           FROM invoices i
           LEFT JOIN journal_entries je ON je.id = i.journal_id
          WHERE i.company_id = ?
            AND i.journal_id IS NOT NULL
            AND je.id IS NULL"
    );
    $stmt->execute([$companyId]);
    $orphans = (int)$stmt->fetchColumn();

    $total = $missing + $orphans;
    $status = $total ? "Issues: no_journal=$missing, orphaned=$orphans" : 'OK';
    $checks[] = ['name'=>'Invoice journal references','status'=>$status,'count'=>$total];
} catch (Exception $e) {
    $checks[] = ['name'=>'Invoice journal references','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.3 AP bill journal references – AP het wél 'posted'
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM ap_bills
          WHERE company_id = ?
            AND status = 'posted'
            AND (journal_id IS NULL OR journal_id = 0)"
    );
    $stmt->execute([$companyId]);
    $missing = (int)$stmt->fetchColumn();

    $stmt = $DB->prepare(
        "SELECT COUNT(*)
           FROM ap_bills b
           LEFT JOIN journal_entries je ON je.id = b.journal_id
          WHERE b.company_id = ?
            AND b.journal_id IS NOT NULL
            AND je.id IS NULL"
    );
    $stmt->execute([$companyId]);
    $orphans = (int)$stmt->fetchColumn();

    $total = $missing + $orphans;
    $status = $total ? "Issues: no_journal=$missing, orphaned=$orphans" : 'OK';
    $checks[] = ['name'=>'AP bill journal references','status'=>$status,'count'=>$total];
} catch (Exception $e) {
    $checks[] = ['name'=>'AP bill journal references','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.4 Customer payments – ontbrekende joernaal
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM payments
          WHERE company_id = ?
            AND (journal_id IS NULL OR journal_id = 0)"
    );
    $stmt->execute([$companyId]);
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = ['name'=>'Customer payments journal','status'=>$cnt?'Missing on '.$cnt:'OK','count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Customer payments journal','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.5 Supplier payments – ontbrekende joernaal
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM ap_payments
          WHERE company_id = ?
            AND (journal_id IS NULL OR journal_id = 0)"
    );
    $stmt->execute([$companyId]);
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = ['name'=>'Supplier payments journal','status'=>$cnt?'Missing on '.$cnt:'OK','count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Supplier payments journal','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.6 Unreconciled bank transaksies
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM gl_bank_transactions
          WHERE company_id = ?
            AND matched = 0"
    );
    $stmt->execute([$companyId]);
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = ['name'=>'Unreconciled bank transactions','status'=>$cnt?("$cnt pending"):'OK','count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Unreconciled bank transactions','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.7 Orphaned journal lines
try {
    $stmt = $DB->query(
        "SELECT COUNT(*) FROM journal_lines jl
          LEFT JOIN journal_entries je ON je.id = jl.journal_id
         WHERE jl.journal_id IS NOT NULL
           AND je.id IS NULL"
    );
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = ['name'=>'Orphaned journal lines','status'=>$cnt?("$cnt lines"):'OK','count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Orphaned journal lines','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.8 Payroll runs – “locked maar nie gepos nie”
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM pay_runs
          WHERE company_id = ?
            AND status = 'locked'"
    );
    $stmt->execute([$companyId]);
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = ['name'=>'Payroll run journals','status'=>$cnt?("$cnt runs locked but not posted"):'OK','count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Payroll run journals','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.9 Open VAT periodes (open of prepared)
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM gl_vat_periods
          WHERE company_id = ?
            AND status IN ('open','prepared')"
    );
    $stmt->execute([$companyId]);
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = ['name'=>'Open VAT periods','status'=>$cnt?("$cnt pending"):'OK','count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Open VAT periods','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

/* -----------------------
 * 4) HTML Output
 * ----------------------- */
$issuesTotal = 0;
foreach ($checks as $c) {
    if ($c['count'] > 0) $issuesTotal += $c['count'];
}
$healthText  = $issuesTotal ? 'Issues detected' : 'All clear';
$healthColor = $issuesTotal ? '#fdd' : '#dfd';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Finance Health Check</title>
<style>
    body{font-family:system-ui,Arial,sans-serif;margin:16px;background:#f7f7fb;color:#111}
    h1{margin:0 0 12px 0}
    .summary{padding:10px;border:1px solid #ddd;background:<?=h($healthColor)?>;margin-bottom:12px}
    table{width:100%;border-collapse:collapse;margin-top:8px}
    th,td{border:1px solid #e5e7eb;padding:8px;text-align:left}
    th{background:#f3f4f6}
    .ok{color:#0a7d32;font-weight:600}
    .err{color:#be123c;font-weight:600}
    .warn{color:#b45309;font-weight:600}
    pre{white-space:pre-wrap;background:#f9fafb;border:1px solid #e5e7eb;padding:8px;border-radius:6px}
</style>
</head>
<body>
<h1>Finance Health Check</h1>
<div class="summary"><strong>Overall status:</strong> <?=h($healthText)?>. Total issues: <?= (int)$issuesTotal ?>.</div>
<table>
    <thead><tr><th>Check</th><th>Status</th><th>Count</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): 
        $cls = ($c['count'] < 0) ? 'warn' : (($c['count'] > 0) ? 'err' : 'ok'); ?>
        <tr>
            <td><?=h($c['name'])?></td>
            <td class="<?=$cls?>"><?=h($c['status'])?></td>
            <td><?=h($c['count'])?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Index Recommendations</h2>
<pre>ALTER TABLE journal_lines      ADD INDEX idx_journal_lines_account_project (account_code, project_id);
ALTER TABLE gl_bank_transactions ADD INDEX idx_gl_bank_transactions_bank_date (bank_account_id, tx_date);
ALTER TABLE invoices            ADD INDEX idx_invoices_journal_id (journal_id);
ALTER TABLE ap_bills            ADD INDEX idx_ap_bills_journal_id (journal_id);
ALTER TABLE payments            ADD INDEX idx_payments_journal_id (journal_id);
ALTER TABLE ap_payments         ADD INDEX idx_ap_payments_journal_id (journal_id);</pre>
</body>
</html>
