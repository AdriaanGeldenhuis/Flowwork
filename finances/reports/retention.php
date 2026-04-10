<?php
// /finances/reports/retention.php
//
// SARS Record Retention Report. SARS requires 5-year retention of all
// financial records (Tax Administration Act, Section 29). This report
// shows record counts by year, highlights records approaching the
// retention limit, and provides a retention policy overview.

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

requireRoles(['admin']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) { header('Location: /login.php'); exit; }

$retentionYears = 5;
$cutoffDate = date('Y-m-d', strtotime("-{$retentionYears} years"));
$currentYear = (int)date('Y');

// Gather record counts per table per year
$tables = [
    'Journal Entries' => [
        'sql' => "SELECT YEAR(entry_date) AS yr, COUNT(*) AS cnt FROM journal_entries WHERE company_id = ? AND status = 'posted' GROUP BY YEAR(entry_date) ORDER BY yr",
        'date_col' => 'entry_date'
    ],
    'Invoices (AR)' => [
        'sql' => "SELECT YEAR(issue_date) AS yr, COUNT(*) AS cnt FROM invoices WHERE company_id = ? GROUP BY YEAR(issue_date) ORDER BY yr",
        'date_col' => 'issue_date'
    ],
    'Bills (AP)' => [
        'sql' => "SELECT YEAR(issue_date) AS yr, COUNT(*) AS cnt FROM ap_bills WHERE company_id = ? GROUP BY YEAR(issue_date) ORDER BY yr",
        'date_col' => 'issue_date'
    ],
    'Customer Payments' => [
        'sql' => "SELECT YEAR(payment_date) AS yr, COUNT(*) AS cnt FROM payments WHERE company_id = ? GROUP BY YEAR(payment_date) ORDER BY yr",
        'date_col' => 'payment_date'
    ],
    'Supplier Payments' => [
        'sql' => "SELECT YEAR(payment_date) AS yr, COUNT(*) AS cnt FROM ap_payments WHERE company_id = ? GROUP BY YEAR(payment_date) ORDER BY yr",
        'date_col' => 'payment_date'
    ],
    'Credit Notes' => [
        'sql' => "SELECT YEAR(issue_date) AS yr, COUNT(*) AS cnt FROM credit_notes WHERE company_id = ? GROUP BY YEAR(issue_date) ORDER BY yr",
        'date_col' => 'issue_date'
    ],
    'Vendor Credits' => [
        'sql' => "SELECT YEAR(issue_date) AS yr, COUNT(*) AS cnt FROM vendor_credits WHERE company_id = ? GROUP BY YEAR(issue_date) ORDER BY yr",
        'date_col' => 'issue_date'
    ],
    'Audit Log' => [
        'sql' => "SELECT YEAR(timestamp) AS yr, COUNT(*) AS cnt FROM audit_log WHERE company_id = ? GROUP BY YEAR(timestamp) ORDER BY yr",
        'date_col' => 'timestamp'
    ],
];

$reportData = [];
foreach ($tables as $label => $cfg) {
    try {
        $stmt = $DB->prepare($cfg['sql']);
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $reportData[$label] = $rows;
    } catch (Exception $e) {
        $reportData[$label] = [];
    }
}

// Find all years present
$allYears = [];
foreach ($reportData as $rows) {
    foreach (array_keys($rows) as $yr) {
        $allYears[$yr] = true;
    }
}
ksort($allYears);
$years = array_keys($allYears);

// Total record counts
$totalRecords = 0;
$protectedRecords = 0;
foreach ($reportData as $rows) {
    foreach ($rows as $yr => $cnt) {
        $totalRecords += $cnt;
        if ($yr >= ($currentYear - $retentionYears)) {
            $protectedRecords += $cnt;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Retention Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 2rem; background-color: #f8f9fa; }
        a.back { display: inline-block; margin-bottom: 1rem; color: #0d6efd; text-decoration: none; }
        a.back:hover { text-decoration: underline; }
        h1 { margin-bottom: 0.3rem; }
        .subtitle { color: #6c757d; margin-bottom: 1.5rem; }

        .policy-box { background: #fff; border: 2px solid #1a3a5c; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem; max-width: 800px; }
        .policy-box h2 { margin-top: 0; font-size: 1.1rem; color: #1a3a5c; }
        .policy-box ul { margin: 0.5rem 0; padding-left: 1.5rem; line-height: 1.8; }
        .policy-box .highlight { background: #fff3cd; padding: 0.2rem 0.4rem; border-radius: 3px; }

        .summary { display: flex; gap: 1.5rem; margin-bottom: 2rem; flex-wrap: wrap; }
        .summary-card { background: #fff; padding: 1rem 1.5rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .summary-card .label { font-size: 0.75rem; color: #6c757d; }
        .summary-card .value { font-size: 1.3rem; font-weight: bold; }

        table { width: 100%; border-collapse: collapse; background: #fff; font-size: 0.82rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: right; }
        th { background-color: #f1f1f1; font-size: 0.75rem; }
        th:first-child, td:first-child { text-align: left; }
        .protected { background-color: #d4edda; }
        .expiring { background-color: #fff3cd; }
        .expired { background-color: #f8d7da; color: #721c24; }
        .legend { display: flex; gap: 1.5rem; margin-top: 1rem; font-size: 0.8rem; }
        .legend span { display: inline-flex; align-items: center; gap: 0.3rem; }
        .legend .swatch { width: 14px; height: 14px; border: 1px solid #ccc; display: inline-block; }

        @media print { body { padding: 0; background: #fff; } a.back { display: none; } }
    </style>
</head>
<body>
    <a class="back" href="/finances/">&larr; Back to Finance Dashboard</a>
    <h1>Record Retention Report</h1>
    <p class="subtitle">SARS compliance: Tax Administration Act, Section 29 – <?= $retentionYears ?>-year retention</p>

    <div class="policy-box">
        <h2>Retention Policy</h2>
        <ul>
            <li><strong>Retention Period:</strong> <span class="highlight"><?= $retentionYears ?> years</span> from the date of the transaction (SARS requirement)</li>
            <li><strong>Applicable Records:</strong> Journal entries, invoices, bills, payments, credit notes, audit logs</li>
            <li><strong>Cutoff Date:</strong> Records dated before <strong><?= $cutoffDate ?></strong> have exceeded the minimum retention period</li>
            <li><strong>Policy:</strong> All financial records are retained indefinitely. Records beyond the retention period are flagged but <strong>not automatically deleted</strong>. Deletion requires explicit admin authorisation.</li>
            <li><strong>Legal Basis:</strong> Tax Administration Act 28 of 2011, Section 29; Value-Added Tax Act 89 of 1991, Section 55(3)</li>
        </ul>
    </div>

    <div class="summary">
        <div class="summary-card">
            <div class="label">Total Records</div>
            <div class="value"><?= number_format($totalRecords) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Within Retention Period</div>
            <div class="value" style="color: #0a7d32;"><?= number_format($protectedRecords) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Beyond Retention Period</div>
            <div class="value" style="color: #6c757d;"><?= number_format($totalRecords - $protectedRecords) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Retention Cutoff</div>
            <div class="value"><?= $cutoffDate ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Record Type</th>
                <?php foreach ($years as $yr): ?>
                <th><?= $yr ?></th>
                <?php endforeach; ?>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reportData as $label => $rows):
                $rowTotal = array_sum($rows);
            ?>
            <tr>
                <td><?= htmlspecialchars($label) ?></td>
                <?php foreach ($years as $yr):
                    $cnt = $rows[$yr] ?? 0;
                    $cls = '';
                    if ($yr < ($currentYear - $retentionYears)) $cls = 'expired';
                    elseif ($yr == ($currentYear - $retentionYears)) $cls = 'expiring';
                    else $cls = 'protected';
                ?>
                <td class="<?= $cls ?>"><?= $cnt ? number_format($cnt) : '-' ?></td>
                <?php endforeach; ?>
                <td><strong><?= number_format($rowTotal) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="legend">
        <span><span class="swatch protected"></span> Within retention period</span>
        <span><span class="swatch expiring"></span> Approaching retention limit</span>
        <span><span class="swatch expired"></span> Beyond retention period</span>
    </div>
</body>
</html>
