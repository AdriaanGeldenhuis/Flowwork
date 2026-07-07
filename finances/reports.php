<?php
// /finances/reports.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Include permissions helper and allow admin, bookkeeper and viewer roles
require_once __DIR__ . '/permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user info
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">
            
            <!-- Header -->
            <?php $finTitle = 'Financial Reports'; $finBack = '/finances/'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/partials/header.php'; ?>

            <!-- Main Content -->
            <main class="fw-finance__main">
                
                <!-- Report Types -->
                <div class="fw-finance__report-types">
                    <button class="fw-finance__report-type-btn fw-finance__report-type-btn--active" data-report="trial-balance">
                        Trial Balance
                    </button>
                    <button class="fw-finance__report-type-btn" data-report="pl">
                        Profit & Loss
                    </button>
                    <button class="fw-finance__report-type-btn" data-report="balance-sheet">
                        Balance Sheet
                    </button>
                    <button class="fw-finance__report-type-btn" data-report="gl-detail">
                        GL Account Detail
                    </button>

                    <!-- Additional report links for aging, cashflow and VAT summary -->
                    <!-- These buttons navigate to dedicated pages rather than being handled by the
                         reports.js script. Each uses an onclick handler to redirect to the
                         appropriate report page. We deliberately omit the data-report attribute
                         to avoid interfering with the existing report-type toggling logic. -->
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/ar_aging.php'">
                        AR Aging
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/ap_aging.php'">
                        AP Aging
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/cashflow_indirect.php'">
                        Cash Flow (Indirect)
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/vat_summary.php'">
                        VAT Summary
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/vat201.php'">
                        VAT201 Return
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/vat_reconciliation.php'">
                        VAT Reconciliation
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/capital_goods_vat.php'">
                        Capital Goods VAT
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/fa_schedule.php'">
                        Fixed Asset Schedule
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/fa_wear_and_tear.php'">
                        Wear-and-Tear Register
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/budget_vs_actual.php'">
                        Budget vs Actual
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/retention.php'">
                        Record Retention
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/trial_balance.php'">
                        Trial Balance (printable)
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/balance_sheet.php'">
                        Balance Sheet (printable)
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/income_statement.php'">
                        Income Statement (printable)
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/gl_detail.php'">
                        GL Detail
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/ar_tieout.php'">
                        AR Tie-out
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/ap_tieout.php'">
                        AP Tie-out
                    </button>
                    <button class="fw-finance__report-type-btn" onclick="window.location.href='/finances/reports/sequence_gaps.php'">
                        Sequence Gaps
                    </button>

                    <!-- New: Exports tab -->
                    <button class="fw-finance__report-type-btn" data-report="exports">
                        Exports
                    </button>
                </div>

                <!-- Report Filters -->
                <div class="fw-finance__report-filters">
                    <div class="fw-finance__filter-group" id="startDateFilterGroup" style="display: none;">
                        <label class="fw-finance__label">Period Start</label>
                        <input
                            type="date"
                            class="fw-finance__filter"
                            id="reportStartDate"
                            value="<?= date('Y-01-01') ?>"
                        >
                    </div>

                    <div class="fw-finance__filter-group">
                        <label class="fw-finance__label">As at Date / Period End</label>
                        <input
                            type="date"
                            class="fw-finance__filter"
                            id="reportDate"
                            value="<?= date('Y-m-d') ?>"
                        >
                    </div>

                    <div class="fw-finance__filter-group" id="accountFilterGroup" style="display: none;">
                        <label class="fw-finance__label">Account</label>
                        <select class="fw-finance__filter" id="accountFilter">
                            <option value="">All Accounts</option>
                        </select>
                    </div>

                    <div class="fw-finance__filter-group" id="projectFilterGroup" style="display: none;">
                        <label class="fw-finance__label">Project</label>
                        <select class="fw-finance__filter" id="projectFilter">
                            <option value="">All Projects</option>
                        </select>
                    </div>

                    <button class="fw-finance__btn fw-finance__btn--primary" id="runReportBtn">
                        Run Report
                    </button>

                    <button class="fw-finance__btn fw-finance__btn--secondary" id="exportBtn" disabled>
                        Export CSV
                    </button>
                </div>

                <!-- Report Content -->
                <div class="fw-finance__report-content" id="reportContent">
                    <div class="fw-finance__empty-state">
                        Select a report type and click "Run Report"
                    </div>
                </div>

                <!-- Exports Content -->
                <div class="fw-finance__exports-content" id="exportsContent" style="display: none;">
                    <div class="fw-finance__filter-group">
                        <label class="fw-finance__label">As at Date (Aging)</label>
                        <input type="date" class="fw-finance__filter" id="exportsDate" value="<?= date('Y-m-d') ?>">
                        <small>Select date for AR/AP Aging exports</small>
                    </div>
                    <div class="fw-finance__filter-group" style="margin-top: 0.5rem;">
                        <label class="fw-finance__label">VAT Period Start</label>
                        <input type="date" class="fw-finance__filter" id="exportsVatStart" value="<?= date('Y-01-01') ?>">
                        <label class="fw-finance__label" style="margin-top: 0.25rem;">VAT Period End</label>
                        <input type="date" class="fw-finance__filter" id="exportsVatEnd" value="<?= date('Y-m-d') ?>">
                        <small>Select period for VAT Summary exports</small>
                    </div>
                    <div class="fw-finance__exports-list">
                        <!-- AR Aging export links -->
                        <div class="fw-finance__exports-item">
                            <span>AR Aging</span>
                            <span>
                                <a href="/export/csv/ar_aging.php" class="fw-finance__link" id="arCsv">CSV</a>
                                <a href="/export/pdf/ar_aging.php" class="fw-finance__link" id="arPdf" target="_blank">PDF</a>
                            </span>
                        </div>
                        <!-- AP Aging export links -->
                        <div class="fw-finance__exports-item">
                            <span>AP Aging</span>
                            <span>
                                <a href="/export/csv/ap_aging.php" class="fw-finance__link" id="apCsv">CSV</a>
                                <a href="/export/pdf/ap_aging.php" class="fw-finance__link" id="apPdf" target="_blank">PDF</a>
                            </span>
                        </div>
                        <!-- Project P&L export links -->
                        <div class="fw-finance__exports-item">
                            <span>Project P&amp;L</span>
                            <span>
                                <a href="/export/csv/project_pl.php" class="fw-finance__link">CSV</a>
                                <a href="/export/pdf/project_pl.php" class="fw-finance__link" target="_blank">PDF</a>
                            </span>
                        </div>
                        <!-- VAT Summary export links -->
                        <div class="fw-finance__exports-item">
                            <span>VAT Summary</span>
                            <span>
                                <a href="/export/csv/vat_summary.php" class="fw-finance__link" id="vatCsv">CSV</a>
                                <a href="/export/pdf/vat_summary.php" class="fw-finance__link" id="vatPdf" target="_blank">PDF</a>
                            </span>
                        </div>
                        <!-- Payroll Summary export links -->
                        <div class="fw-finance__exports-item">
                            <span>Payroll Summary</span>
                            <span>
                                <a href="/export/csv/payroll_summary.php" class="fw-finance__link">CSV</a>
                                <a href="/export/pdf/payroll_summary.php" class="fw-finance__link" target="_blank">PDF</a>
                            </span>
                        </div>
                    </div>
                </div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Financial Reports v<?= ASSET_VERSION ?></span>
                <span id="reportInfo"></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/reports.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>