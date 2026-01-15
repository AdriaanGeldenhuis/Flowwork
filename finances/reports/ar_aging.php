<?php
// /finances/reports/ar_aging.php
// Standalone Accounts Receivable Aging report page.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and allow admin, bookkeeper and viewer roles
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', '2025-02-10-AR-AGING');

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
    <title>AR Aging – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        .filters { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; }
        table.report-table { width: 100%; border-collapse: collapse; }
        table.report-table th, table.report-table td { border: 1px solid var(--fw-border); padding: 0.4rem; text-align: right; }
        table.report-table th:first-child, table.report-table td:first-child { text-align: left; }
        tfoot tr { background: var(--fw-bg-secondary); font-weight: bold; }
    </style>
</head>
<body>
    <main class="fw-finance">
        <div class="fw-finance__container">
            <header class="fw-finance__header">
                <div class="fw-finance__brand">
                    <div class="fw-finance__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="fw-finance__brand-text">
                        <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-finance__app-name">AR Aging</div>
                    </div>
                </div>
                <div class="fw-finance__greeting">Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span></div>
                <div class="fw-finance__controls">
                    <a href="/finances/reports.php" class="fw-finance__back-btn" title="Back to Reports">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a href="/finances/" class="fw-finance__home-btn" title="Finance Dashboard">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <button class="fw-finance__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-finance__theme-icon fw-finance__theme-icon--light" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                            <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="1" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="21" y1="12" x2="23" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <svg class="fw-finance__theme-icon fw-finance__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </header>
            <!-- Filters -->
            <div class="filters">
                <label for="reportDate">As at Date:</label>
                <input type="date" id="reportDate" value="<?= date('Y-m-d') ?>">
                <button class="fw-finance__btn fw-finance__btn--primary" id="runBtn">Run Report</button>
                <button class="fw-finance__btn fw-finance__btn--secondary" id="exportBtn" disabled>Export CSV</button>
            </div>
            <!-- Report Container -->
            <div id="reportContainer" class="fw-finance__report-content">
                <div class="fw-finance__empty-state">Select a date and click "Run Report"</div>
            </div>
            <footer class="fw-finance__footer">
                <span>AR Aging v<?= ASSET_VERSION ?></span>
                <span id="reportInfo" style="margin-left: 1rem;"></span>
            </footer>
        </div>
    </main>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    (function() {
        'use strict';
        const runBtn = document.getElementById('runBtn');
        const exportBtn = document.getElementById('exportBtn');
        const reportDate = document.getElementById('reportDate');
        const container = document.getElementById('reportContainer');
        const reportInfo = document.getElementById('reportInfo');
        let reportData = null;
        runBtn.addEventListener('click', async function() {
            const date = reportDate.value;
            if (!date) { alert('Please select a date'); return; }
            container.innerHTML = '<div class="fw-finance__loading"><div class="fw-finance__spinner"></div> Generating...</div>';
            exportBtn.disabled = true;
            reportInfo.textContent = '';
            try {
                const res = await fetch(`/finances/ajax/report_ar_aging.php?date=${encodeURIComponent(date)}`);
                const json = await res.json();
                if (!json.ok) {
                    container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + (json.error || 'Failed to generate report') + '</div>';
                    return;
                }
                reportData = json.data;
                renderReport(reportData);
                exportBtn.disabled = false;
                reportInfo.textContent = 'Generated: ' + new Date().toLocaleString();
            } catch (e) {
                container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + e.message + '</div>';
            }
        });
        exportBtn.addEventListener('click', function() {
            if (!reportData) return;
            const csvRows = [];
            csvRows.push(['Customer','Current','1-30','31-60','61-90','90+','Total']);
            reportData.forEach(row => {
                csvRows.push([
                    row.customer_name,
                    (row.current || 0).toFixed(2),
                    (row.days_1_30 || 0).toFixed(2),
                    (row.days_31_60 || 0).toFixed(2),
                    (row.days_61_90 || 0).toFixed(2),
                    (row.days_90_plus || 0).toFixed(2),
                    (row.total || 0).toFixed(2)
                ]);
            });
            const csv = csvRows.map(r => r.join(',')).join('\n');
            const blob = new Blob([csv], { type:'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `ar_aging_${reportDate.value}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
        function renderReport(data) {
            let html = '';
            html += '<table class="report-table">';
            html += '<thead><tr><th>Customer</th><th>Current</th><th>1-30</th><th>31-60</th><th>61-90</th><th>90+</th><th>Total</th></tr></thead>';
            html += '<tbody>';
            let totals = { current:0, days_1_30:0, days_31_60:0, days_61_90:0, days_90_plus:0, total:0 };
            data.forEach(row => {
                html += '<tr>';
                html += '<td>' + row.customer_name + '</td>';
                ['current','days_1_30','days_31_60','days_61_90','days_90_plus','total'].forEach(key => {
                    const val = parseFloat(row[key] || 0);
                    totals[key] += val;
                    html += '<td>' + formatCurrency(val * 100) + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody>';
            html += '<tfoot><tr><td><strong>Total</strong></td>';
            ['current','days_1_30','days_31_60','days_61_90','days_90_plus','total'].forEach(key => {
                html += '<td><strong>' + formatCurrency(totals[key] * 100) + '</strong></td>';
            });
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