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

// 3.1 Finance settings mapping – vereis AR/AP/VAT/Sales/COGS/Inventory/Bank/Expense/Disposal
try {
    $required = [
        'finance_ar_account_id',
        'finance_ap_account_id',
        'finance_vat_output_account_id',
        'finance_vat_input_account_id',
        'finance_sales_account_id',
        'finance_cogs_account_id',
        'finance_inventory_account_id',
        'finance_bank_account_id',
        'finance_vat_control_account_id',
        'finance_expense_account_id',
        // Keys as AccountsMap::DEFAULTS spells them — the previous
        // '..._on_disposal_...' names matched nothing and reported the
        // disposal mappings as permanently missing.
        'finance_gain_on_disposal_account_id',
        'finance_loss_on_disposal_account_id'
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
            AND status IN ('sent','viewed','part-paid','paid','overdue')
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

// 3.7 Orphaned journal lines (SYSTEM-WIDE)
// journal_lines carries no company_id and an orphan has no header row left
// to scope by — every company shares the seeded code set, so an
// account-code filter only pretended to be per-tenant. Reported honestly as
// a system-wide data-integrity count.
try {
    $stmt = $DB->query(
        "SELECT COUNT(*) FROM journal_lines jl
          LEFT JOIN journal_entries je ON je.id = jl.journal_id
         WHERE jl.journal_id IS NOT NULL
           AND je.id IS NULL"
    );
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = ['name'=>'Orphaned journal lines (system-wide)','status'=>$cnt?("$cnt lines"):'OK','count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Orphaned journal lines (system-wide)','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
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

require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/Tieout.php';
require_once __DIR__ . '/../lib/VatCalculator.php';

// 3.10 Unbalanced journals — debits must equal credits to the cent.
// Posted ones corrupt reports (count as failures); draft/approved ones are
// legacy-era artefacts that journal_post.php would reject — reported as
// informational so the operator knows the legacy ledger holds them.
try {
    $stmt = $DB->prepare(
        "SELECT je.id, je.status
           FROM journal_entries je
           JOIN journal_lines jl ON jl.journal_id = je.id
          WHERE je.company_id = ?
          GROUP BY je.id, je.status
         HAVING SUM(ROUND(jl.debit*100)) <> SUM(ROUND(jl.credit*100))"
    );
    $stmt->execute([$companyId]);
    $postedIds = [];
    $otherCnt = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['status'] === 'posted') { $postedIds[] = (int)$r['id']; } else { $otherCnt++; }
    }
    $cnt = count($postedIds);
    $status = $cnt
        ? ("$cnt unbalanced POSTED journal(s), ids: " . implode(', ', array_slice($postedIds, 0, 10)) . ($cnt > 10 ? ', …' : ''))
        : 'OK';
    if ($otherCnt) {
        $status .= " ($otherCnt unbalanced non-posted legacy journal(s) — excluded from reports, blocked from posting)";
    }
    $checks[] = ['name'=>'Unbalanced posted journals','status'=>$status,'count'=>$cnt];
} catch (Exception $e) {
    $checks[] = ['name'=>'Unbalanced posted journals','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.11 / 3.12 AR & AP tie-out: subledger vs GL control account
try {
    $tieout = new Tieout($DB, (int)$companyId);
    $asOf = date('Y-m-d');
    foreach (['AR' => 'arSubledger', 'AP' => 'apSubledger'] as $side => $method) {
        $sub = $tieout->$method($asOf);
        $gl  = $tieout->glBalance($side, $asOf);
        $diff = round($sub - $gl, 2);
        $ok = abs($diff) < 0.01;
        $checks[] = [
            'name'   => "$side tie-out (subledger vs GL control)",
            'status' => $ok ? 'OK'
                : sprintf('Subledger %.2f vs GL %.2f — difference %.2f', $sub, $gl, $diff),
            'count'  => $ok ? 0 : 1,
        ];
    }
} catch (Exception $e) {
    $checks[] = ['name'=>'AR/AP tie-out','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.13 Journal lines on account codes missing from the company chart
// (journal_lines has no company_id — scope via journal_entries)
try {
    $stmt = $DB->prepare(
        "SELECT DISTINCT jl.account_code
           FROM journal_lines jl
           JOIN journal_entries je ON je.id = jl.journal_id
           LEFT JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
          WHERE je.company_id = ? AND ga.account_id IS NULL"
    );
    $stmt->execute([$companyId]);
    $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $cnt = count($codes);
    $checks[] = [
        'name'   => 'Journal lines on unknown account codes',
        'status' => $cnt ? ("$cnt code(s): " . implode(', ', array_slice($codes, 0, 10)) . ($cnt > 10 ? ', …' : '')) : 'OK',
        'count'  => $cnt,
    ];
} catch (Exception $e) {
    $checks[] = ['name'=>'Journal lines on unknown account codes','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.14 Posted revenue lines without a tax code (breaks VAT201 classification)
try {
    $stmt = $DB->prepare(
        "SELECT COUNT(*)
           FROM journal_lines jl
           JOIN journal_entries je ON je.id = jl.journal_id
           JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
          WHERE je.company_id = ? AND je.status = 'posted'
            AND ga.account_type = 'revenue'
            AND jl.tax_code_id IS NULL"
    );
    $stmt->execute([$companyId]);
    $cnt = (int)$stmt->fetchColumn();
    $checks[] = [
        'name'   => 'Untagged revenue lines (no tax code)',
        'status' => $cnt ? "$cnt posted revenue line(s) have no tax_code_id — VAT201 cannot classify them" : 'OK',
        'count'  => $cnt,
    ];
} catch (Exception $e) {
    $checks[] = ['name'=>'Untagged revenue lines (no tax code)','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.15 Inventory: movement on-hand value vs GL inventory account balance
try {
    $map = new AccountsMap($DB, (int)$companyId);
    $invCode = $map->code('finance_inventory_account_id');

    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(qty * unit_cost), 0) FROM inventory_movements WHERE company_id = ?"
    );
    $stmt->execute([$companyId]);
    $onHand = round((float)$stmt->fetchColumn(), 2);

    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
           FROM journal_lines jl
           JOIN journal_entries je ON je.id = jl.journal_id
          WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ?"
    );
    $stmt->execute([$companyId, $invCode]);
    $glBal = round((float)$stmt->fetchColumn(), 2);

    $diff = round($onHand - $glBal, 2);
    $ok = abs($diff) < 0.01;
    $checks[] = [
        'name'   => 'Inventory on-hand value vs GL',
        'status' => $ok ? 'OK'
            : sprintf('Movements %.2f vs GL account %s %.2f — difference %.2f', $onHand, $invCode, $glBal, $diff),
        'count'  => $ok ? 0 : 1,
    ];
} catch (Exception $e) {
    $checks[] = ['name'=>'Inventory on-hand value vs GL','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.16 Payments basis only: unallocated customer receipts carry VAT that the
// payments-basis VAT201 cannot see (no document profile to apportion over).
try {
    if (VatCalculator::companyBasis($DB, (int)$companyId) === 'payments') {
        $stmt = $DB->prepare(
            "SELECT COUNT(*) FROM (
                SELECT p.id
                  FROM payments p
                  LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
                 WHERE p.company_id = ?
                 GROUP BY p.id, p.amount
                HAVING COALESCE(SUM(pa.amount), 0) < p.amount - 0.005
             ) AS unalloc"
        );
        $stmt->execute([$companyId]);
        $cnt = (int)$stmt->fetchColumn();
        $checks[] = [
            'name'   => 'Unallocated receipts (payments basis)',
            'status' => $cnt ? "$cnt receipt(s) not fully allocated — their VAT is missing from the payments-basis VAT201" : 'OK',
            'count'  => $cnt,
        ];
    } else {
        $checks[] = ['name'=>'Unallocated receipts (payments basis)','status'=>'OK (company is on the invoice basis)','count'=>0];
    }
} catch (Exception $e) {
    $checks[] = ['name'=>'Unallocated receipts (payments basis)','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
}

// 3.17 finance_* mappings whose account subtype contradicts the role
try {
    $map = $map ?? new AccountsMap($DB, (int)$companyId);
    $wrong = [];
    foreach (AccountsMap::SUBTYPES as $settingKey => $expectedSubtypes) {
        $accountId = $map->getAccountId($settingKey);
        if (!$accountId) {
            continue; // unset/unresolvable — covered by check 3.1
        }
        $stmt = $DB->prepare(
            "SELECT account_code, account_subtype FROM gl_accounts
              WHERE account_id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$accountId, $companyId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($acc && !in_array((string)$acc['account_subtype'], $expectedSubtypes, true)) {
            $wrong[] = sprintf('%s -> %s (subtype %s, expected %s)',
                $settingKey, $acc['account_code'],
                $acc['account_subtype'] ?: 'none', implode('/', $expectedSubtypes));
        }
    }
    $cnt = count($wrong);
    $checks[] = [
        'name'   => 'Finance mappings vs account subtypes',
        'status' => $cnt ? implode('; ', $wrong) : 'OK',
        'count'  => $cnt,
    ];
} catch (Exception $e) {
    $checks[] = ['name'=>'Finance mappings vs account subtypes','status'=>'Error: '.h($e->getMessage()),'count'=>-1];
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
