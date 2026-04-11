<?php
// /finances/reports/budget_vs_actual.php
// Page to display budget vs actual report per account and month for a selected year.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and allow admin, bookkeeper and viewer roles
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', '2026-04-07-FIN-3');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Fetch company name and user first name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = $stmt->fetchColumn() ?: 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = $stmt->fetchColumn() ?: 'User';

// Check if user has edit permissions (admin or bookkeeper)
$userRole = fw_get_user_role($DB);
$canEditBudgets = in_array($userRole, ['admin', 'bookkeeper'], true);

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
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
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
        .bva-meta {
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: var(--fw-text-secondary, #666);
            line-height: 1.6;
        }
        .bva-meta strong {
            color: var(--fw-text-primary, #333);
        }
        .bva-section-header td {
            background: var(--fw-bg-secondary);
            font-weight: bold;
            text-align: left !important;
            padding: 0.4rem 0.5rem;
        }
        .bva-subtotal td {
            font-weight: bold;
            border-top: 2px solid var(--fw-border);
        }
        .bva-net-position td {
            font-weight: bold;
            border-top: 3px double var(--fw-border);
            background: var(--fw-bg-secondary);
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
                        <?php if ($canEditBudgets): ?>
                        <a href="/finances/budgets/edit.php" class="fw-finance__btn">Edit Budgets</a>
                        <?php endif; ?>
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

    // Store last fetched data for CSV export
    let lastReportData = null;

    async function generateReport(year, type) {
        const container = document.getElementById('reportContainer');
        container.innerHTML = '<div class="fw-finance__loading"><div class="fw-finance__spinner"></div> Generating report...</div>';
        document.getElementById('exportBtn').disabled = true;
        document.getElementById('reportStatus').textContent = '';
        try {
            const res = await fetch(`/finances/ajax/report_budget_vs_actual.php?year=${encodeURIComponent(year)}&type=${encodeURIComponent(type)}`);
            const json = await res.json();
            if (!json.ok) {
                container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + escapeHtml(json.error || 'Failed to generate report') + '</div>';
                return;
            }
            lastReportData = json.data;
            renderTable(json.data);
            document.getElementById('exportBtn').disabled = false;
            document.getElementById('reportStatus').textContent = 'Generated: ' + new Date().toLocaleString();
        } catch (e) {
            container.innerHTML = '<div class="fw-finance__empty-state">Error: ' + escapeHtml(e.message) + '</div>';
        }
    }

    // Determine if variance is unfavorable based on account type
    function isUnfavorable(variance, accountType) {
        // 'revenue' is the DB type; 'income' kept as alias for backward compatibility
        if (accountType === 'revenue' || accountType === 'income') return variance > 0; // Under-earned
        return variance < 0; // Overspent
    }

    // Calculate percentage variance
    function pctVariance(variance, budget) {
        if (budget === 0) return '-';
        const pct = (variance / Math.abs(budget)) * 100;
        return pct.toFixed(1) + '%';
    }

    function renderTable(data) {
        const container = document.getElementById('reportContainer');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const colSpan = 2 + (12 * 2) + 4; // code + name + 24 months + totBud + totAct + var + %var

        // Report metadata header
        let html = '';
        if (data.meta) {
            html += '<div class="bva-meta">';
            html += '<strong>' + escapeHtml(data.meta.company_name || '') + '</strong><br>';
            if (data.meta.reg_number) html += 'Reg: ' + escapeHtml(data.meta.reg_number) + ' &nbsp; ';
            if (data.meta.vat_number) html += 'VAT: ' + escapeHtml(data.meta.vat_number) + ' &nbsp; ';
            if (data.meta.tax_number) html += 'Tax: ' + escapeHtml(data.meta.tax_number);
            if (data.meta.reg_number || data.meta.vat_number || data.meta.tax_number) html += '<br>';
            html += 'Budget vs Actual Report &mdash; Year: ' + escapeHtml(String(data.year)) + '<br>';
            html += 'Prepared by: ' + escapeHtml(data.meta.prepared_by || '') + ' &nbsp; Generated: ' + escapeHtml(data.meta.generated_at || '');
            html += '</div>';
        }

        html += '<table class="bva-table"><thead><tr>';
        html += '<th>Account Code</th><th>Account Name</th>';
        months.forEach(function(m) {
            html += '<th>' + m + ' Bud</th><th>' + m + ' Act</th>';
        });
        html += '<th>Total Bud</th><th>Total Act</th><th>Variance</th><th>% Var</th>';
        html += '</tr></thead><tbody>';

        // Separate accounts into revenue (income) and expense
        const incomeAccounts = (data.accounts || []).filter(function(a) { return a.account_type === 'revenue' || a.account_type === 'income'; });
        const expenseAccounts = (data.accounts || []).filter(function(a) { return a.account_type === 'expense'; });

        let grandBud = new Array(12).fill(0);
        let grandAct = new Array(12).fill(0);
        let grandTotalBud = 0;
        let grandTotalAct = 0;

        // Render a section of accounts and return subtotals
        function renderSection(accounts, label) {
            let secBud = new Array(12).fill(0);
            let secAct = new Array(12).fill(0);
            let secTotalBud = 0;
            let secTotalAct = 0;
            const accType = accounts.length > 0 ? accounts[0].account_type : 'expense';

            // Section header
            html += '<tr class="bva-section-header"><td colspan="' + colSpan + '">' + escapeHtml(label) + '</td></tr>';

            accounts.forEach(function(acc) {
                let totalBud = 0;
                let totalAct = 0;
                html += '<tr>';
                html += '<td>' + escapeHtml(acc.account_code) + '</td>';
                html += '<td>' + escapeHtml(acc.account_name) + '</td>';
                for (let m = 1; m <= 12; m++) {
                    const bud = acc.budget_cents[m] || 0;
                    const act = acc.actual_cents[m] || 0;
                    secBud[m-1] += bud;
                    secAct[m-1] += act;
                    totalBud += bud;
                    totalAct += act;
                    html += '<td>' + (bud !== 0 ? formatCurrency(bud) : '-') + '</td>';
                    html += '<td>' + (act !== 0 ? formatCurrency(act) : '-') + '</td>';
                }
                secTotalBud += totalBud;
                secTotalAct += totalAct;
                const variance = totalBud - totalAct;
                const unfavorable = isUnfavorable(variance, accType);
                const varColor = (variance !== 0 && unfavorable) ? 'red' : 'inherit';
                html += '<td>' + (totalBud !== 0 ? formatCurrency(totalBud) : '-') + '</td>';
                html += '<td>' + (totalAct !== 0 ? formatCurrency(totalAct) : '-') + '</td>';
                html += '<td style="color:' + varColor + '">' + (variance !== 0 ? formatCurrency(variance) : '-') + '</td>';
                html += '<td style="color:' + varColor + '">' + pctVariance(variance, totalBud) + '</td>';
                html += '</tr>';
            });

            // Subtotal row
            const secVariance = secTotalBud - secTotalAct;
            const secUnfavorable = isUnfavorable(secVariance, accType);
            const secVarColor = (secVariance !== 0 && secUnfavorable) ? 'red' : 'inherit';
            html += '<tr class="bva-subtotal">';
            html += '<td colspan="2">Total ' + escapeHtml(label) + '</td>';
            for (let i = 0; i < 12; i++) {
                grandBud[i] += secBud[i];
                grandAct[i] += secAct[i];
                html += '<td>' + (secBud[i] !== 0 ? formatCurrency(secBud[i]) : '-') + '</td>';
                html += '<td>' + (secAct[i] !== 0 ? formatCurrency(secAct[i]) : '-') + '</td>';
            }
            grandTotalBud += secTotalBud;
            grandTotalAct += secTotalAct;
            html += '<td>' + (secTotalBud !== 0 ? formatCurrency(secTotalBud) : '-') + '</td>';
            html += '<td>' + (secTotalAct !== 0 ? formatCurrency(secTotalAct) : '-') + '</td>';
            html += '<td style="color:' + secVarColor + '">' + (secVariance !== 0 ? formatCurrency(secVariance) : '-') + '</td>';
            html += '<td style="color:' + secVarColor + '">' + pctVariance(secVariance, secTotalBud) + '</td>';
            html += '</tr>';

            return { totalBud: secTotalBud, totalAct: secTotalAct };
        }

        // Render Income section
        let incomeTotals = { totalBud: 0, totalAct: 0 };
        if (incomeAccounts.length > 0) {
            incomeTotals = renderSection(incomeAccounts, 'Income');
        }

        // Render Expense section
        let expenseTotals = { totalBud: 0, totalAct: 0 };
        if (expenseAccounts.length > 0) {
            expenseTotals = renderSection(expenseAccounts, 'Expenses');
        }

        // Net Position row (Income - Expenses)
        const netBud = incomeTotals.totalBud - expenseTotals.totalBud;
        const netAct = incomeTotals.totalAct - expenseTotals.totalAct;
        const netVariance = netBud - netAct;
        html += '<tr class="bva-net-position">';
        html += '<td colspan="2">Net Position (Income - Expenses)</td>';
        for (let i = 0; i < 12; i++) {
            html += '<td>-</td><td>-</td>';
        }
        html += '<td>' + (netBud !== 0 ? formatCurrency(netBud) : '-') + '</td>';
        html += '<td>' + (netAct !== 0 ? formatCurrency(netAct) : '-') + '</td>';
        const netColor = netVariance < 0 ? 'red' : 'inherit';
        html += '<td style="color:' + netColor + '">' + (netVariance !== 0 ? formatCurrency(netVariance) : '-') + '</td>';
        html += '<td style="color:' + netColor + '">' + pctVariance(netVariance, netBud) + '</td>';
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

            // Company metadata header rows
            if (data.meta) {
                csv += '"' + (data.meta.company_name || '').replace(/"/g,'""') + '"\n';
                if (data.meta.reg_number) csv += '"Reg: ' + data.meta.reg_number.replace(/"/g,'""') + '"\n';
                if (data.meta.vat_number) csv += '"VAT: ' + data.meta.vat_number.replace(/"/g,'""') + '"\n';
                if (data.meta.tax_number) csv += '"Tax: ' + data.meta.tax_number.replace(/"/g,'""') + '"\n';
                csv += '"Budget vs Actual Report - Year: ' + year + '"\n';
                csv += '"Prepared by: ' + (data.meta.prepared_by || '').replace(/"/g,'""') + '","Generated: ' + (data.meta.generated_at || '').replace(/"/g,'""') + '"\n';
                csv += '\n';
            }

            // Column header row
            csv += 'Account Code,Account Name';
            months.forEach(function(m) {
                csv += ',' + m + ' Budget,' + m + ' Actual';
            });
            csv += ',Total Budget,Total Actual,Variance,% Variance\n';

            // Separate accounts (DB uses 'revenue'; 'income' kept as alias)
            const incomeAccounts = (data.accounts || []).filter(function(a) { return a.account_type === 'revenue' || a.account_type === 'income'; });
            const expenseAccounts = (data.accounts || []).filter(function(a) { return a.account_type === 'expense'; });

            function csvSection(accounts, label) {
                let secTotalBud = 0, secTotalAct = 0;
                csv += '"' + label + '"\n';
                accounts.forEach(function(acc) {
                    let line = '"' + acc.account_code.replace(/"/g,'""') + '","' + acc.account_name.replace(/"/g,'""') + '"';
                    let totBud = 0, totAct = 0;
                    for (let m = 1; m <= 12; m++) {
                        const bud = acc.budget_cents[m] || 0;
                        const act = acc.actual_cents[m] || 0;
                        totBud += bud;
                        totAct += act;
                        line += ',' + (bud/100).toFixed(2) + ',' + (act/100).toFixed(2);
                    }
                    secTotalBud += totBud;
                    secTotalAct += totAct;
                    const variance = (totBud - totAct)/100;
                    const pct = totBud !== 0 ? ((totBud - totAct) / Math.abs(totBud) * 100).toFixed(1) + '%' : '-';
                    line += ',' + (totBud/100).toFixed(2) + ',' + (totAct/100).toFixed(2) + ',' + variance.toFixed(2) + ',' + pct;
                    csv += line + '\n';
                });
                // Subtotal
                const secVar = (secTotalBud - secTotalAct)/100;
                const secPct = secTotalBud !== 0 ? ((secTotalBud - secTotalAct) / Math.abs(secTotalBud) * 100).toFixed(1) + '%' : '-';
                csv += '"Total ' + label + '",,,,,,,,,,,,,,,,,,,,,,,,,,' + (secTotalBud/100).toFixed(2) + ',' + (secTotalAct/100).toFixed(2) + ',' + secVar.toFixed(2) + ',' + secPct + '\n';
                return { totalBud: secTotalBud, totalAct: secTotalAct };
            }

            let incomeTotals = { totalBud: 0, totalAct: 0 };
            if (incomeAccounts.length > 0) {
                incomeTotals = csvSection(incomeAccounts, 'Income');
            }

            let expenseTotals = { totalBud: 0, totalAct: 0 };
            if (expenseAccounts.length > 0) {
                expenseTotals = csvSection(expenseAccounts, 'Expenses');
            }

            // Net Position
            const netBud = (incomeTotals.totalBud - expenseTotals.totalBud)/100;
            const netAct = (incomeTotals.totalAct - expenseTotals.totalAct)/100;
            const netVar = netBud - netAct;
            const netPct = netBud !== 0 ? (netVar / Math.abs(netBud) * 100).toFixed(1) + '%' : '-';
            csv += '\n"Net Position (Income - Expenses)",,,,,,,,,,,,,,,,,,,,,,,,,,' + netBud.toFixed(2) + ',' + netAct.toFixed(2) + ',' + netVar.toFixed(2) + ',' + netPct + '\n';

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