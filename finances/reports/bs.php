<?php
// /finances/reports/bs.php
// Simple balance sheet report page. Users pick an end date; we show
// balances for asset, liability and equity accounts as of that date.

// Dynamically load init, auth and permissions depending on project structure.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}

requireRoles(['admin','bookkeeper','viewer']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    header('Location: /login.php');
    exit;
}
// Get company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet – <?= htmlspecialchars($companyName) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 1.5rem; background: #f8f9fa; }
        h1 { margin-bottom: 1rem; }
        .filters { margin-bottom: 1rem; }
        label { margin-right: 0.5rem; }
        input[type=date] { padding: 0.4rem; }
        button { padding: 0.5rem 1rem; margin-right: 0.5rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #ddd; padding: 0.5rem; text-align: right; }
        th:first-child, td:first-child { text-align: left; }
        th:nth-child(2), td:nth-child(2) { text-align: left; }
        tfoot { font-weight: bold; background: #eee; }
    </style>
</head>
<body>
    <h1>Balance Sheet</h1>
    <div class="filters">
        <label for="reportDate">As of date:</label>
        <input type="date" id="reportDate" value="<?= date('Y-m-d') ?>">
        <button id="runBtn">Run Report</button>
        <button id="exportBtn" disabled>Export CSV</button>
    </div>
    <div id="reportContainer">Select a date and click "Run Report".</div>
    <script>
    (function() {
        const runBtn = document.getElementById('runBtn');
        const exportBtn = document.getElementById('exportBtn');
        const reportDate = document.getElementById('reportDate');
        const reportContainer = document.getElementById('reportContainer');
        let reportData = null;
        runBtn.addEventListener('click', function() {
            const date = reportDate.value;
            if (!date) { alert('Select a date'); return; }
            reportContainer.textContent = 'Loading...';
            exportBtn.disabled = true;
            fetch(`/finances/ajax/report_export.php?report=bs&date=${encodeURIComponent(date)}`)
                .then(res => res.json())
                .then(json => {
                    if (!json.ok) { reportContainer.textContent = json.error || 'Failed'; return; }
                    reportData = json.data;
                    renderReport(reportData);
                    exportBtn.disabled = false;
                })
                .catch(err => { reportContainer.textContent = 'Error: ' + err.message; });
        });
        exportBtn.addEventListener('click', function() {
            const date = reportDate.value;
            if (!date) return;
            window.location.href = `/finances/ajax/report_export.php?report=bs&date=${encodeURIComponent(date)}&export=1`;
        });
        function renderReport(data) {
            let html = '';
            function section(title, accounts) {
                let total = 0;
                let rows = '';
                accounts.forEach(row => {
                    const bal = row.balance_cents || 0;
                    total += bal;
                    rows += `<tr><td>${row.account_code}</td><td>${row.account_name}</td><td>${(bal/100).toFixed(2)}</td></tr>`;
                });
                let table = `<h2>${title}</h2><table><thead><tr><th>Account Code</th><th>Account Name</th><th>Balance</th></tr></thead><tbody>${rows}</tbody><tfoot><tr><td colspan="2">Total ${title}</td><td>${(total/100).toFixed(2)}</td></tr></tfoot></table>`;
                return {html: table, total: total};
            }
            // Assets
            const assetsRes = section('Assets', data.assets);
            // Liabilities
            const liabRes = section('Liabilities', data.liabilities);
            // Equity
            const equityRes = section('Equity', data.equity);
            html = assetsRes.html + liabRes.html + equityRes.html;
            // Basic check
            const totalAssets = assetsRes.total;
            const totalLE = liabRes.total + equityRes.total;
            html += `<h3>Total Assets: ${(totalAssets/100).toFixed(2)} &nbsp;&nbsp; Total Liabilities + Equity: ${(totalLE/100).toFixed(2)}</h3>`;
            reportContainer.innerHTML = html;
        }
    })();
    </script>
</body>
</html>