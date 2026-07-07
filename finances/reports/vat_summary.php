<?php
// /finances/reports/vat_summary.php
// Standalone VAT Summary report page.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and allow admin, bookkeeper and viewer roles
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch company and user names
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = $stmt->fetchColumn() ?: 'User';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VAT Summary – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .filters { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
        table.report-table { width: 100%; border-collapse: collapse; }
        table.report-table th, table.report-table td { border: 1px solid var(--fw-border); padding: 0.4rem; text-align: right; }
        table.report-table th:first-child, table.report-table td:first-child { text-align: left; }
        tfoot tr { background: var(--fw-bg-secondary); font-weight: bold; }
    </style>
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php $finTitle = 'VAT Summary'; $finBack = '/finances/reports.php'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/../partials/header.php'; ?>
        <main class="fw-finance__main">
            <!-- Filters -->
            <div class="filters">
                <label for="startDate">Start Date:</label>
                <input type="date" id="startDate" value="<?= date('Y-01-01') ?>">
                <label for="endDate">End Date:</label>
                <input type="date" id="endDate" value="<?= date('Y-m-d') ?>">
                <button class="fw-finance__btn fw-finance__btn--primary" id="runBtn">Run Report</button>
                <button class="fw-finance__btn fw-finance__btn--secondary" id="exportBtn" disabled>Export CSV</button>
            </div>
            <!-- Report Container -->
            <div id="reportContainer" class="fw-finance__report-content">
                <div class="fw-finance__empty-state">Select dates and click "Run Report"</div>
            </div>
        </main>
        <footer class="fw-finance__footer">
            <span>VAT Summary v<?= ASSET_VERSION ?> <span id="reportInfo" style="margin-left: 1rem;"></span></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    (function() {
        'use strict';
        // XSS-safe HTML escape helper
        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        const runBtn    = document.getElementById('runBtn');
        const exportBtn = document.getElementById('exportBtn');
        const startDate = document.getElementById('startDate');
        const endDate   = document.getElementById('endDate');
        const container = document.getElementById('reportContainer');
        const reportInfo= document.getElementById('reportInfo');
        let reportData  = null;
        runBtn.addEventListener('click', async function() {
            const s = startDate.value;
            const e = endDate.value;
            if (!s || !e) { alert('Please select start and end dates'); return; }
            container.innerHTML = '<div class="fw-finance__loading"><div class="fw-finance__spinner"></div> Generating...</div>';
            exportBtn.disabled = true;
            reportInfo.textContent = '';
            try {
                const res  = await fetch(`/finances/ajax/report_vat_summary.php?start_date=${encodeURIComponent(s)}&end_date=${encodeURIComponent(e)}`);
                const json = await res.json();
                if (!json.ok) {
                    container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + esc(json.error || 'Failed to generate report') + '</div>';
                    return;
                }
                reportData = json.data;
                renderReport(reportData);
                exportBtn.disabled = false;
                reportInfo.textContent = 'Generated: ' + new Date().toLocaleString();
            } catch (err) {
                container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + esc(err.message) + '</div>';
            }
        });
        exportBtn.addEventListener('click', function() {
            if (!reportData) return;
            const rows = [];
            rows.push(['Period','Output VAT','Input VAT','Net VAT']);
            reportData.periods.forEach(period => {
                rows.push([
                    period.period,
                    (period.output_vat_cents/100).toFixed(2),
                    (period.input_vat_cents/100).toFixed(2),
                    (period.net_vat_cents/100).toFixed(2)
                ]);
            });
            // totals row
            const t = reportData.totals;
            rows.push(['Total',(t.output_vat_cents/100).toFixed(2),(t.input_vat_cents/100).toFixed(2),(t.net_vat_cents/100).toFixed(2)]);
            const csv = rows.map(r => r.join(',')).join('\n');
            const blob= new Blob([csv], { type:'text/csv' });
            const url = URL.createObjectURL(blob);
            const a   = document.createElement('a');
            a.href = url;
            a.download = `vat_summary_${startDate.value}_${endDate.value}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
        function renderReport(data) {
            let html = '';
            html += '<table class="report-table">';
            html += '<thead><tr><th>Period</th><th>Output VAT</th><th>Input VAT</th><th>Net VAT</th></tr></thead>';
            html += '<tbody>';
            (data.periods || []).forEach(row => {
                html += '<tr>';
                html += '<td>' + esc(row.period) + '</td>';
                html += '<td>' + formatCurrency(row.output_vat_cents) + '</td>';
                html += '<td>' + formatCurrency(row.input_vat_cents) + '</td>';
                html += '<td>' + formatCurrency(row.net_vat_cents) + '</td>';
                html += '</tr>';
            });
            html += '</tbody>';
            const tot = data.totals;
            html += '<tfoot><tr>';
            html += '<td><strong>Total</strong></td>';
            html += '<td><strong>' + formatCurrency(tot.output_vat_cents) + '</strong></td>';
            html += '<td><strong>' + formatCurrency(tot.input_vat_cents) + '</strong></td>';
            html += '<td><strong>' + formatCurrency(tot.net_vat_cents) + '</strong></td>';
            html += '</tr></tfoot>';
            html += '</table>';
            container.innerHTML = html;
        }
        function formatCurrency(cents) {
            const num = cents / 100;
            return num.toLocaleString(undefined, { style:'currency', currency:'ZAR' });
        }
    })();
    </script>
</body>
</html>
