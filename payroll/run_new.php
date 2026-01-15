<?php
// /payroll/run_new.php
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

// Get settings
$stmt = $DB->prepare("SELECT * FROM payroll_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();

if (!$settings) {
    // Create default settings
    $stmt = $DB->prepare("
        INSERT INTO payroll_settings (company_id) VALUES (?)
    ");
    $stmt->execute([$companyId]);
    $settings = [
        'monthly_anchor_day' => 25,
        'fortnight_anchor_day' => 15,
        'weekly_anchor_day' => 5,
        'default_frequency' => 'monthly'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Pay Run – <?= htmlspecialchars($companyName) ?></title>
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
                            <a href="/payroll/employees.php" class="fw-payroll__kebab-item">Employees</a>
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
                
                <div class="fw-payroll__page-header">
                    <h1 class="fw-payroll__page-title">Create New Pay Run</h1>
                    <p class="fw-payroll__page-subtitle">Set up period, select employees, and calculate payroll</p>
                </div>

                <div class="fw-payroll__wizard">
                    <form id="runWizardForm">
                        
                        <!-- Step 1: Basic Info -->
                        <div class="fw-payroll__wizard-card">
                            <h3 class="fw-payroll__wizard-card-title">Pay Run Details</h3>
                            
                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__label">
                                    Run Name <span class="fw-payroll__required">*</span>
                                </label>
                                <input type="text" name="name" class="fw-payroll__input" 
                                       placeholder="e.g., January 2025 Payroll" required>
                            </div>

                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        Frequency <span class="fw-payroll__required">*</span>
                                    </label>
                                    <select name="frequency" id="frequency" class="fw-payroll__input" required>
                                        <option value="monthly" <?= $settings['default_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                        <option value="fortnight" <?= $settings['default_frequency'] === 'fortnight' ? 'selected' : '' ?>>Fortnight</option>
                                        <option value="weekly" <?= $settings['default_frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    </select>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        Pay Date <span class="fw-payroll__required">*</span>
                                    </label>
                                    <input type="date" name="pay_date" id="payDate" class="fw-payroll__input" required>
                                </div>
                            </div>

                            <div class="fw-payroll__form-row">
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        Period Start <span class="fw-payroll__required">*</span>
                                    </label>
                                    <input type="date" name="period_start" id="periodStart" class="fw-payroll__input" required>
                                </div>
                                <div class="fw-payroll__form-group">
                                    <label class="fw-payroll__label">
                                        Period End <span class="fw-payroll__required">*</span>
                                    </label>
                                    <input type="date" name="period_end" id="periodEnd" class="fw-payroll__input" required>
                                </div>
                            </div>

                            <button type="button" class="fw-payroll__btn fw-payroll__btn--secondary" 
                                    onclick="PayrollRunWizard.autoFillDates()">
                                Auto-Fill Dates from Anchors
                            </button>

                            <div class="fw-payroll__form-group">
                                <label class="fw-payroll__label">Notes</label>
                                <textarea name="notes" class="fw-payroll__textarea" rows="3"></textarea>
                            </div>
                        </div>

                        <div id="formMessage"></div>

                        <div class="fw-payroll__wizard-actions">
                            <a href="/payroll/runs.php" class="fw-payroll__btn fw-payroll__btn--secondary">Cancel</a>
                            <button type="button" class="fw-payroll__btn fw-payroll__btn--primary" 
                                    onclick="PayrollRunWizard.createRun()">
                                Create & Continue
                            </button>
                        </div>

                    </form>
                </div>

            </div>

            <footer class="fw-payroll__footer">
                <span>Payroll v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>
    </main>

    <script>
        const PAYROLL_SETTINGS = <?= json_encode($settings) ?>;
    </script>
    <script src="/payroll/assets/payroll.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/payroll/assets/run_wizard.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>