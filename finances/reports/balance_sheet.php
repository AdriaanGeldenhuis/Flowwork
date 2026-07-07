<?php
// /finances/reports/balance_sheet.php
// Standalone, printable Balance Sheet (Statement of Financial Position).
// Renders the SAME data as the in-page Balance Sheet on /finances/reports.php
// by calling the canonical AJAX endpoint (report_balance_sheet.php).
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

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
    <title>Balance Sheet – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="/finances/assets/report_print.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
        <?php $finTitle = 'Balance Sheet'; $finBack = '/finances/reports.php'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/../partials/header.php'; ?>
        <main class="fw-finance__main">
            <div class="fw-report-toolbar fw-print-hide">
                <div class="fw-finance__filter-group">
                    <label class="fw-finance__label" for="reportDate">As at Date</label>
                    <input type="date" class="fw-finance__filter" id="reportDate" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="fw-report-actions">
                    <button class="fw-finance__btn fw-finance__btn--primary" id="runBtn">Run Report</button>
                    <button class="fw-finance__btn fw-finance__btn--secondary" id="printBtn" disabled>Print</button>
                    <button class="fw-finance__btn fw-finance__btn--secondary" id="exportBtn" disabled>Export CSV</button>
                </div>
            </div>

            <div class="fw-finance__report-content" id="reportContent">
                <div class="fw-finance__empty-state">Select a date and click "Run Report"</div>
            </div>
        </main>
        <footer class="fw-finance__footer">
            <span>Balance Sheet v<?= ASSET_VERSION ?> <span id="reportInfo" style="margin-left: 1rem;"></span></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </div>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/report_render.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    (function () {
        'use strict';
        var runBtn = document.getElementById('runBtn');
        var printBtn = document.getElementById('printBtn');
        var exportBtn = document.getElementById('exportBtn');
        var reportDate = document.getElementById('reportDate');
        var content = document.getElementById('reportContent');
        var reportInfo = document.getElementById('reportInfo');
        var reportData = null;

        async function run() {
            var date = reportDate.value;
            if (!date) { alert('Please select a date'); return; }
            content.innerHTML = '<div class="fw-finance__loading"><div class="fw-finance__spinner"></div> Generating report...</div>';
            printBtn.disabled = true;
            exportBtn.disabled = true;
            reportInfo.textContent = '';

            var result = await FinanceAPI.request('/finances/ajax/report_balance_sheet.php?date=' + encodeURIComponent(date));
            if (result && result.ok) {
                reportData = result.data;
                content.innerHTML = FWReport.balanceSheet(reportData);
                printBtn.disabled = false;
                exportBtn.disabled = false;
                reportInfo.textContent = 'Generated: ' + new Date().toLocaleString();
            } else {
                reportData = null;
                content.innerHTML = '<div class="fw-finance__empty-state">Error: ' + escapeHtml((result && result.error) || 'Failed to generate report') + '</div>';
            }
        }

        runBtn.addEventListener('click', run);
        printBtn.addEventListener('click', function () { window.print(); });
        exportBtn.addEventListener('click', function () {
            if (!reportData) return;
            FWReport.download('balance-sheet-' + reportDate.value + '.csv', FWReport.csv.balanceSheet(reportData));
        });

        run();
    })();
    </script>
</body>
</html>
