<?php
// /finances/reports/capital_goods_vat.php
//
// Capital Goods VAT Report for SARS VAT201 Box 7 compliance.
// Lists all input VAT claimed on capital goods (fixed assets), showing
// the asset, supplier, VAT amount, and journal reference.

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
$vatInputCode = $accounts->get('finance_vat_input_account_id', '2130');

// Date range filter (validate format strictly to avoid DateTime exceptions in SQL)
$startDate = $_GET['start'] ?? date('Y-01-01');
$endDate   = $_GET['end'] ?? date('Y-m-d');
$dtS = DateTime::createFromFormat('Y-m-d', $startDate);
$dtE = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$dtS || $dtS->format('Y-m-d') !== $startDate) { $startDate = date('Y-01-01'); }
if (!$dtE || $dtE->format('Y-m-d') !== $endDate)   { $endDate   = date('Y-m-d'); }

// Method 1: Use is_capital_goods flag (for new records)
// Method 2: Heuristic detection (for historical records) - sibling line debits fixed asset
$stmt = $DB->prepare(
    "SELECT
        je.id AS journal_id,
        je.entry_date,
        je.reference,
        je.description AS journal_desc,
        jl.description AS line_desc,
        jl.debit AS vat_amount,
        jl.is_capital_goods,
        -- Get the asset account line from same journal
        (SELECT CONCAT(ga2.account_code, ' - ', ga2.account_name)
         FROM journal_lines jl2
         JOIN gl_accounts ga2 ON ga2.account_code = jl2.account_code AND ga2.company_id = je.company_id
         WHERE jl2.journal_id = je.id AND jl2.id != jl.id
           AND ga2.account_subtype = 'fixed_asset' AND jl2.debit > 0
         LIMIT 1) AS asset_account,
        -- Get asset cost from sibling line
        (SELECT jl2.debit
         FROM journal_lines jl2
         JOIN gl_accounts ga2 ON ga2.account_code = jl2.account_code AND ga2.company_id = je.company_id
         WHERE jl2.journal_id = je.id AND jl2.id != jl.id
           AND ga2.account_subtype = 'fixed_asset' AND jl2.debit > 0
         LIMIT 1) AS asset_cost,
        -- Get supplier name
        (SELECT s.name FROM crm_accounts s WHERE s.id = jl.supplier_id LIMIT 1) AS supplier_name
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_id
     WHERE je.company_id = ? AND je.status = 'posted'
       AND je.entry_date BETWEEN ? AND ?
       AND jl.account_code = ?
       AND jl.debit > 0
       AND (
           jl.is_capital_goods = 1
           OR EXISTS (
               SELECT 1 FROM journal_lines jl2
               JOIN gl_accounts ga ON ga.account_code = jl2.account_code AND ga.company_id = je.company_id
               WHERE jl2.journal_id = je.id AND jl2.id != jl.id
                 AND ga.account_subtype = 'fixed_asset' AND jl2.debit > 0
           )
       )
     ORDER BY je.entry_date DESC"
);
$stmt->execute([$companyId, $startDate, $endDate, $vatInputCode]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalVat = 0;
$totalCost = 0;
foreach ($entries as $e) {
    $totalVat += (float)$e['vat_amount'];
    $totalCost += (float)($e['asset_cost'] ?? 0);
}

function fmtR($val) { return 'R ' . number_format($val, 2, '.', ' '); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capital Goods VAT Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background-color: #f8f9fa; }
        a.back { display: inline-block; margin-bottom: 1rem; color: #0d6efd; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
        h1 { margin-bottom: 0.3rem; }
        .subtitle { color: #6c757d; margin-bottom: 1.5rem; }
        .controls { display: flex; gap: 0.75rem; align-items: end; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .controls label { font-weight: bold; font-size: 0.85rem; }
        .controls input { padding: 0.4rem; border: 1px solid #ccc; border-radius: 4px; }
        .controls button { padding: 0.5rem 1rem; background-color: #38ff12; color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .summary { display: flex; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .summary-card { background: #fff; padding: 1rem 1.5rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .summary-card .label { font-size: 0.75rem; color: #6c757d; }
        .summary-card .value { font-size: 1.2rem; font-weight: bold; font-family: monospace; }
        .info-box { background-color: #e7f3ff; border: 1px solid #b6d4fe; padding: 0.75rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #084298; max-width: 800px; }
        table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.82rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: left; }
        th { background-color: #f1f1f1; font-size: 0.75rem; }
        .num { text-align: right; font-family: monospace; }
        .total-row { font-weight: bold; background-color: #e9ecef; }
        @media print { body { padding: 0; background: #fff; } a.back, .controls { display: none; } }
    </style>
</head>
<body>
    <a class="back" href="/finances/vat.php">&larr; Back to VAT Returns</a>
    <h1>Capital Goods VAT Report</h1>
    <p class="subtitle">SARS VAT201 Box 7 – Input VAT on capital goods purchases</p>

    <div class="info-box">
        <strong>SARS VAT201 Box 7:</strong> Input tax on capital goods must be reported separately from other input tax.
        Capital goods are fixed assets (property, plant, equipment) where input VAT is claimed.
        This report lists all VAT input claims linked to fixed asset purchases.
    </div>

    <form class="controls" method="GET">
        <div>
            <label>From</label><br>
            <input type="date" name="start" value="<?= htmlspecialchars($startDate) ?>">
        </div>
        <div>
            <label>To</label><br>
            <input type="date" name="end" value="<?= htmlspecialchars($endDate) ?>">
        </div>
        <button type="submit">Filter</button>
    </form>

    <div class="summary">
        <div class="summary-card">
            <div class="label">Capital Goods Transactions</div>
            <div class="value"><?= count($entries) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Asset Cost (excl VAT)</div>
            <div class="value"><?= fmtR($totalCost) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Input VAT (Box 7)</div>
            <div class="value" style="color: #0a7d32;"><?= fmtR($totalVat) ?></div>
        </div>
    </div>

    <?php if (empty($entries)): ?>
        <p>No capital goods VAT claims found for the selected period.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Supplier</th>
                <th>Asset Account</th>
                <th>Asset Cost (ZAR)</th>
                <th>VAT Claimed (ZAR)</th>
                <th>Journal ID</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['entry_date']) ?></td>
                <td><?= htmlspecialchars($e['reference']) ?></td>
                <td><?= htmlspecialchars($e['supplier_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($e['asset_account'] ?? '-') ?></td>
                <td class="num"><?= $e['asset_cost'] ? fmtR((float)$e['asset_cost']) : '-' ?></td>
                <td class="num"><?= fmtR((float)$e['vat_amount']) ?></td>
                <td><?= (int)$e['journal_id'] ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="4">TOTAL</td>
                <td class="num"><?= fmtR($totalCost) ?></td>
                <td class="num"><?= fmtR($totalVat) ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
</body>
</html>
