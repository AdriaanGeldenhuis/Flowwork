<?php
// /finances/ar/index.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['viewer', 'bookkeeper', 'admin']);
require_once __DIR__ . '/../lib/Csrf.php';

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

// Get AR stats
$stmt = $DB->prepare("
    SELECT 
        COUNT(*) as total_invoices,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_invoices,
        SUM(balance_due) as total_outstanding
    FROM invoices
    WHERE company_id = ?
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Accounts Receivable – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

            <?php
            $finTitle = 'Accounts Receivable';
            $finBack = '/finances/';
            $finCompanyName = $companyName;
            $finFirstName = $firstName;
            include __DIR__ . '/../partials/header.php';
            ?>

            <!-- Main Content -->
            <main class="fw-finance__main">
                
                <!-- Stats -->
                <div class="fw-finance__stats-grid">
                    <div class="fw-finance__stat-card">
                        <div class="fw-finance__stat-value"><?= $stats['total_invoices'] ?></div>
                        <div class="fw-finance__stat-label">Total Invoices</div>
                    </div>
                    <div class="fw-finance__stat-card">
                        <div class="fw-finance__stat-value"><?= $stats['paid_invoices'] ?></div>
                        <div class="fw-finance__stat-label">Paid</div>
                    </div>
                    <div class="fw-finance__stat-card">
                        <div class="fw-finance__stat-value"><?= $stats['overdue_invoices'] ?></div>
                        <div class="fw-finance__stat-label">Overdue</div>
                    </div>
                    <div class="fw-finance__stat-card">
                        <div class="fw-finance__stat-value">R <?= number_format($stats['total_outstanding'], 2) ?></div>
                        <div class="fw-finance__stat-label">Outstanding</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="fw-finance__tabs">
                    <button class="fw-finance__tab fw-finance__tab--active" data-tab="invoices">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/>
                            <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/>
                            <line x1="12" y1="18" x2="12" y2="12" stroke="currentColor" stroke-width="2"/>
                            <line x1="9" y1="15" x2="15" y2="15" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Invoices
                    </button>
                    <button class="fw-finance__tab" data-tab="aging">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M3 3v18h18" stroke="currentColor" stroke-width="2"/>
                            <path d="M7 15l4-4 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Aging Report
                    </button>
                    <button class="fw-finance__tab" data-tab="statements">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="2"/>
                            <path d="M4 10h16" stroke="currentColor" stroke-width="2"/>
                            <path d="M10 4v16" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Customer Statements
                    </button>
                    <button class="fw-finance__tab" data-tab="reminders">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M12 1v22" stroke="currentColor" stroke-width="2"/>
                            <circle cx="12" cy="5" r="3" stroke="currentColor" stroke-width="2"/>
                            <path d="M5 12h14" stroke="currentColor" stroke-width="2"/>
                            <circle cx="5" cy="18" r="2" stroke="currentColor" stroke-width="2"/>
                            <circle cx="19" cy="18" r="2" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Payment Reminders
                    </button>
                </div>

                <!-- Tab Content -->
                <div class="fw-finance__tab-content">
                    
                    <!-- Invoices Tab -->
                    <div class="fw-finance__tab-panel fw-finance__tab-panel--active" id="invoicesPanel">
                        
                        <!-- Toolbar -->
                        <div class="fw-finance__toolbar">
                            <input 
                                type="search" 
                                class="fw-finance__search" 
                                placeholder="Search invoices..." 
                                id="searchInput"
                            >
                            
                            <select class="fw-finance__filter" id="filterStatus">
                                <option value="">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="viewed">Viewed</option>
                                <option value="paid">Paid</option>
                                <option value="overdue">Overdue</option>
                                <option value="cancelled">Cancelled</option>
                            </select>

                            <button class="fw-finance__btn fw-finance__btn--primary" id="syncAllBtn">
                                🔄 Sync All to GL
                            </button>
                        </div>

                        <!-- Invoice List -->
                        <div class="fw-finance__list" id="invoiceList">
                            <div class="fw-finance__loading">Loading invoices...</div>
                        </div>

                        <!-- Pagination -->
                        <div class="fw-finance__pagination" id="pagination"></div>

                    </div>

                    <!-- Aging Report Tab -->
                    <div class="fw-finance__tab-panel" id="agingPanel">
                        <div class="fw-finance__report-content" id="agingReport">
                            <div class="fw-finance__empty-state">Loading aging report...</div>
                        </div>
                    </div>

                    <!-- Customer Statements Tab -->
                    <div class="fw-finance__tab-panel" id="statementsPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            📄 <strong>Customer Statements:</strong> Generate and send account statements to customers.
                        </div>
                        <div class="fw-finance__empty-state">
                            Statement functionality will be loaded here.
                            <br><small>This will integrate with /finances/ar/statement.php</small>
                        </div>
                    </div>

                    <!-- Payment Reminders Tab -->
                    <div class="fw-finance__tab-panel" id="remindersPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            🔔 <strong>Payment Reminders:</strong> Send automated payment reminders to customers with overdue invoices.
                        </div>
                        <div class="fw-finance__empty-state">
                            Reminder functionality will be loaded here.
                            <br><small>This will integrate with /finances/ar/reminders.php</small>
                        </div>
                    </div>

                </div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Accounts Receivable v<?= ASSET_VERSION ?></span>
                <span id="invoiceCount">0 invoices</span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/ar.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>