<?php
// /finances/reports/vat_reconciliation.php
//
// VAT Reconciliation Report - Three-way reconciliation between:
// 1. GL account balances (VAT output/input accounts)
// 2. VAT period return totals (from gl_vat_periods)
// 3. Source document totals (invoices/bills VAT)
// Highlights variances for SARS audit purposes.

$__fin_root = realpath(__DIR__ . '/../..');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
}

require_once __DIR__ . '/../lib/AccountsMap.php';

requireRoles(['admin', 'bookkeeper', 'viewer']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

$accounts = new AccountsMap($DB, $companyId);
$vatOutputCode = $accounts->get('finance_vat_output_account_id', '2120');
$vatInputCode  = $accounts->get('finance_vat_input_account_id', '2130');

// Load VAT periods
$stmt = $DB->prepare(
    "SELECT * FROM gl_vat_periods WHERE company_id = ? ORDER BY period_start DESC"
);
$stmt->execute([$companyId]);
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build reconciliation data for each period
$reconData = [];
foreach ($periods as $p) {
    $periodStart = $p['period_start'];
    $periodEnd   = $p['period_end'];

    // 1. GL balance: sum of posted journal lines on VAT accounts for period
    $stmt = $DB->prepare(
        "SELECT jl.account_code,
                SUM(jl.debit) AS total_debit, SUM(jl.credit) AS total_credit
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_id
         WHERE je.company_id = ? AND je.status = 'posted'
           AND je.entry_date BETWEEN ? AND ?
           AND jl.account_code IN (?, ?)
         GROUP BY jl.account_code"
    );
    $stmt->execute([$companyId, $periodStart, $periodEnd, $vatOutputCode, $vatInputCode]);
    $glRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $glOutputCents = 0;
    $glInputCents = 0;
    foreach ($glRows as $r) {
        $net = round(($r['total_credit'] - $r['total_debit']) * 100);
        if ($r['account_code'] === $vatOutputCode) {
            $glOutputCents = $net;
        } elseif ($r['account_code'] === $vatInputCode) {
            // Input VAT has debit balance, so negate
            $glInputCents = -$net;
        }
    }

    // 2. VAT return totals from gl_vat_periods
    $returnOutputCents = (int)($p['output_vat_cents'] ?? 0);
    $returnInputCents  = (int)($p['input_vat_cents'] ?? 0);

    // 3. Source document totals - invoices VAT
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(i.tax), 0) AS invoice_vat
         FROM invoices i
         WHERE i.company_id = ? AND i.issue_date BETWEEN ? AND ?
           AND i.journal_id IS NOT NULL"
    );
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $invoiceVatCents = (int)round((float)$stmt->fetchColumn() * 100);

    // Source doc: bills VAT
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(b.tax), 0) AS bill_vat
         FROM ap_bills b
         WHERE b.company_id = ? AND b.issue_date BETWEEN ? AND ?
           AND b.journal_id IS NOT NULL"
    );
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $billVatCents = (int)round((float)$stmt->fetchColumn() * 100);

    // Calculate variances
    $outputVariance = $glOutputCents - $returnOutputCents;
    $inputVariance  = $glInputCents - $returnInputCents;
    $docOutputVariance = $glOutputCents - $invoiceVatCents;
    $docInputVariance  = $glInputCents - $billVatCents;

    $reconData[] = [
        'period'      => $periodStart . ' to ' . $periodEnd,
        'status'      => $p['status'],
        'gl_output'   => $glOutputCents,
        'gl_input'    => $glInputCents,
        'return_output' => $returnOutputCents,
        'return_input'  => $returnInputCents,
        'doc_output'    => $invoiceVatCents,
        'doc_input'     => $billVatCents,
        'output_var_gl_return' => $outputVariance,
        'input_var_gl_return'  => $inputVariance,
        'output_var_gl_doc'    => $docOutputVariance,
        'input_var_gl_doc'     => $docInputVariance,
    ];
}

function fmtR($cents) {
    $val = $cents / 100;
    return 'R ' . number_format($val, 2, '.', ' ');
}
function varClass($cents) {
    if ($cents == 0) return 'ok';
    return abs($cents) > 100 ? 'err' : 'warn'; // > R1 variance
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VAT Reconciliation Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background-color: #f8f9fa; }
        a.back { display: inline-block; margin-bottom: 1rem; color: #0d6efd; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
        h1 { margin-bottom: 0.3rem; }
        .subtitle { color: #6c757d; margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.78rem; margin-bottom: 2rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.5rem; text-align: right; }
        th { background-color: #f1f1f1; text-align: center; font-size: 0.72rem; white-space: nowrap; }
        td:first-child, th:first-child { text-align: left; }
        .ok { color: #0a7d32; font-weight: bold; }
        .warn { color: #b45309; font-weight: bold; }
        .err { color: #dc3545; font-weight: bold; }
        .section-header th { background-color: #1a3a5c; color: #fff; }
        .info-box { background-color: #e7f3ff; border: 1px solid #b6d4fe; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #084298; max-width: 900px; }
        @media print { body { padding: 0; background: #fff; } a.back { display: none; } }
    </style>
</head>
<body>
    <a class="back" href="/finances/vat.php">&larr; Back to VAT Returns</a>
    <h1>VAT Reconciliation Report</h1>
    <p class="subtitle">Three-way reconciliation: GL balances vs VAT returns vs source documents</p>

    <div class="info-box">
        <strong>How to read this report:</strong> For each VAT period, the GL account balance (from posted journals)
        is compared against the VAT return totals and the source document (invoice/bill) totals.
        Variances of R0.00 are shown in <span class="ok">green</span>. Variances under R1.00 are <span class="warn">amber</span> (rounding).
        Variances over R1.00 are <span class="err">red</span> and should be investigated.
    </div>

    <?php if (empty($reconData)): ?>
        <p>No VAT periods found. Create VAT periods in the VAT Returns module first.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr class="section-header">
                <th>Period</th>
                <th>Status</th>
                <th colspan="3">Output VAT</th>
                <th colspan="3">Input VAT</th>
                <th colspan="2">Variances (GL vs Return)</th>
                <th colspan="2">Variances (GL vs Docs)</th>
            </tr>
            <tr>
                <th></th>
                <th></th>
                <th>GL Balance</th>
                <th>VAT Return</th>
                <th>Invoices</th>
                <th>GL Balance</th>
                <th>VAT Return</th>
                <th>Bills</th>
                <th>Output</th>
                <th>Input</th>
                <th>Output</th>
                <th>Input</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reconData as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['period']) ?></td>
                <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                <td><?= fmtR($r['gl_output']) ?></td>
                <td><?= fmtR($r['return_output']) ?></td>
                <td><?= fmtR($r['doc_output']) ?></td>
                <td><?= fmtR($r['gl_input']) ?></td>
                <td><?= fmtR($r['return_input']) ?></td>
                <td><?= fmtR($r['doc_input']) ?></td>
                <td class="<?= varClass($r['output_var_gl_return']) ?>"><?= fmtR($r['output_var_gl_return']) ?></td>
                <td class="<?= varClass($r['input_var_gl_return']) ?>"><?= fmtR($r['input_var_gl_return']) ?></td>
                <td class="<?= varClass($r['output_var_gl_doc']) ?>"><?= fmtR($r['output_var_gl_doc']) ?></td>
                <td class="<?= varClass($r['input_var_gl_doc']) ?>"><?= fmtR($r['input_var_gl_doc']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</body>
</html>
