<?php
// /finances/ap/index.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-FIN-4');

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

// Note: We'll use the receipt_file table as proxy for bills
// In production you'd have a proper bills table
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Payable – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-finance__app-name">Accounts Payable</div>
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
                
                <!-- Tabs -->
                <div class="fw-finance__tabs">
                    <button class="fw-finance__tab fw-finance__tab--active" data-tab="manual">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="2"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Manual Bill Entry
                    </button>
                    <button class="fw-finance__tab" data-tab="receipts">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/>
                            <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        From Receipts Module
                    </button>
                    <button class="fw-finance__tab" data-tab="bills">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="2"/>
                            <path d="M4 10h16" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Bills List
                    </button>
                    <button class="fw-finance__tab" data-tab="payments">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <rect x="3" y="6" width="18" height="12" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
                            <path d="M8 12h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Payments
                    </button>
                    <button class="fw-finance__tab" data-tab="credits">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="2"/>
                            <path d="M4 12h16" stroke="currentColor" stroke-width="2"/>
                            <path d="M12 4v16" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Vendor Credits
                    </button>
                    <button class="fw-finance__tab" data-tab="aging">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M3 3v18h18" stroke="currentColor" stroke-width="2"/>
                            <path d="M7 15l4-4 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Aging
                    </button>
                    <button class="fw-finance__tab" data-tab="statements">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="2"/>
                            <path d="M4 8h16" stroke="currentColor" stroke-width="2"/>
                            <path d="M8 4v16" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Statements
                    </button>
                    <button class="fw-finance__tab" data-tab="threeway">
                        <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                            <path d="M3 12h18" stroke="currentColor" stroke-width="2"/>
                            <path d="M12 3v18" stroke="currentColor" stroke-width="2"/>
                            <circle cx="6" cy="6" r="2" stroke="currentColor" stroke-width="2"/>
                            <circle cx="18" cy="18" r="2" stroke="currentColor" stroke-width="2"/>
                            <circle cx="6" cy="18" r="2" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        3-Way Match
                    </button>
                </div>

                <!-- Tab Content -->
                <div class="fw-finance__tab-content">
                    
                    <!-- Manual Entry Tab -->
                    <div class="fw-finance__tab-panel fw-finance__tab-panel--active" id="manualPanel">
                        
                        <div class="fw-finance__alert fw-finance__alert--info">
                            💡 <strong>Quick Manual Bill Posting:</strong> Use this to quickly post supplier bills directly to GL.
                        </div>

                        <!-- Manual Bill Form -->
                        <div class="fw-finance__form-card">
                            <h3 class="fw-finance__form-card-title">Post Supplier Bill</h3>
                            <form id="manualBillForm">
                                <div class="fw-finance__form-row">
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">Supplier <span class="fw-finance__required">*</span></label>
                                        <select class="fw-finance__input" id="supplierId" required>
                                            <option value="">Select Supplier</option>
                                        </select>
                                    </div>
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">Invoice Date <span class="fw-finance__required">*</span></label>
                                        <input type="date" class="fw-finance__input" id="billDate" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>

                                <div class="fw-finance__form-row">
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">Invoice Number</label>
                                        <input type="text" class="fw-finance__input" id="invoiceNumber" placeholder="Supplier's invoice #">
                                    </div>
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">Due Date</label>
                                        <input type="date" class="fw-finance__input" id="dueDate">
                                    </div>
                                </div>

                                <div class="fw-finance__form-row">
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">Expense Account <span class="fw-finance__required">*</span></label>
                                        <select class="fw-finance__input" id="expenseAccount" required>
                                            <option value="">Select Account</option>
                                        </select>
                                    </div>
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">Amount (excl VAT) <span class="fw-finance__required">*</span></label>
                                        <input type="number" class="fw-finance__input" id="billAmount" step="0.01" required>
                                    </div>
                                </div>

                                <div class="fw-finance__form-row">
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">VAT Amount</label>
                                        <input type="number" class="fw-finance__input" id="vatAmount" step="0.01" value="0">
                                        <small class="fw-finance__help-text">Leave 0 if VAT exempt</small>
                                    </div>
                                    <div class="fw-finance__form-group">
                                        <label class="fw-finance__label">Total (incl VAT)</label>
                                        <input type="number" class="fw-finance__input" id="totalAmount" step="0.01" readonly>
                                    </div>
                                </div>

                                <div class="fw-finance__form-group">
                                    <label class="fw-finance__label">Description</label>
                                    <textarea class="fw-finance__textarea" id="description" rows="2" placeholder="What is this bill for?"></textarea>
                                </div>

                                <div id="billFormMessage"></div>

                                <div class="fw-finance__form-actions">
                                    <button type="submit" class="fw-finance__btn fw-finance__btn--primary">
                                        Post Bill to GL
                                    </button>
                                </div>
                            </form>
                        </div>

                    </div>

                    <!-- Receipts Module Tab -->
                    <div class="fw-finance__tab-panel" id="receiptsPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            📄 <strong>Integration with Receipts Module:</strong> Coming soon - sync approved bills from your receipts/AP workflow.
                        </div>
                    </div>

                    <!-- Bills List Tab -->
                    <div class="fw-finance__tab-panel" id="billsPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            📋 <strong>Bills List:</strong> View and manage all supplier bills.
                        </div>
                        <div class="fw-finance__empty-state">
                            Bills list functionality will be loaded here.
                            <br><small>This will integrate with /finances/ap/bills_list.php</small>
                        </div>
                    </div>

                    <!-- Payments Tab -->
                    <div class="fw-finance__tab-panel" id="paymentsPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            💳 <strong>Payments:</strong> Record and track payments to suppliers.
                        </div>
                        <div class="fw-finance__empty-state">
                            Payment functionality will be loaded here.
                            <br><small>This will integrate with /finances/ap/payment_list.php</small>
                        </div>
                    </div>

                    <!-- Vendor Credits Tab -->
                    <div class="fw-finance__tab-panel" id="creditsPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            🔖 <strong>Vendor Credits:</strong> Manage credit notes from suppliers.
                        </div>
                        <div class="fw-finance__empty-state">
                            Vendor credits functionality will be loaded here.
                            <br><small>This will integrate with /finances/ap/vendor_credit_new.php</small>
                        </div>
                    </div>

                    <!-- Aging Tab -->
                    <div class="fw-finance__tab-panel" id="agingPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            📊 <strong>Aging Report:</strong> View outstanding payables by age.
                        </div>
                        <div class="fw-finance__empty-state">
                            Aging report will be loaded here.
                            <br><small>This will integrate with /finances/ap/aging.php</small>
                        </div>
                    </div>

                    <!-- Statements Tab -->
                    <div class="fw-finance__tab-panel" id="statementsPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            📄 <strong>Vendor Statements:</strong> View statements from suppliers.
                        </div>
                        <div class="fw-finance__empty-state">
                            Statements functionality will be loaded here.
                            <br><small>This will integrate with /finances/ap/statement.php</small>
                        </div>
                    </div>

                    <!-- 3-Way Match Tab -->
                    <div class="fw-finance__tab-panel" id="threewayPanel">
                        <div class="fw-finance__alert fw-finance__alert--info">
                            🔍 <strong>3-Way Match:</strong> Match purchase orders, receipts, and invoices.
                        </div>
                        <div class="fw-finance__empty-state">
                            3-Way match functionality will be loaded here.
                            <br><small>This will integrate with /finances/ap/three_way_match.php</small>
                        </div>
                    </div>

                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance AP v<?= ASSET_VERSION ?></span>
                <span id="statusText">Ready</span>
            </footer>

        </div>
    </main>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/ap.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>