<?php
// /finances/reports/cashflow_indirect.php
// Standalone Cash Flow statement (Indirect method) report page.

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
    <title>Cash Flow Statement – <?= htmlspecialchars($companyName) ?></title>
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
        <?php $finTitle = 'Cash Flow (Indirect)'; $finBack = '/finances/reports.php'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/../partials/header.php'; ?>
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
            <span>Cash Flow (Indirect) v<?= ASSET_VERSION ?> <span id="reportInfo" style="margin-left: 1rem;"></span></span>
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
        let reportData = null;
        runBtn.addEventListener('click', async function() {
            const s = startDate.value;
            const e = endDate.value;
            if (!s || !e) { alert('Please select start and end dates'); return; }
            container.innerHTML = '<div class="fw-finance__loading"><div class="fw-finance__spinner"></div> Generating...</div>';
            exportBtn.disabled = true;
            reportInfo.textContent = '';
            try {
                const res  = await fetch(`/finances/ajax/report_cashflow_indirect.php?start_date=${encodeURIComponent(s)}&end_date=${encodeURIComponent(e)}`);
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
            rows.push(['Description','Amount']);
            const map = [
                ['Net Income', reportData.net_income_cents],
                ['Depreciation', reportData.depreciation_cents],
                ['Change in Accounts Receivable', reportData.change_ar_cents],
                ['Change in Inventory', reportData.change_inv_cents],
                ['Change in Accounts Payable', reportData.change_ap_cents],
                ['Net Cash from Operating Activities', reportData.operating_cents],
                ['Net Cash from Investing Activities', reportData.investing_cents],
                ['Net Cash from Financing Activities', reportData.financing_cents],
                ['Net Increase in Cash', reportData.net_cash_cents]
            ];
            map.forEach(([label, cents]) => {
                const amount = (cents/100).toFixed(2);
                rows.push([label, amount]);
            });
            const csv = rows.map(r => r.join(',')).join('\n');
            const blob= new Blob([csv], { type:'text/csv' });
            const url = URL.createObjectURL(blob);
            const a   = document.createElement('a');
            a.href = url;
            a.download = `cashflow_indirect_${startDate.value}_${endDate.value}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
        function renderReport(data) {
            // Build a table with description and amount
            const items = [
                ['Net Income', data.net_income_cents],
                ['Depreciation', data.depreciation_cents],
                ['Change in Accounts Receivable', data.change_ar_cents],
                ['Change in Inventory', data.change_inv_cents],
                ['Change in Accounts Payable', data.change_ap_cents],
                ['Net Cash from Operating Activities', data.operating_cents],
                ['Net Cash from Investing Activities', data.investing_cents],
                ['Net Cash from Financing Activities', data.financing_cents],
                ['Net Increase in Cash', data.net_cash_cents]
            ];
            let html = '';
            html += '<table class="report-table">';
            html += '<thead><tr><th>Description</th><th>Amount</th></tr></thead>';
            html += '<tbody>';
            items.forEach(([label, cents]) => {
                html += '<tr>';
                html += '<td>' + esc(label) + '</td>';
                html += '<td>' + formatCurrency(cents) + '</td>';
                html += '</tr>';
            });
            html += '</tbody>';
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
