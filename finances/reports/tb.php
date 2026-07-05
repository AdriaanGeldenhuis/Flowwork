<?php
// /finances/reports/tb.php
// Simple trial balance report page. Allows users to select a date and
// generate a trial balance as of that date. Uses ajax/report_export.php
// with report=tb to fetch data.

// Dynamically load init, auth, and permissions. Determine project root two levels up.
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

// Allow finance admins, bookkeepers and viewers to view reports
requireRoles(['admin','bookkeeper','viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    header('Location: /login.php');
    exit;
}

// Get company name to display
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Balance – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
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
        .message { margin-top: 1rem; font-weight: bold; }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
    <?php $finTitle = 'Trial Balance'; $finBack = '/finances/reports.php'; include __DIR__ . '/../partials/header.php'; ?>
    <main class="fw-finance__main">
    <div class="fw-finance__paper">
    <h1>Trial Balance</h1>
    <div class="filters">
        <label for="reportDate">As of date:</label>
        <input type="date" id="reportDate" value="<?= date('Y-m-d') ?>">
        <button id="runBtn">Run Report</button>
        <button id="exportBtn" disabled>Export CSV</button>
    </div>
    <div id="reportContainer">Select a date and click "Run Report".</div>
    </div><!-- /fw-finance__paper -->
    </main>
    <footer class="fw-finance__footer">
        <span>Trial Balance v<?= ASSET_VERSION ?></span>
        <span id="themeIndicator">Theme: Light</span>
    </footer>
    </div><!-- /fw-finance__container -->
    <script>
    (function() {
        const runBtn = document.getElementById('runBtn');
        const exportBtn = document.getElementById('exportBtn');
        const reportDate = document.getElementById('reportDate');
        const reportContainer = document.getElementById('reportContainer');
        let reportData = null;
        runBtn.addEventListener('click', function() {
            const date = reportDate.value;
            if (!date) {
                alert('Please select a date');
                return;
            }
            reportContainer.textContent = 'Loading...';
            exportBtn.disabled = true;
            fetch(`/finances/ajax/report_export.php?report=tb&date=${encodeURIComponent(date)}`)
                .then(res => res.json())
                .then(json => {
                    if (!json.ok) {
                        reportContainer.textContent = json.error || 'Failed to generate report';
                        return;
                    }
                    reportData = json.data;
                    renderTable(reportData);
                    exportBtn.disabled = false;
                })
                .catch(err => {
                    reportContainer.textContent = 'Error: ' + err.message;
                });
        });
        exportBtn.addEventListener('click', function() {
            const date = reportDate.value;
            if (!date) return;
            window.location.href = `/finances/ajax/report_export.php?report=tb&date=${encodeURIComponent(date)}&export=1`;
        });
        function renderTable(data) {
            let html = '<table><thead><tr><th>Account Code</th><th>Account Name</th><th>Debit</th><th>Credit</th></tr></thead><tbody>';
            let totalDebit = 0;
            let totalCredit = 0;
            data.accounts.forEach(acc => {
                const debit = acc.debit_cents || 0;
                const credit = acc.credit_cents || 0;
                totalDebit += debit;
                totalCredit += credit;
                html += `<tr><td>${acc.account_code}</td><td>${acc.account_name || ''}</td><td>${(debit/100).toFixed(2)}</td><td>${(credit/100).toFixed(2)}</td></tr>`;
            });
            html += '</tbody><tfoot><tr><td colspan="2">Total</td><td>' + (totalDebit/100).toFixed(2) + '</td><td>' + (totalCredit/100).toFixed(2) + '</td></tr></tfoot></table>';
            reportContainer.innerHTML = html;
        }
    })();
    </script>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>