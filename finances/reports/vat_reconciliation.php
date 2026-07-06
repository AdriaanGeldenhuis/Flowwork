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
require_once __DIR__ . '/../lib/VatCalculator.php';

requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

// Resolve VAT control accounts through the canonical mapping authority —
// never hardcoded chart codes (legacy charts differ from the seeded chart).
$accounts = new AccountsMap($DB, (int)$companyId);
$vatOutputCode = $accounts->code('finance_vat_output_account_id');
$vatInputCode  = $accounts->code('finance_vat_input_account_id');

// Load VAT periods
$stmt = $DB->prepare(
    "SELECT * FROM gl_vat_periods WHERE company_id = ? ORDER BY period_start DESC"
);
$stmt->execute([$companyId]);
$periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// Selected-period detail: GL control balances vs live VAT201 box totals,
// honouring the period's basis; on the payments basis a timing-differences
// section reconciles the accrual GL to the cash-basis VAT201.
// ---------------------------------------------------------------------------
$selectedId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
$selected = null;
foreach ($periods as $p) {
    if ((int)$p['id'] === $selectedId) { $selected = $p; break; }
}
if (!$selected && $periods) {
    $selected = $periods[0]; // default: most recent period
}

$detail = null;
if ($selected) {
    $selStart = $selected['period_start'];
    $selEnd   = $selected['period_end'];
    $selBasis = $selected['basis'] ?: VatCalculator::companyBasis($DB, (int)$companyId);

    // GL control movements for the period (posted journals only).
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
    $stmt->execute([$companyId, $selStart, $selEnd, $vatOutputCode, $vatInputCode]);
    $glOutC = 0;
    $glInC = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $net = (int)round(($r['total_credit'] - $r['total_debit']) * 100);
        if ($r['account_code'] === $vatOutputCode) {
            $glOutC = $net;              // output VAT: credit balance
        } elseif ($r['account_code'] === $vatInputCode) {
            $glInC = -$net;              // input VAT: debit balance
        }
    }

    // Live VAT201 boxes on the period's own basis.
    $boxes = VatCalculator::vat201Boxes(
        $DB, (int)$companyId, $selStart, $selEnd,
        $vatOutputCode, $vatInputCode, $selBasis
    );

    $detail = [
        'period'   => $selStart . ' to ' . $selEnd,
        'basis'    => $selBasis,
        'status'   => $selected['status'],
        'gl_out'   => $glOutC,
        'gl_in'    => $glInC,
        'box5'     => (int)$boxes['box5_total_output_cents'],
        'box9'     => (int)$boxes['box9_total_input_cents'],
        'box10'    => (int)$boxes['box10_net_cents'],
        'timing'   => null,
    ];

    if ($selBasis === 'payments') {
        // The GL is accrual; the VAT201 is cash. The bridge is the timing
        // difference: output VAT invoiced-but-not-yet-received and input VAT
        // billed-but-not-yet-paid (invoice-basis boxes minus payments-basis
        // boxes for the same window).
        $invBoxes = VatCalculator::vat201Boxes(
            $DB, (int)$companyId, $selStart, $selEnd,
            $vatOutputCode, $vatInputCode, 'invoice'
        );
        $detail['timing'] = [
            'inv_box5'      => (int)$invBoxes['box5_total_output_cents'],
            'inv_box9'      => (int)$invBoxes['box9_total_input_cents'],
            'output_timing' => (int)$invBoxes['box5_total_output_cents'] - (int)$boxes['box5_total_output_cents'],
            'input_timing'  => (int)$invBoxes['box9_total_input_cents'] - (int)$boxes['box9_total_input_cents'],
        ];
    }
}

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
        "SELECT COALESCE(SUM(i.tax * COALESCE(NULLIF(i.exchange_rate, 0), 1)), 0) AS invoice_vat
         FROM invoices i
         WHERE i.company_id = ? AND i.issue_date BETWEEN ? AND ?
           AND i.journal_id IS NOT NULL"
    );
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $invoiceVatCents = (int)round((float)$stmt->fetchColumn() * 100);

    // Net off posted credit notes — the GL side reflects their reversals.
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(cn.tax * COALESCE(NULLIF(cn.exchange_rate, 0), 1)), 0)
         FROM credit_notes cn
         WHERE cn.company_id = ? AND cn.issue_date BETWEEN ? AND ?
           AND cn.journal_id IS NOT NULL"
    );
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $invoiceVatCents -= (int)round((float)$stmt->fetchColumn() * 100);

    // Source doc: bills VAT
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(b.tax), 0) AS bill_vat
         FROM ap_bills b
         WHERE b.company_id = ? AND b.issue_date BETWEEN ? AND ?
           AND b.journal_id IS NOT NULL"
    );
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $billVatCents = (int)round((float)$stmt->fetchColumn() * 100);

    // Net off posted vendor credits — the GL side reflects their reversals.
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(vc.tax), 0)
         FROM vendor_credits vc
         WHERE vc.company_id = ? AND vc.issue_date BETWEEN ? AND ?
           AND vc.journal_id IS NOT NULL"
    );
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $billVatCents -= (int)round((float)$stmt->fetchColumn() * 100);

    // Calculate variances
    $outputVariance = $glOutputCents - $returnOutputCents;
    $inputVariance  = $glInputCents - $returnInputCents;
    $docOutputVariance = $glOutputCents - $invoiceVatCents;
    $docInputVariance  = $glInputCents - $billVatCents;

    $reconData[] = [
        'id'          => (int)$p['id'],
        'period'      => $periodStart . ' to ' . $periodEnd,
        'basis'       => $p['basis'] ?: 'invoice',
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
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
<body class="fw-finance">
    <div class="fw-finance__container">
    <?php $finTitle = 'VAT Reconciliation'; $finBack = '/finances/vat.php'; include __DIR__ . '/../partials/header.php'; ?>
    <main class="fw-finance__main">
    <div class="fw-finance__paper">
    <h1>VAT Reconciliation Report</h1>
    <p class="subtitle">Three-way reconciliation: GL balances vs VAT returns vs source documents</p>

    <div class="info-box">
        <strong>How to read this report:</strong> For each VAT period, the GL account balance (from posted journals)
        is compared against the VAT return totals and the source document (invoice/bill) totals.
        Variances of R0.00 are shown in <span class="ok">green</span>. Variances under R1.00 are <span class="warn">amber</span> (rounding).
        Variances over R1.00 are <span class="err">red</span> and should be investigated.
    </div>

    <?php if ($detail): ?>
    <form method="get" class="period-picker" style="margin-bottom:1rem;">
        <label for="period_id"><strong>Period detail:</strong></label>
        <select name="period_id" id="period_id" onchange="this.form.submit()">
            <?php foreach ($periods as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$selected['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['period_start'] . ' to ' . $p['period_end']) ?>
                (<?= htmlspecialchars(($p['basis'] ?: 'invoice') . ', ' . $p['status']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Load</button></noscript>
    </form>

    <h2>GL control vs VAT201 — <?= htmlspecialchars($detail['period']) ?>
        (<?= $detail['basis'] === 'payments' ? 'payments/cash basis' : 'invoice/accrual basis' ?>)</h2>
    <table>
        <thead>
            <tr class="section-header">
                <th></th>
                <th>GL control movement</th>
                <th>VAT201 box total</th>
                <th>Variance</th>
            </tr>
        </thead>
        <tbody>
            <?php
                $outVar = $detail['gl_out'] - $detail['box5'];
                $inVar  = $detail['gl_in'] - $detail['box9'];
                // On the payments basis the GL (accrual) is EXPECTED to differ
                // from the cash-basis boxes by the timing difference — colour
                // against the residual after timing, not the raw gap.
                $outResidual = $detail['timing'] ? ($detail['gl_out'] - $detail['timing']['inv_box5']) : $outVar;
                $inResidual  = $detail['timing'] ? ($detail['gl_in'] - $detail['timing']['inv_box9']) : $inVar;
            ?>
            <tr>
                <td>Output VAT (Box 5)</td>
                <td><?= fmtR($detail['gl_out']) ?></td>
                <td><?= fmtR($detail['box5']) ?></td>
                <td class="<?= varClass($outResidual) ?>"><?= fmtR($outVar) ?></td>
            </tr>
            <tr>
                <td>Input VAT (Box 9)</td>
                <td><?= fmtR($detail['gl_in']) ?></td>
                <td><?= fmtR($detail['box9']) ?></td>
                <td class="<?= varClass($inResidual) ?>"><?= fmtR($inVar) ?></td>
            </tr>
            <tr>
                <td>Net VAT (Box 10)</td>
                <td><?= fmtR($detail['gl_out'] - $detail['gl_in']) ?></td>
                <td><?= fmtR($detail['box10']) ?></td>
                <td class="<?= varClass($outResidual - $inResidual) ?>"><?= fmtR(($detail['gl_out'] - $detail['gl_in']) - $detail['box10']) ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($detail['timing']): $t = $detail['timing']; ?>
    <h2>Timing differences (accrual GL → cash-basis VAT201)</h2>
    <div class="info-box">
        The company accounts for VAT on the <strong>payments (cash) basis</strong> while the general ledger stays
        accrual. The rows below bridge the two: VAT already in the GL but not yet on the VAT201 because the
        consideration has not been received/paid (s15(2) VAT Act).
    </div>
    <table>
        <tbody>
            <tr class="section-header"><th colspan="2">Output VAT</th></tr>
            <tr>
                <td>GL output VAT control (accrual, per invoice basis)</td>
                <td><?= fmtR($t['inv_box5']) ?></td>
            </tr>
            <tr>
                <td>Less: output VAT invoiced but not yet received (timing)</td>
                <td><?= fmtR(-$t['output_timing']) ?></td>
            </tr>
            <tr>
                <td><strong>Cash-basis VAT201 Box 5</strong></td>
                <td><strong><?= fmtR($detail['box5']) ?></strong></td>
            </tr>
            <tr class="section-header"><th colspan="2">Input VAT</th></tr>
            <tr>
                <td>GL input VAT control (accrual, per invoice basis)</td>
                <td><?= fmtR($t['inv_box9']) ?></td>
            </tr>
            <tr>
                <td>Less: input VAT billed but not yet paid (timing)</td>
                <td><?= fmtR(-$t['input_timing']) ?></td>
            </tr>
            <tr>
                <td><strong>Cash-basis VAT201 Box 9</strong></td>
                <td><strong><?= fmtR($detail['box9']) ?></strong></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endif; ?>

    <h2>All periods: GL vs stored returns vs source documents</h2>
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
                <td><?= htmlspecialchars(ucfirst($r['status']) . ' (' . $r['basis'] . ')') ?></td>
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
    </div><!-- /fw-finance__paper -->
    </main>
    <footer class="fw-finance__footer">
        <span>VAT Reconciliation v<?= ASSET_VERSION ?></span>
        <span id="themeIndicator">Theme: Light</span>
    </footer>
    </div><!-- /fw-finance__container -->
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
