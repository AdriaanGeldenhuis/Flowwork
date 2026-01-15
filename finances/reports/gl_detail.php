<?php
// /finances/reports/gl_detail.php
// General ledger detail report. Lists individual journal lines within a date
// range, optionally filtered by account code.

// Dynamically load init, auth and permissions based on project structure.
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

// Company name for header
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Ledger Detail – <?= htmlspecialchars($companyName) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 1.5rem; background: #f8f9fa; }
        h1 { margin-bottom: 1rem; }
        .filters { margin-bottom: 1rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        label { margin-right: 0.25rem; }
        input[type=date], input[type=text] { padding: 0.4rem; }
        button { padding: 0.5rem 1rem; margin-right: 0.5rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.9rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem; text-align: right; }
        th:nth-child(1), td:nth-child(1) { text-align: left; }
        th:nth-child(2), td:nth-child(2) { text-align: left; }
        th:nth-child(3), td:nth-child(3) { text-align: left; }
        tfoot { font-weight: bold; background: #eee; }
    </style>
</head>
<body>
    <h1>General Ledger Detail</h1>
    <div class="filters">
        <label for="startDate">Start Date:</label>
        <input type="date" id="startDate" value="<?= date('Y-m-01') ?>">
        <label for="endDate">End Date:</label>
        <input type="date" id="endDate" value="<?= date('Y-m-d') ?>">
        <label for="account">Account Code (optional):</label>
        <input type="text" id="account" placeholder="e.g. 4000">
        <button id="runBtn">Run Report</button>
        <button id="exportBtn" disabled>Export CSV</button>
    </div>
    <div id="reportContainer">Set filters and click "Run Report".</div>
    <script>
    (function() {
        const runBtn = document.getElementById('runBtn');
        const exportBtn = document.getElementById('exportBtn');
        const startDate = document.getElementById('startDate');
        const endDate = document.getElementById('endDate');
        const account = document.getElementById('account');
        const reportContainer = document.getElementById('reportContainer');
        let reportData = null;
        runBtn.addEventListener('click', function() {
            const sd = startDate.value;
            const ed = endDate.value;
            const acc = account.value.trim();
            if (!sd || !ed) { alert('Please select start and end dates'); return; }
            reportContainer.textContent = 'Loading...';
            exportBtn.disabled = true;
            let url = `/finances/ajax/report_export.php?report=gl_detail&start_date=${encodeURIComponent(sd)}&end_date=${encodeURIComponent(ed)}`;
            if (acc) url += `&account_code=${encodeURIComponent(acc)}`;
            fetch(url)
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
            const sd = startDate.value;
            const ed = endDate.value;
            const acc = account.value.trim();
            if (!sd || !ed) return;
            let url = `/finances/ajax/report_export.php?report=gl_detail&start_date=${encodeURIComponent(sd)}&end_date=${encodeURIComponent(ed)}&export=1`;
            if (acc) url += `&account_code=${encodeURIComponent(acc)}`;
            window.location.href = url;
        });
        function renderReport(data) {
            let html = '<table><thead><tr><th>Date</th><th>Journal ID</th><th>Account Code</th><th>Account Name</th><th>Description</th><th>Debit</th><th>Credit</th></tr></thead><tbody>';
            let totalDebit = 0;
            let totalCredit = 0;
            data.lines.forEach(item => {
                const d = item.debit_cents || 0;
                const c = item.credit_cents || 0;
                totalDebit += d;
                totalCredit += c;
                html += `<tr><td>${item.entry_date}</td><td>${item.journal_id}</td><td>${item.account_code}</td><td>${item.account_name || ''}</td><td>${item.description || ''}</td><td>${(d/100).toFixed(2)}</td><td>${(c/100).toFixed(2)}</td></tr>`;
            });
            html += '</tbody><tfoot><tr><td colspan="5">Totals</td><td>' + (totalDebit/100).toFixed(2) + '</td><td>' + (totalCredit/100).toFixed(2) + '</td></tr></tfoot></table>';
            reportContainer.innerHTML = html;
        }
    })();
    </script>
</body>
</html>