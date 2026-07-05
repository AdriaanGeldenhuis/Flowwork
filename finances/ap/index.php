<?php
// /finances/ap/index.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'viewer';
$canWrite = in_array($userRole, ['admin', 'bookkeeper']);

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

// Fetch suppliers for statement/matching forms
$stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND status = 'active' ORDER BY name");
$stmt->execute([$companyId]);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSRF token for JS
require_once __DIR__ . '/../lib/Csrf.php';
$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Payable – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

            <?php
            $finTitle = 'Accounts Payable';
            $finBack = '/finances/';
            $finCompanyName = $companyName;
            $finFirstName = $firstName;
            include __DIR__ . '/../partials/header.php';
            ?>

            <!-- Main Content -->
            <main class="fw-finance__main">
                
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
                        <?php if ($canWrite): ?>
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
                                    <button type="submit" class="fw-finance__btn fw-finance__btn--primary">Post Bill to GL</button>
                                </div>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="fw-finance__empty-state">You do not have permission to create bills. Please use the other tabs to view AP data.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Receipts Module Tab -->
                    <div class="fw-finance__tab-panel" id="receiptsPanel">
                        <div id="receiptsContent"><div class="fw-finance__loading">Loading receipts...</div></div>
                    </div>

                    <!-- Bills List Tab -->
                    <div class="fw-finance__tab-panel" id="billsPanel">
                        <div class="fw-finance__toolbar" style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center;">
                            <input type="search" class="fw-finance__input" placeholder="Search bills..." id="billSearchInput" style="max-width:250px;">
                            <select class="fw-finance__input" id="billStatusFilter" style="max-width:180px;">
                                <option value="">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="review">Review</option>
                                <option value="approved">Approved</option>
                                <option value="posted">Posted</option>
                                <option value="paid">Paid</option>
                                <option value="blocked">Blocked</option>
                            </select>
                            <?php if ($canWrite): ?>
                            <a href="/finances/ap/bill_new.php" class="fw-finance__btn fw-finance__btn--primary">+ New Bill</a>
                            <?php endif; ?>
                        </div>
                        <div id="billsListContent"><div class="fw-finance__loading">Loading bills...</div></div>
                    </div>

                    <!-- Payments Tab -->
                    <div class="fw-finance__tab-panel" id="paymentsPanel">
                        <div class="fw-finance__toolbar" style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center;">
                            <select class="fw-finance__input" id="paymentSupplierFilter" style="max-width:250px;">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($canWrite): ?>
                            <a href="/finances/ap/payment_new.php" class="fw-finance__btn fw-finance__btn--primary">+ New Payment</a>
                            <?php endif; ?>
                        </div>
                        <div id="paymentsListContent"><div class="fw-finance__loading">Loading payments...</div></div>
                    </div>

                    <!-- Vendor Credits Tab -->
                    <div class="fw-finance__tab-panel" id="creditsPanel">
                        <div class="fw-finance__toolbar" style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center;">
                            <select class="fw-finance__input" id="creditStatusFilter" style="max-width:180px;">
                                <option value="">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="applied">Applied</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <?php if ($canWrite): ?>
                            <a href="/finances/ap/vendor_credit_new.php" class="fw-finance__btn fw-finance__btn--primary">+ New Credit</a>
                            <?php endif; ?>
                        </div>
                        <div id="creditsListContent"><div class="fw-finance__loading">Loading vendor credits...</div></div>
                    </div>

                    <!-- Aging Tab -->
                    <div class="fw-finance__tab-panel" id="agingPanel">
                        <div id="agingContent"><div class="fw-finance__loading">Loading aging report...</div></div>
                    </div>

                    <!-- Statements Tab -->
                    <div class="fw-finance__tab-panel" id="statementsPanel">
                        <div class="fw-finance__toolbar" style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;align-items:flex-end;">
                            <label style="display:flex;flex-direction:column;font-size:0.875rem;">
                                Supplier
                                <select class="fw-finance__input" id="stmtSupplierSelect" style="min-width:200px;">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label style="display:flex;flex-direction:column;font-size:0.875rem;">
                                From
                                <input type="date" class="fw-finance__input" id="stmtStartDate">
                            </label>
                            <label style="display:flex;flex-direction:column;font-size:0.875rem;">
                                To
                                <input type="date" class="fw-finance__input" id="stmtEndDate">
                            </label>
                            <button class="fw-finance__btn fw-finance__btn--primary" id="stmtGenerateBtn">Generate</button>
                        </div>
                        <div id="statementsContent"></div>
                    </div>

                    <!-- 3-Way Match Tab -->
                    <div class="fw-finance__tab-panel" id="threewayPanel">
                        <?php if ($canWrite): ?>
                        <div class="fw-finance__toolbar" style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;align-items:flex-end;">
                            <label style="display:flex;flex-direction:column;font-size:0.875rem;">
                                Supplier
                                <select class="fw-finance__input" id="twSupplierSelect" style="min-width:200px;">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="fw-finance__btn" id="twLoadBtn">Load Lines</button>
                        </div>
                        <div id="threewayContent"></div>
                        <?php else: ?>
                        <div class="fw-finance__empty-state">You do not have permission to perform 3-way matching.</div>
                        <?php endif; ?>
                    </div>

                </div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Accounts Payable v<?= ASSET_VERSION ?></span>
                <span id="statusText">Ready</span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

    <script>window.AP_CONFIG = { canWrite: <?= $canWrite ? 'true' : 'false' ?> };</script>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/ap.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>