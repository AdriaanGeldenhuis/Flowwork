<?php
// /payroll/payitems.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-PAYROLL-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

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
    <title>Pay Items – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/payroll/assets/payroll.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-payroll">
        <div class="fw-payroll__container">
            
            <!-- Header (same as employees.php) -->
            <header class="fw-payroll__header">
                <div class="fw-payroll__brand">
                    <div class="fw-payroll__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="fw-payroll__brand-text">
                        <div class="fw-payroll__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-payroll__app-name">Payroll</div>
                    </div>
                </div>

                <div class="fw-payroll__greeting">
                    Hello, <span class="fw-payroll__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-payroll__controls">
                    <a href="/" class="fw-payroll__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <button class="fw-payroll__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-payroll__theme-icon fw-payroll__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                        <svg class="fw-payroll__theme-icon fw-payroll__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>

                    <div class="fw-payroll__menu-wrapper">
                        <button class="fw-payroll__kebab-toggle" id="kebabToggle" aria-label="Menu">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                            </svg>
                        </button>
                        <nav class="fw-payroll__kebab-menu" id="kebabMenu" aria-hidden="true">
                            <a href="/payroll/" class="fw-payroll__kebab-item">Dashboard</a>
                            <a href="/payroll/employees.php" class="fw-payroll__kebab-item">Employees</a>
                            <a href="/payroll/runs.php" class="fw-payroll__kebab-item">Pay Runs</a>
                            <a href="/payroll/reports.php" class="fw-payroll__kebab-item">Reports</a>
                            <a href="/payroll/settings.php" class="fw-payroll__kebab-item">Settings</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <div class="fw-payroll__main">
                
                <div class="fw-payroll__page-header">
                    <h1 class="fw-payroll__page-title">Pay Items Catalogue</h1>
                    <p class="fw-payroll__page-subtitle">
                        Earnings, deductions, contributions, benefits & reimbursements
                    </p>
                </div>

                <div class="fw-payroll__toolbar">
                    <select class="fw-payroll__filter" id="filterType">
                        <option value="">All Types</option>
                        <option value="earning">Earnings</option>
                        <option value="deduction">Deductions</option>
                        <option value="contribution">Contributions</option>
                        <option value="benefit">Benefits</option>
                        <option value="reimbursement">Reimbursements</option>
                    </select>

                    <button class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollPayitems.openNewModal()">
                        + New Pay Item
                    </button>
                </div>

                <div class="fw-payroll__list" id="payitemsList">
                    <div class="fw-payroll__loading">Loading pay items...</div>
                </div>

            </div>

            <footer class="fw-payroll__footer">
                <span>Payroll v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>

        <!-- Pay Item Modal -->
        <div class="fw-payroll__modal-overlay" id="payitemModal" aria-hidden="true">
            <div class="fw-payroll__modal">
                <div class="fw-payroll__modal-header">
                    <h3 class="fw-payroll__modal-title" id="payitemModalTitle">New Pay Item</h3>
                    <button class="fw-payroll__modal-close" onclick="PayrollPayitems.closeModal()" aria-label="Close">
                        <svg viewBox="0 0 24 24" fill="none">
                            <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <div class="fw-payroll__modal-body">
                    <form id="payitemForm">
                        <input type="hidden" name="id" id="payitemId">
                        
                        <div class="fw-payroll__form-group">
                            <label class="fw-payroll__label">
                                Code <span class="fw-payroll__required">*</span>
                            </label>
                            <input type="text" name="code" class="fw-payroll__input" required>
                        </div>

                        <div class="fw-payroll__form-group">
                            <label class="fw-payroll__label">
                                Name <span class="fw-payroll__required">*</span>
                            </label>
                            <input type="text" name="name" class="fw-payroll__input" required>
                        </div>

                        <div class="fw-payroll__form-group">
                            <label class="fw-payroll__label">Type</label>
                            <select name="type" class="fw-payroll__input">
                                <option value="earning">Earning</option>
                                <option value="deduction">Deduction</option>
                                <option value="contribution">Contribution</option>
                                <option value="benefit">Benefit</option>
                                <option value="reimbursement">Reimbursement</option>
                            </select>
                        </div>

                        <div class="fw-payroll__form-row">
                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__checkbox-wrapper">
                                    <input type="checkbox" name="taxable" class="fw-payroll__checkbox" checked>
                                    Taxable (PAYE)
                                </label>
                            </div>
                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__checkbox-wrapper">
                                    <input type="checkbox" name="uif_subject" class="fw-payroll__checkbox" checked>
                                    UIF Subject
                                </label>
                            </div>
                        </div>

                        <div class="fw-payroll__form-row">
                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__checkbox-wrapper">
                                    <input type="checkbox" name="sdl_subject" class="fw-payroll__checkbox" checked>
                                    SDL Subject
                                </label>
                            </div>
                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__checkbox-wrapper">
                                    <input type="checkbox" name="active" class="fw-payroll__checkbox" checked>
                                    Active
                                </label>
                            </div>
                        </div>

                        <div class="fw-payroll__form-group">
                            <label class="fw-payroll__label">GL Account Code</label>
                            <input type="text" name="gl_account_code" class="fw-payroll__input">
                        </div>

                        <div id="formMessage"></div>
                    </form>
                </div>
                <div class="fw-payroll__modal-footer">
                    <button type="button" class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollPayitems.closeModal()">Cancel</button>
                    <button type="button" class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollPayitems.savePayitem()">Save Pay Item</button>
                </div>
            </div>
        </div>

    </main>

    <script src="/payroll/assets/payroll.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/payroll/assets/payitems.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>