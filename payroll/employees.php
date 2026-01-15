<?php
// /payroll/employees.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-PAYROLL-1');

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

// Stats
$stmt = $DB->prepare("SELECT COUNT(*) as total FROM employees WHERE company_id = ? AND termination_date IS NULL");
$stmt->execute([$companyId]);
$activeCount = $stmt->fetchColumn();

$stmt = $DB->prepare("SELECT COUNT(*) as total FROM employees WHERE company_id = ? AND termination_date IS NOT NULL");
$stmt->execute([$companyId]);
$terminatedCount = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/payroll/assets/payroll.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-payroll">
        <div class="fw-payroll__container">
            
            <!-- Header -->
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
                            <a href="/payroll/payitems.php" class="fw-payroll__kebab-item">Pay Items</a>
                            <a href="/payroll/runs.php" class="fw-payroll__kebab-item">Pay Runs</a>
                            <a href="/payroll/reports.php" class="fw-payroll__kebab-item">Reports</a>
                            <a href="/payroll/settings.php" class="fw-payroll__kebab-item">Settings</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <div class="fw-payroll__main">
                
                <!-- Page Header -->
                <div class="fw-payroll__page-header">
                    <h1 class="fw-payroll__page-title">Employees</h1>
                    <p class="fw-payroll__page-subtitle">
                        <?= $activeCount ?> active, <?= $terminatedCount ?> terminated
                    </p>
                </div>

                <!-- Toolbar -->
                <div class="fw-payroll__toolbar">
                    <input 
                        type="search" 
                        class="fw-payroll__search" 
                        placeholder="Search employees..." 
                        id="searchInput"
                        autocomplete="off"
                    >
                    
                    <select class="fw-payroll__filter" id="filterStatus">
                        <option value="">All Statuses</option>
                        <option value="active" selected>Active</option>
                        <option value="terminated">Terminated</option>
                    </select>

                    <select class="fw-payroll__filter" id="filterFrequency">
                        <option value="">All Frequencies</option>
                        <option value="monthly">Monthly</option>
                        <option value="fortnight">Fortnight</option>
                        <option value="weekly">Weekly</option>
                    </select>

                    <button class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollEmployees.openNewModal()">
                        + New Employee
                    </button>
                </div>

                <!-- Employees List -->
                <div class="fw-payroll__list" id="employeesList">
                    <div class="fw-payroll__loading">Loading employees...</div>
                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-payroll__footer">
                <span>Payroll v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>

        <!-- New/Edit Employee Modal -->
        <div class="fw-payroll__modal-overlay" id="employeeModal" aria-hidden="true">
            <div class="fw-payroll__modal fw-payroll__modal--large">
                <div class="fw-payroll__modal-header">
                    <h3 class="fw-payroll__modal-title" id="employeeModalTitle">New Employee</h3>
                    <button class="fw-payroll__modal-close" onclick="PayrollEmployees.closeModal()" aria-label="Close">
                        <svg viewBox="0 0 24 24" fill="none">
                            <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <div class="fw-payroll__modal-body">
                    <form id="employeeForm">
                        <input type="hidden" name="id" id="employeeId">
                        
                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">Personal Information</h4>
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        Employee No <span class="fw-payroll__required">*</span>
                                    </label>
                                    <input type="text" name="employee_no" class="fw-payroll__input" required>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        First Name <span class="fw-payroll__required">*</span>
                                    </label>
                                    <input type="text" name="first_name" class="fw-payroll__input" required>
                                </div>
                            </div>
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        Last Name <span class="fw-payroll__required">*</span>
                                    </label>
                                    <input type="text" name="last_name" class="fw-payroll__input" required>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">ID Number</label>
                                    <input type="text" name="id_number" class="fw-payroll__input">
                                </div>
                            </div>
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Email</label>
                                    <input type="email" name="email" class="fw-payroll__input">
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Phone</label>
                                    <input type="tel" name="phone" class="fw-payroll__input">
                                </div>
                            </div>
                        </div>

                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">Employment Details</h4>
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        Hire Date <span class="fw-payroll__required">*</span>
                                    </label>
                                    <input type="date" name="hire_date" class="fw-payroll__input" required>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Employment Type</label>
                                    <select name="employment_type" class="fw-payroll__input">
                                        <option value="permanent">Permanent</option>
                                        <option value="contract">Contract</option>
                                        <option value="casual">Casual</option>
                                    </select>
                                </div>
                            </div>
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Pay Frequency</label>
                                    <select name="pay_frequency" class="fw-payroll__input">
                                        <option value="monthly">Monthly</option>
                                        <option value="fortnight">Fortnight</option>
                                        <option value="weekly">Weekly</option>
                                    </select>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Base Salary (R/month or R/hour)</label>
                                    <input type="number" name="base_salary" step="0.01" class="fw-payroll__input" placeholder="0.00">
                                </div>
                            </div>
                        </div>

                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">Tax & Statutory</h4>
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Tax Number</label>
                                    <input type="text" name="tax_number" class="fw-payroll__input">
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__checkbox-wrapper">
                                        <input type="checkbox" name="uif_included" class="fw-payroll__checkbox" checked>
                                        UIF Included
                                    </label>
                                    <label class="fw-payroll__checkbox-wrapper">
                                        <input type="checkbox" name="sdl_included" class="fw-payroll__checkbox" checked>
                                        SDL Included
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="fw-payroll__form-section">
                            <h4 class="fw-payroll__form-section-title">Banking Details</h4>
                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Bank Name</label>
                                    <input type="text" name="bank_name" class="fw-payroll__input">
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">Branch Code</label>
                                    <input type="text" name="branch_code" class="fw-payroll__input">
                                </div>
                            </div>
                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__label">Account Number</label>
                                <input type="text" name="bank_account_no" class="fw-payroll__input">
                            </div>
                        </div>

                        <div id="formMessage"></div>
                    </form>
                </div>
                <div class="fw-payroll__modal-footer">
                    <button type="button" class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollEmployees.closeModal()">Cancel</button>
                    <button type="button" class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollEmployees.saveEmployee()">Save Employee</button>
                </div>
            </div>
        </div>

    </main>

    <script src="/payroll/assets/payroll.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/payroll/assets/employees.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>