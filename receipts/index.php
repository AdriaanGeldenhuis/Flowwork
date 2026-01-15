<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-RECEIPTS-1');

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

// Active tab
// Add an "overview" tab as the default landing page. Only known tabs are allowed.
$activeTab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'inbox', 'exceptions', 'approved', 'all'];
if (!in_array($activeTab, $allowedTabs)) {
    $activeTab = 'overview';
}

// Fetch counts
$counts = [
    'inbox' => 0,
    'exceptions' => 0,
    'approved' => 0,
    'all' => 0
];

// Inbox: pending OCR or draft invoices
$stmt = $DB->prepare("
    SELECT COUNT(*) FROM receipt_file 
    WHERE company_id = ? AND ocr_status = 'pending'
");
$stmt->execute([$companyId]);
$counts['inbox'] = (int)$stmt->fetchColumn();

// Exceptions: policy violations
$stmt = $DB->prepare("
    SELECT COUNT(DISTINCT invoice_id) FROM receipt_policy_log 
    WHERE company_id = ? AND severity = 'error' AND override_by IS NULL
");
$stmt->execute([$companyId]);
$counts['exceptions'] = (int)$stmt->fetchColumn();

// Approved: posted vendor bills (AP). Count bills that have been posted or paid.
$stmt = $DB->prepare("\n    SELECT COUNT(*) FROM ap_bills\n    WHERE company_id = ? AND status IN ('posted', 'paid')\n");
$stmt->execute([$companyId]);
$counts['approved'] = (int)$stmt->fetchColumn();

// All files
$stmt = $DB->prepare("SELECT COUNT(*) FROM receipt_file WHERE company_id = ?");
$stmt->execute([$companyId]);
$counts['all'] = (int)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts & Bills – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="assets/receipts.css?v=<?= ASSET_VERSION ?>">
    <!-- Additional styles for widgets -->
    <link rel="stylesheet" href="assets/widgets.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-receipts">
    <div class="fw-receipts__container">
        
        <!-- Header -->
        <header class="fw-receipts__header">
            <div class="fw-receipts__brand">
                <div class="fw-receipts__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-receipts__brand-text">
                    <div class="fw-receipts__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-receipts__app-name">Receipts</div>
                </div>
            </div>

            <div class="fw-receipts__greeting">
                Hello, <span class="fw-receipts__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-receipts__controls">
                <a href="/" class="fw-receipts__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </a>
                
                <button class="fw-receipts__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-receipts__theme-icon fw-receipts__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-receipts__theme-icon fw-receipts__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-receipts__menu-wrapper">
                    <button class="fw-receipts__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-receipts__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="upload.php" class="fw-receipts__kebab-item">Upload Receipt</a>
                        <a href="settings.php" class="fw-receipts__kebab-item">Settings</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-receipts__main">
            <!-- Action bar: Upload button above tabs -->
            <div class="fw-receipts__actions">
                <button class="fw-receipts__btn fw-receipts__btn--primary" onclick="location.href='upload.php'">+ Upload Receipt</button>
            </div>

            <!-- Tabs -->
            <div class="fw-receipts__tabs">
                <a href="?tab=overview" class="fw-receipts__tab <?= $activeTab === 'overview' ? 'fw-receipts__tab--active' : '' ?>">
                    Overview
                </a>
                <a href="?tab=inbox" class="fw-receipts__tab <?= $activeTab === 'inbox' ? 'fw-receipts__tab--active' : '' ?>">
                    Inbox (<?= $counts['inbox'] ?>)
                </a>
                <a href="?tab=exceptions" class="fw-receipts__tab <?= $activeTab === 'exceptions' ? 'fw-receipts__tab--active' : '' ?>">
                    Exceptions (<?= $counts['exceptions'] ?>)
                </a>
                <a href="?tab=approved" class="fw-receipts__tab <?= $activeTab === 'approved' ? 'fw-receipts__tab--active' : '' ?>">
                    Approved (<?= $counts['approved'] ?>)
                </a>
                <a href="?tab=all" class="fw-receipts__tab <?= $activeTab === 'all' ? 'fw-receipts__tab--active' : '' ?>">
                    All (<?= $counts['all'] ?>)
                </a>
            </div>

            <?php if ($activeTab === 'overview'): ?>
                <!-- Widget Grid for overview only -->
                <div class="fw-receipts__widgets" id="widgetsGrid"></div>
            <?php else: ?>
                <!-- Toolbar visible only on non-overview tabs -->
                <div class="fw-receipts__toolbar">
                    <input 
                        type="search" 
                        class="fw-receipts__search" 
                        placeholder="Search receipts..." 
                        id="searchInput"
                        autocomplete="off"
                    >
                    
                    <select class="fw-receipts__filter" id="filterSupplier">
                        <option value="">All Suppliers</option>
                        <?php
                        $suppliers = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' ORDER BY name");
                        $suppliers->execute([$companyId]);
                        foreach ($suppliers as $sup) {
                            echo '<option value="' . $sup['id'] . '">' . htmlspecialchars($sup['name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- List visible only on non-overview tabs -->
                <div class="fw-receipts__list" id="receiptsList">
                    <div class="fw-receipts__loading">Loading receipts...</div>
                </div>
            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="fw-receipts__footer">
            <span>Receipts v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <!-- Widget Picker Modal -->
    <div id="widgetPickerModal" class="fw-receipts__modal-overlay" aria-hidden="true">
        <div class="fw-receipts__widget-picker" role="dialog" aria-modal="true">
            <h2 class="fw-receipts__widget-picker-title">Select a Widget</h2>
            <ul id="widgetPickerList" class="fw-receipts__widget-list">
                <li data-key="this_month_spend">This Month Spend</li>
                <li data-key="unreviewed_uploads">Unreviewed Uploads</li>
                <li data-key="bank_matches_pending">Bank Matches Pending</li>
                <li data-key="top_suppliers_90d">Top Suppliers (90d)</li>
                <li data-key="cost_by_project">Cost by Project</li>
                <li data-key="recent_receipts">Recent Receipts</li>
            </ul>
            <button type="button" class="fw-receipts__btn" onclick="closeWidgetPicker()">Close</button>
        </div>
    </div>

    <!-- Load widget registry before receipts.js so it can reference global ReceiptsWidgets -->
    <script src="assets/widgets.registry.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="assets/receipts.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>