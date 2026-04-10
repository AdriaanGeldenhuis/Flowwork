<?php
// /finances/reports/fa_schedule.php
//
// Fixed Asset Schedule Report for SARS audit purposes.
// Shows all assets with cost, accumulated depreciation, net book value,
// and disposal details. Exportable to CSV.

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

requireRoles(['admin', 'bookkeeper', 'viewer']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

// Determine output format
$format = $_GET['format'] ?? 'html';

// Fetch all fixed assets with GL account names
$stmt = $DB->prepare(
    "SELECT fa.*,
            ga_asset.account_code AS asset_account_code,
            ga_asset.account_name AS asset_account_name,
            ga_depr.account_code AS depr_expense_code,
            ga_depr.account_name AS depr_expense_name,
            ga_accum.account_code AS accum_depr_code,
            ga_accum.account_name AS accum_depr_name
     FROM gl_fixed_assets fa
     LEFT JOIN gl_accounts ga_asset ON ga_asset.account_id = fa.asset_account_id AND ga_asset.company_id = fa.company_id
     LEFT JOIN gl_accounts ga_depr ON ga_depr.account_id = fa.depreciation_expense_account_id AND ga_depr.company_id = fa.company_id
     LEFT JOIN gl_accounts ga_accum ON ga_accum.account_id = fa.accumulated_depreciation_account_id AND ga_accum.company_id = fa.company_id
     WHERE fa.company_id = ?
     ORDER BY fa.category, fa.asset_name"
);
$stmt->execute([$companyId]);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalCost = 0;
$totalAccumDepr = 0;
$totalNBV = 0;
$totalSalvage = 0;
foreach ($assets as $a) {
    $totalCost += $a['purchase_cost_cents'];
    $totalAccumDepr += $a['accumulated_depreciation_cents'];
    $totalNBV += ($a['purchase_cost_cents'] - $a['accumulated_depreciation_cents']);
    $totalSalvage += $a['salvage_value_cents'];
}

// CSV export
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fixed_asset_schedule_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Asset Name', 'Category', 'Status', 'Purchase Date',
        'Cost (ZAR)', 'Salvage Value (ZAR)', 'Useful Life (months)',
        'Depreciation Method', 'Accumulated Depreciation (ZAR)',
        'Net Book Value (ZAR)', 'Asset Account', 'Depreciation Expense Account',
        'Accumulated Depreciation Account', 'Disposal Date', 'Disposal Proceeds (ZAR)'
    ]);
    foreach ($assets as $a) {
        $nbv = ($a['purchase_cost_cents'] - $a['accumulated_depreciation_cents']) / 100;
        fputcsv($out, [
            $a['asset_name'],
            $a['category'] ?? '',
            $a['status'],
            $a['purchase_date'],
            number_format($a['purchase_cost_cents'] / 100, 2, '.', ''),
            number_format($a['salvage_value_cents'] / 100, 2, '.', ''),
            $a['useful_life_months'],
            $a['depreciation_method'],
            number_format($a['accumulated_depreciation_cents'] / 100, 2, '.', ''),
            number_format($nbv, 2, '.', ''),
            ($a['asset_account_code'] ?? '') . ' ' . ($a['asset_account_name'] ?? ''),
            ($a['depr_expense_code'] ?? '') . ' ' . ($a['depr_expense_name'] ?? ''),
            ($a['accum_depr_code'] ?? '') . ' ' . ($a['accum_depr_name'] ?? ''),
            $a['disposal_date'] ?? '',
            $a['disposal_proceeds_cents'] ? number_format($a['disposal_proceeds_cents'] / 100, 2, '.', '') : ''
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['TOTALS', '', '', '',
        number_format($totalCost / 100, 2, '.', ''),
        number_format($totalSalvage / 100, 2, '.', ''),
        '', '',
        number_format($totalAccumDepr / 100, 2, '.', ''),
        number_format($totalNBV / 100, 2, '.', ''),
        '', '', '', '', ''
    ]);
    fclose($out);
    exit;
}

function fmtZAR($cents) { return 'R ' . number_format($cents / 100, 2, '.', ' '); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixed Asset Schedule</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background-color: #f8f9fa; }
        a.back { display: inline-block; margin-bottom: 1rem; color: #0d6efd; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
        h1 { margin-bottom: 0.3rem; }
        .subtitle { color: #6c757d; margin-bottom: 1.5rem; }
        .controls { margin-bottom: 1rem; }
        .controls a { display: inline-block; padding: 0.5rem 1rem; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 4px; margin-right: 0.5rem; font-size: 0.85rem; }
        .controls a:hover { background-color: #0b5ed7; }
        table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.8rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.5rem; text-align: left; }
        th { background-color: #f1f1f1; font-size: 0.75rem; white-space: nowrap; }
        .num { text-align: right; font-family: monospace; }
        .total-row { font-weight: bold; background-color: #e9ecef; }
        .category-row { background-color: #f8f9fa; font-weight: bold; }
        .status-active { color: #0a7d32; }
        .status-disposed { color: #dc3545; }
        .summary { display: flex; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .summary-card { background: #fff; padding: 1rem 1.5rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .summary-card .label { font-size: 0.75rem; color: #6c757d; }
        .summary-card .value { font-size: 1.2rem; font-weight: bold; font-family: monospace; }
        @media print {
            body { padding: 0; background: #fff; }
            a.back, .controls { display: none; }
        }
    </style>
</head>
<body>
    <a class="back" href="/finances/fa/">&larr; Back to Fixed Assets</a>
    <h1>Fixed Asset Schedule</h1>
    <p class="subtitle">Complete register of all fixed assets as at <?= date('d F Y') ?></p>

    <div class="summary">
        <div class="summary-card">
            <div class="label">Total Assets</div>
            <div class="value"><?= count($assets) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Cost</div>
            <div class="value"><?= fmtZAR($totalCost) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Accumulated Depreciation</div>
            <div class="value"><?= fmtZAR($totalAccumDepr) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Net Book Value</div>
            <div class="value"><?= fmtZAR($totalNBV) ?></div>
        </div>
    </div>

    <div class="controls">
        <a href="?format=csv">Export CSV</a>
        <a href="javascript:window.print()">Print</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>Asset Name</th>
                <th>Category</th>
                <th>Status</th>
                <th>Purchase Date</th>
                <th>Method</th>
                <th>Life (months)</th>
                <th>Cost (ZAR)</th>
                <th>Salvage (ZAR)</th>
                <th>Accum. Depr. (ZAR)</th>
                <th>NBV (ZAR)</th>
                <th>Disposal Date</th>
                <th>Proceeds (ZAR)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $currentCategory = null;
            foreach ($assets as $a):
                $nbv = $a['purchase_cost_cents'] - $a['accumulated_depreciation_cents'];
                if ($a['category'] !== $currentCategory):
                    $currentCategory = $a['category'];
            ?>
            <tr class="category-row">
                <td colspan="12"><?= htmlspecialchars($currentCategory ?: 'Uncategorised') ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td><?= htmlspecialchars($a['asset_name']) ?></td>
                <td><?= htmlspecialchars($a['category'] ?? '') ?></td>
                <td class="status-<?= $a['status'] === 'active' ? 'active' : 'disposed' ?>"><?= ucfirst(htmlspecialchars($a['status'])) ?></td>
                <td><?= htmlspecialchars($a['purchase_date']) ?></td>
                <td><?= htmlspecialchars(str_replace('_', ' ', $a['depreciation_method'])) ?></td>
                <td class="num"><?= (int)$a['useful_life_months'] ?></td>
                <td class="num"><?= fmtZAR($a['purchase_cost_cents']) ?></td>
                <td class="num"><?= fmtZAR($a['salvage_value_cents']) ?></td>
                <td class="num"><?= fmtZAR($a['accumulated_depreciation_cents']) ?></td>
                <td class="num"><?= fmtZAR($nbv) ?></td>
                <td><?= htmlspecialchars($a['disposal_date'] ?? '-') ?></td>
                <td class="num"><?= $a['disposal_proceeds_cents'] ? fmtZAR($a['disposal_proceeds_cents']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="6">TOTALS</td>
                <td class="num"><?= fmtZAR($totalCost) ?></td>
                <td class="num"><?= fmtZAR($totalSalvage) ?></td>
                <td class="num"><?= fmtZAR($totalAccumDepr) ?></td>
                <td class="num"><?= fmtZAR($totalNBV) ?></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
