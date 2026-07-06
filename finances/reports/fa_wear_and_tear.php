<?php
// /finances/reports/fa_wear_and_tear.php
//
// SARS Wear-and-Tear (tax allowance) register. For every fixed asset with a
// tax write-off election (s11(e) wear-and-tear or s12C manufacturing assets)
// it shows the current fiscal-year tax allowance, the accumulated allowance
// to date, the book accumulated depreciation, and the temporary difference
// between the two (deferred-tax base).
//
//   s11(e): straight line, cost / tax_writeoff_years per full year.
//   s12C:   40 / 20 / 20 / 20 over four years.

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

require_once __DIR__ . '/../lib/CoaSchema.php';

requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

// Determine output format
$format = $_GET['format'] ?? 'html';

// Current fiscal year window
$fyStart = CoaSchema::fiscalYearStartDate($DB, (int)$companyId);
$fyEnd = date('Y-m-d', strtotime($fyStart . ' +1 year -1 day'));

// Fetch assets with a tax write-off election
$stmt = $DB->prepare(
    "SELECT fa.* FROM gl_fixed_assets fa
     WHERE fa.company_id = ? AND fa.tax_method <> 'none'
     ORDER BY fa.category, fa.asset_name"
);
$stmt->execute([$companyId]);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Tax allowance figures for one asset, in cents.
 * Year index 1 is the fiscal year the asset was brought into use.
 *
 * @return array{current:int, accumulated:int, year_index:int}
 */
function wtAllowance(array $asset, string $fyStart, PDO $DB, int $companyId): array
{
    $cost = (int)$asset['purchase_cost_cents'];
    $method = $asset['tax_method'];
    $years = (int)($asset['tax_writeoff_years'] ?: ($method === 's12c' ? 4 : 0));
    if ($method === 's12c') {
        $years = 4;
    }
    if ($cost <= 0 || $years <= 0) {
        return ['current' => 0, 'accumulated' => 0, 'year_index' => 0];
    }

    // Fiscal year in which the asset was brought into use (purchase date).
    $purchaseFyStart = CoaSchema::fiscalYearStartDate($DB, $companyId, $asset['purchase_date']);
    $idx = (int)(new DateTime($purchaseFyStart))->diff(new DateTime($fyStart))->y + 1;
    if ($idx < 1) {
        return ['current' => 0, 'accumulated' => 0, 'year_index' => $idx];
    }

    // Per-year allowance schedule; the final year absorbs rounding so the
    // allowances always sum exactly to cost.
    $schedule = [];
    if ($method === 's12c') {
        $schedule[] = (int)round($cost * 0.40);
        $schedule[] = (int)round($cost * 0.20);
        $schedule[] = (int)round($cost * 0.20);
        $schedule[] = $cost - array_sum($schedule);
    } else { // s11e straight line
        $annual = intdiv($cost, $years);
        for ($i = 1; $i < $years; $i++) {
            $schedule[] = $annual;
        }
        $schedule[] = $cost - ($annual * ($years - 1));
    }

    $current = ($idx <= count($schedule)) ? $schedule[$idx - 1] : 0;
    $accumulated = array_sum(array_slice($schedule, 0, min($idx, count($schedule))));
    return ['current' => $current, 'accumulated' => $accumulated, 'year_index' => $idx];
}

// Build report rows + totals
$rows = [];
$totCost = 0;
$totCurrent = 0;
$totAccumTax = 0;
$totBookDepr = 0;
$totTempDiff = 0;
foreach ($assets as $a) {
    $wt = wtAllowance($a, $fyStart, $DB, (int)$companyId);
    $bookDepr = (int)$a['accumulated_depreciation_cents'];
    $tempDiff = $wt['accumulated'] - $bookDepr;
    $rows[] = [
        'asset'       => $a,
        'current'     => $wt['current'],
        'accumulated' => $wt['accumulated'],
        'book_depr'   => $bookDepr,
        'temp_diff'   => $tempDiff,
        'year_index'  => $wt['year_index'],
    ];
    $totCost += (int)$a['purchase_cost_cents'];
    $totCurrent += $wt['current'];
    $totAccumTax += $wt['accumulated'];
    $totBookDepr += $bookDepr;
    $totTempDiff += $tempDiff;
}

$methodLabel = fn(string $m): string => $m === 's12c' ? 's12C (40/20/20/20)' : 's11(e) wear-and-tear';

// CSV export
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="wear_and_tear_register_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fiscal year', $fyStart . ' to ' . $fyEnd], ',', '"', '\\');
    fputcsv($out, [
        'Asset Name', 'Category', 'Status', 'Date in Use', 'Cost (ZAR)',
        'Tax Method', 'Write-off Years', 'Tax Year #',
        'Current FY Allowance (ZAR)', 'Accumulated Tax Allowance (ZAR)',
        'Book Accumulated Depreciation (ZAR)', 'Temporary Difference (ZAR)'
    ], ',', '"', '\\');
    foreach ($rows as $r) {
        $a = $r['asset'];
        fputcsv($out, [
            $a['asset_name'],
            $a['category'] ?? '',
            $a['status'],
            $a['purchase_date'],
            number_format($a['purchase_cost_cents'] / 100, 2, '.', ''),
            $methodLabel($a['tax_method']),
            (int)($a['tax_writeoff_years'] ?: ($a['tax_method'] === 's12c' ? 4 : 0)),
            $r['year_index'],
            number_format($r['current'] / 100, 2, '.', ''),
            number_format($r['accumulated'] / 100, 2, '.', ''),
            number_format($r['book_depr'] / 100, 2, '.', ''),
            number_format($r['temp_diff'] / 100, 2, '.', ''),
        ], ',', '"', '\\');
    }
    fputcsv($out, [], ',', '"', '\\');
    fputcsv($out, ['TOTALS', '', '', '',
        number_format($totCost / 100, 2, '.', ''),
        '', '', '',
        number_format($totCurrent / 100, 2, '.', ''),
        number_format($totAccumTax / 100, 2, '.', ''),
        number_format($totBookDepr / 100, 2, '.', ''),
        number_format($totTempDiff / 100, 2, '.', ''),
    ], ',', '"', '\\');
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
    <title>Wear-and-Tear Register</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
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
<body class="fw-finance">
    <div class="fw-finance__container">
    <?php $finTitle = 'Wear-and-Tear Register'; $finBack = '/finances/reports.php'; include __DIR__ . '/../partials/header.php'; ?>
    <main class="fw-finance__main">
    <div class="fw-finance__paper">
    <h1>Wear-and-Tear Register (Tax Allowances)</h1>
    <p class="subtitle">s11(e) / s12C tax allowances for fiscal year <?= htmlspecialchars($fyStart) ?> to <?= htmlspecialchars($fyEnd) ?></p>

    <div class="summary">
        <div class="summary-card">
            <div class="label">Elected Assets</div>
            <div class="value"><?= count($rows) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Current FY Allowance</div>
            <div class="value"><?= fmtZAR($totCurrent) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Accumulated Tax Allowance</div>
            <div class="value"><?= fmtZAR($totAccumTax) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Temporary Difference</div>
            <div class="value"><?= fmtZAR($totTempDiff) ?></div>
        </div>
    </div>

    <div class="controls">
        <a href="?format=csv">Export CSV</a>
        <a href="javascript:window.print()">Print</a>
    </div>

    <?php if (empty($rows)): ?>
        <p>No assets carry a tax write-off election. Set the "Tax Write-off Method" when capturing a fixed asset.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Asset Name</th>
                <th>Category</th>
                <th>Status</th>
                <th>Date in Use</th>
                <th>Tax Method</th>
                <th>Years</th>
                <th>Tax Year #</th>
                <th>Cost (ZAR)</th>
                <th>Current FY Allowance</th>
                <th>Accum. Tax Allowance</th>
                <th>Book Accum. Depr.</th>
                <th>Temporary Difference</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): $a = $r['asset']; ?>
            <tr>
                <td><?= htmlspecialchars($a['asset_name']) ?></td>
                <td><?= htmlspecialchars($a['category'] ?? '') ?></td>
                <td class="status-<?= $a['status'] === 'active' ? 'active' : 'disposed' ?>"><?= ucfirst(htmlspecialchars($a['status'])) ?></td>
                <td><?= htmlspecialchars($a['purchase_date']) ?></td>
                <td><?= htmlspecialchars($methodLabel($a['tax_method'])) ?></td>
                <td class="num"><?= (int)($a['tax_writeoff_years'] ?: ($a['tax_method'] === 's12c' ? 4 : 0)) ?></td>
                <td class="num"><?= (int)$r['year_index'] ?></td>
                <td class="num"><?= fmtZAR($a['purchase_cost_cents']) ?></td>
                <td class="num"><?= fmtZAR($r['current']) ?></td>
                <td class="num"><?= fmtZAR($r['accumulated']) ?></td>
                <td class="num"><?= fmtZAR($r['book_depr']) ?></td>
                <td class="num"><?= fmtZAR($r['temp_diff']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="7">TOTALS</td>
                <td class="num"><?= fmtZAR($totCost) ?></td>
                <td class="num"><?= fmtZAR($totCurrent) ?></td>
                <td class="num"><?= fmtZAR($totAccumTax) ?></td>
                <td class="num"><?= fmtZAR($totBookDepr) ?></td>
                <td class="num"><?= fmtZAR($totTempDiff) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
    </div><!-- /fw-finance__paper -->
    </main>
    <footer class="fw-finance__footer">
        <span>Wear-and-Tear Register v<?= ASSET_VERSION ?></span>
        <span id="themeIndicator">Theme: Light</span>
    </footer>
    </div><!-- /fw-finance__container -->
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
