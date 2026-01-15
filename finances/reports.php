<?php
// /finances/reports.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Include permissions helper and allow admin, bookkeeper and viewer roles
require_once __DIR__ . '/permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', '2025-01-21-FIN-1');

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
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
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
                        <div class="fw-finance__app-name">Financial Reports</div>
                    </div>
                </div>

                <div class="fw-finance__greeting">
                    Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-finance__controls">
                    <a href="/finances/" class="fw-finance__back-btn" title="Back to Dashboard">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <a href="/" class="fw-finance__home-btn" title="Home">
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

            <!-- Main Content -->
            <div class="fw-finance__main">
                
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

                    <!-- New: Exports tab -->
                    <button class="fw-finance__report-type-btn" data-report="exports">
                        Exports
                    </button>
                </div>

                <!-- Report Filters -->
                <div class="fw-finance__report-filters">
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
                                <a href="/export/csv/vat_summary.php" class="fw-finance__link">CSV</a>
                                <a href="/export/pdf/vat_summary.php" class="fw-finance__link" target="_blank">PDF</a>
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

            </div>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance v<?= ASSET_VERSION ?></span>
                <span id="reportInfo"></span>
            </footer>

        </div>
    </main>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/reports.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>