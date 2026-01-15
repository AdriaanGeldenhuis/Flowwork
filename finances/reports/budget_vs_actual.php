<?php
// /finances/reports/budget_vs_actual.php
// Page to display budget vs actual report per account and month for a selected year.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and allow admin, bookkeeper and viewer roles
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', '2025-01-21-BUDGET-VS-ACTUAL');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch company name and user first name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = $stmt->fetchColumn() ?: 'User';

// Build years list (last 5 to next 2 years)
$currentYear = intval(date('Y'));
$years = [];
for ($y = $currentYear - 5; $y <= $currentYear + 2; $y++) {
    if ($y >= 2000 && $y <= 2100) $years[] = $y;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget vs Actual Report – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <style>
        /* Extra styles for budgets vs actual report */
        .report-container {
            overflow-x: auto;
            margin-top: 1rem;
        }
        table.bva-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        table.bva-table th, table.bva-table td {
            border: 1px solid var(--fw-border);
            padding: 0.25rem 0.4rem;
            text-align: right;
            white-space: nowrap;
        }
        table.bva-table th:nth-child(1), table.bva-table td:nth-child(1),
        table.bva-table th:nth-child(2), table.bva-table td:nth-child(2) {
            text-align: left;
            position: sticky;
            left: 0;
            background: var(--fw-bg-primary);
            z-index: 2;
        }
        table.bva-table th:nth-child(2), table.bva-table td:nth-child(2) {
            left: 150px; /* approximate width of first column */
        }
        table.bva-table thead th {
            background: var(--fw-bg-secondary);
        }
        .bva-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
        }
        .bva-header .controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .fw-finance__btn {
            margin-left: 0.5rem;
        }
    </style>
</head>
<body>
    <main class="fw-finance">
        <div class="fw-finance__container">
            <!-- Header -->
            <header class="fw-finance__header">
                <div class="fw-finance__brand">
                    <div class="fw-finance__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="fw-finance__brand-text">
                        <div class="fw-finance__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-finance__app-name">Budget vs Actual</div>
                    </div>
                </div>
                <div class="fw-finance__greeting">
                    Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>
                <div class="fw-finance__controls">
                    <a href="/finances/" class="fw-finance__back-btn" title="Back to Finance">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
            <!-- Main -->
            <div class="fw-finance__main">
                <div class="bva-header">
                    <div class="controls">
                        <label for="yearSelect">Year:</label>
                        <select id="yearSelect">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="typeSelect">Account Type:</label>
                        <select id="typeSelect">
                            <option value="all">All</option>
                            <option value="income">Income</option>
                            <option value="expense">Expense</option>
                        </select>
                        <button class="fw-finance__btn fw-finance__btn--primary" id="runReportBtn">Run Report</button>
                        <button class="fw-finance__btn fw-finance__btn--secondary" id="exportBtn" disabled>Export CSV</button>
                    </div>
                    <div id="reportStatus"></div>
                </div>
                <div class="report-container" id="reportContainer">
                    <div class="fw-finance__empty-state">Select criteria and click "Run Report"</div>
                </div>
            </div>
            <footer class="fw-finance__footer">
                <span>Budget vs Actual Report v<?= ASSET_VERSION ?></span>
            </footer>
        </div>
    </main>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    document.getElementById('runReportBtn').addEventListener('click', function() {
        const year = document.getElementById('yearSelect').value;
        const type = document.getElementById('typeSelect').value;
        generateReport(year, type);
    });

    async function generateReport(year, type) {
        const container = document.getElementById('reportContainer');
        container.innerHTML = '<div class="fw-finance__loading"><div class="fw-finance__spinner"></div> Generating report...</div>';
        document.getElementById('exportBtn').disabled = true;
        document.getElementById('reportStatus').textContent = '';
        try {
            const res = await fetch(`/finances/ajax/report_budget_vs_actual.php?year=${encodeURIComponent(year)}&type=${encodeURIComponent(type)}`);
            const json = await res.json();
            if (!json.ok) {
                container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + (json.error || 'Failed to generate report') + '</div>';
                return;
            }
            const data = json.data;
            renderTable(data);
            document.getElementById('exportBtn').disabled = false;
            document.getElementById('reportStatus').textContent = 'Generated: ' + new Date().toLocaleString();
        } catch (e) {
            container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + e.message + '</div>';
        }
    }

    function renderTable(data) {
        const container = document.getElementById('reportContainer');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        let html = '';
        html += '<table class="bva-table"><thead><tr>';
        html += '<th>Account Code</th><th>Account Name</th>';
        months.forEach(function(m) {
            html += '<th>' + m + ' Bud</th><th>' + m + ' Act</th>';
        });
        html += '<th>Total Bud</th><th>Total Act</th><th>Variance</th>';
        html += '</tr></thead><tbody>';
        let grandBud = new Array(12).fill(0);
        let grandAct = new Array(12).fill(0);
        let grandTotalBud = 0;
        let grandTotalAct = 0;
        data.accounts.forEach(function(acc) {
            let totalBud = 0;
            let totalAct = 0;
            html += '<tr>';
            html += '<td>' + escapeHtml(acc.account_code) + '</td>';
            html += '<td>' + escapeHtml(acc.account_name) + '</td>';
            for (let m=1; m<=12; m++) {
                const bud = acc.budget_cents[m] || 0;
                const act = acc.actual_cents[m] || 0;
                grandBud[m-1] += bud;
                grandAct[m-1] += act;
                totalBud += bud;
                totalAct += act;
                html += '<td>' + (bud !== 0 ? formatCurrency(bud) : '-') + '</td>';
                html += '<td>' + (act !== 0 ? formatCurrency(act) : '-') + '</td>';
            }
            grandTotalBud += totalBud;
            grandTotalAct += totalAct;
            const variance = totalBud - totalAct;
            html += '<td>' + (totalBud !== 0 ? formatCurrency(totalBud) : '-') + '</td>';
            html += '<td>' + (totalAct !== 0 ? formatCurrency(totalAct) : '-') + '</td>';
            html += '<td style="color:' + (variance < 0 ? 'red' : 'inherit') + '">' + (variance !== 0 ? formatCurrency(variance) : '-') + '</td>';
            html += '</tr>';
        });
        // Totals row
        html += '<tr class="fw-finance__report-table-total">';
        html += '<td colspan="2"><strong>Total</strong></td>';
        for (let i=0; i<12; i++) {
            html += '<td><strong>' + (grandBud[i] !== 0 ? formatCurrency(grandBud[i]) : '-') + '</strong></td>';
            html += '<td><strong>' + (grandAct[i] !== 0 ? formatCurrency(grandAct[i]) : '-') + '</strong></td>';
        }
        const grandVariance = grandTotalBud - grandTotalAct;
        html += '<td><strong>' + (grandTotalBud !== 0 ? formatCurrency(grandTotalBud) : '-') + '</strong></td>';
        html += '<td><strong>' + (grandTotalAct !== 0 ? formatCurrency(grandTotalAct) : '-') + '</strong></td>';
        html += '<td style="color:' + (grandVariance < 0 ? 'red' : 'inherit') + '"><strong>' + (grandVariance !== 0 ? formatCurrency(grandVariance) : '-') + '</strong></td>';
        html += '</tr>';
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    // Helper to format cents to currency string (R) e.g. "R 1,234.56"
    function formatCurrency(cents) {
        const sign = cents < 0 ? '-' : '';
        const abs = Math.abs(cents);
        const rands = abs / 100;
        return sign + 'R ' + rands.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    // Helper to escape HTML
    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function(m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#039;'}[m] || m;
        });
    }
    // CSV Export
    document.getElementById('exportBtn').addEventListener('click', function() {
        const year = document.getElementById('yearSelect').value;
        const type = document.getElementById('typeSelect').value;
        exportCSV(year, type);
    });
    async function exportCSV(year, type) {
        try {
            const res = await fetch(`/finances/ajax/report_budget_vs_actual.php?year=${encodeURIComponent(year)}&type=${encodeURIComponent(type)}`);
            const json = await res.json();
            if (!json.ok) {
                alert('Error exporting: ' + (json.error || 'Failed'));
                return;
            }
            const data = json.data;
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            let csv = '';
            // Header row
            csv += 'Account Code,Account Name';
            months.forEach(function(m) {
                csv += ',' + m + ' Budget,' + m + ' Actual';
            });
            csv += ',Total Budget,Total Actual,Variance\n';
            // Data rows
            let grandBud = new Array(12).fill(0);
            let grandAct = new Array(12).fill(0);
            let grandTotalBud = 0;
            let grandTotalAct = 0;
            data.accounts.forEach(function(acc) {
                let line = '';
                line += '"' + acc.account_code.replace(/"/g,'""') + '",' + '"' + acc.account_name.replace(/"/g,'""') + '"';
                let totBud = 0;
                let totAct = 0;
                for (let m=1; m<=12; m++) {
                    const bud = acc.budget_cents[m] || 0;
                    const act = acc.actual_cents[m] || 0;
                    grandBud[m-1] += bud;
                    grandAct[m-1] += act;
                    totBud += bud;
                    totAct += act;
                    line += ',' + (bud/100).toFixed(2) + ',' + (act/100).toFixed(2);
                }
                grandTotalBud += totBud;
                grandTotalAct += totAct;
                const variance = (totBud - totAct)/100;
                line += ',' + (totBud/100).toFixed(2) + ',' + (totAct/100).toFixed(2) + ',' + variance.toFixed(2);
                csv += line + '\n';
            });
            // Totals row
            let totalsLine = 'Total,Total';
            for (let i=0; i<12; i++) {
                totalsLine += ',' + (grandBud[i]/100).toFixed(2) + ',' + (grandAct[i]/100).toFixed(2);
            }
            const grandVar = (grandTotalBud - grandTotalAct)/100;
            totalsLine += ',' + (grandTotalBud/100).toFixed(2) + ',' + (grandTotalAct/100).toFixed(2) + ',' + grandVar.toFixed(2);
            csv += totalsLine + '\n';
            // Trigger download
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'budget_vs_actual_' + year + '_' + type + '.csv';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } catch (e) {
            alert('Error exporting CSV: ' + e.message);
        }
    }
    </script>
</body>
</html>