<?php
// /payroll/run_view.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-PAYROLL-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$runId = $_GET['id'] ?? 0;

if (!$runId) {
    header('Location: /payroll/runs.php');
    exit;
}

// Fetch run
$stmt = $DB->prepare("
    SELECT * FROM pay_runs 
    WHERE id = ? AND company_id = ?
");
$stmt->execute([$runId, $companyId]);
$run = $stmt->fetch();

if (!$run) {
    header('Location: /payroll/runs.php');
    exit;
}

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
$stmt = $DB->prepare("
    SELECT 
        COUNT(*) as employee_count,
        SUM(gross_cents) as total_gross,
        SUM(paye_cents) as total_paye,
        SUM(uif_employee_cents) as total_uif_emp,
        SUM(uif_employer_cents) as total_uif_empr,
        SUM(sdl_cents) as total_sdl,
        SUM(other_deductions_cents) as total_deductions,
        SUM(net_cents) as total_net,
        SUM(employer_cost_cents) as total_employer_cost
    FROM pay_run_employees
    WHERE run_id = ? AND company_id = ?
");
$stmt->execute([$runId, $companyId]);
$stats = $stmt->fetch();

$canEdit = in_array($run['status'], ['draft', 'calculated', 'review']);
$canApprove = in_array($run['status'], ['review']);
$canLock = $run['status'] === 'approved';
$canPost = $run['status'] === 'locked';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($run['name']) ?> – <?= htmlspecialchars($companyName) ?></title>
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
                
                <!-- Run Header -->
                <div class="fw-payroll__run-view-header">
                    <div class="fw-payroll__run-view-title">
                        <a href="/payroll/runs.php" class="fw-payroll__back-link">
                            <svg viewBox="0 0 24 24" fill="none" width="20" height="20">
                                <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                        <div>
                            <h1 class="fw-payroll__page-title"><?= htmlspecialchars($run['name']) ?></h1>
                            <p class="fw-payroll__page-subtitle">
                                <?= date('d M Y', strtotime($run['period_start'])) ?> - <?= date('d M Y', strtotime($run['period_end'])) ?> 
                                • Pay Date: <?= date('d M Y', strtotime($run['pay_date'])) ?>
                            </p>
                        </div>
                    </div>
                    <div class="fw-payroll__run-view-status">
                        <span class="fw-payroll__badge fw-payroll__badge--<?= $run['status'] ?> fw-payroll__badge--large">
                            <?= ucfirst($run['status']) ?>
                        </span>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="fw-payroll__stats-grid fw-payroll__stats-grid--compact">
                    <div class="fw-payroll__stat-card">
                        <div class="fw-payroll__stat-value"><?= $stats['employee_count'] ?? 0 ?></div>
                        <div class="fw-payroll__stat-label">Employees</div>
                    </div>
                    <div class="fw-payroll__stat-card">
                        <div class="fw-payroll__stat-value">R <?= number_format(($stats['total_gross'] ?? 0) / 100, 2) ?></div>
                        <div class="fw-payroll__stat-label">Gross Pay</div>
                    </div>
                    <div class="fw-payroll__stat-card">
                        <div class="fw-payroll__stat-value">R <?= number_format(($stats['total_paye'] ?? 0) / 100, 2) ?></div>
                        <div class="fw-payroll__stat-label">PAYE</div>
                    </div>
                    <div class="fw-payroll__stat-card">
                        <div class="fw-payroll__stat-value">R <?= number_format(($stats['total_net'] ?? 0) / 100, 2) ?></div>
                        <div class="fw-payroll__stat-label">Net Pay</div>
                    </div>
                </div>

                <!-- Action Bar -->
                <div class="fw-payroll__action-bar">
                    <?php if ($canEdit): ?>
                        <button class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollRunView.calculate()">
                            ⚡ Calculate Payroll
                        </button>
                        <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollRunView.addEmployee()">
                            + Add Employee
                        </button>
                    <?php endif; ?>

                    <?php if ($run['status'] === 'calculated'): ?>
                        <button class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollRunView.moveToReview()">
                            → Move to Review
                        </button>
                    <?php endif; ?>

                    <?php if ($canApprove): ?>
                        <button class="fw-payroll__btn fw-payroll__btn--success" onclick="PayrollRunView.approve()">
                            ✓ Approve Run
                        </button>
                    <?php endif; ?>

                    <?php if ($canLock): ?>
                        <button class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollRunView.lock()">
                            🔒 Lock & Generate Payslips
                        </button>
                    <?php endif; ?>

                    <?php if ($canPost): ?>
                        <button class="fw-payroll__btn fw-payroll__btn--success" onclick="PayrollRunView.post()">
                            📊 Post to Finance
                        </button>
                        <button class="fw-payroll__btn fw-payroll__btn--primary" onclick="PayrollRunView.exportBank()">
                            💳 Export Bank File
                        </button>
                    <?php endif; ?>
                </div>

                <div id="actionMessage"></div>

                <!-- Employees List -->
                <div class="fw-payroll__run-employees" id="employeesList">
                    <div class="fw-payroll__loading">Loading employees...</div>
                </div>

            </div>

            <footer class="fw-payroll__footer">
                <span>Payroll v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>

        <!-- Employee Detail Modal -->
        <div class="fw-payroll__modal-overlay" id="employeeModal" aria-hidden="true">
            <div class="fw-payroll__modal fw-payroll__modal--large">
                <div class="fw-payroll__modal-header">
                    <h3 class="fw-payroll__modal-title" id="employeeModalTitle">Employee Details</h3>
                    <button class="fw-payroll__modal-close" onclick="PayrollRunView.closeEmployeeModal()" aria-label="Close">
                        <svg viewBox="0 0 24 24" fill="none">
                            <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <div class="fw-payroll__modal-body" id="employeeModalBody">
                    <div class="fw-payroll__loading">Loading...</div>
                </div>
                <div class="fw-payroll__modal-footer">
                    <button type="button" class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollRunView.closeEmployeeModal()">Close</button>
                </div>
            </div>
        </div>

    </main>

    <script>
        const RUN_ID = <?= $runId ?>;
        const RUN_STATUS = '<?= $run['status'] ?>';
        const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    </script>
    <script src="/payroll/assets/payroll.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/payroll/assets/run_view.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>