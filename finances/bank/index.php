<?php
// /finances/bank/index.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-FIN-5');

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

// Get bank accounts
$stmt = $DB->prepare("
    SELECT * FROM gl_bank_accounts 
    WHERE company_id = ? AND is_active = 1
    ORDER BY name ASC
");
$stmt->execute([$companyId]);
$bankAccounts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Feeds – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-finance__app-name">Bank Feeds</div>
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
                    <button class="fw-finance__tab fw-finance__tab--active" data-tab="accounts">
                        Bank Accounts
                    </button>
                    <button class="fw-finance__tab" data-tab="import">
                        Import Statement
                    </button>
                    <button class="fw-finance__tab" data-tab="transactions">
                        Transactions
                    </button>
                    <button class="fw-finance__tab" data-tab="rules">
                        Rules
                    </button>
                </div>

                <!-- Tab Content -->
                <div class="fw-finance__tab-content">
                    
                    <!-- Bank Accounts Tab -->
                    <div class="fw-finance__tab-panel fw-finance__tab-panel--active" id="accountsPanel">
                        
                        <div class="fw-finance__toolbar">
                            <button class="fw-finance__btn fw-finance__btn--primary" id="addBankAccountBtn">
                                + Add Bank Account
                            </button>
                        </div>

                        <div class="fw-finance__bank-accounts-grid">
                            <?php if (empty($bankAccounts)): ?>
                                <div class="fw-finance__empty-state">
                                    No bank accounts configured. Add one to get started.
                                </div>
                            <?php else: ?>
                                <?php foreach ($bankAccounts as $acc): ?>
                                    <div class="fw-finance__bank-account-card">
                                        <div class="fw-finance__bank-account-header">
                                            <div class="fw-finance__bank-account-icon">🏦</div>
                                            <div class="fw-finance__bank-account-info">
                                                <div class="fw-finance__bank-account-name"><?= htmlspecialchars($acc['name']) ?></div>
                                                <div class="fw-finance__bank-account-meta">
                                                    <?= htmlspecialchars($acc['bank_name'] ?? 'Unknown Bank') ?> • 
                                                    <?= htmlspecialchars($acc['account_no'] ?? 'No account #') ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="fw-finance__bank-account-balance">
                                            <div class="fw-finance__bank-account-balance-label">Current Balance</div>
                                            <div class="fw-finance__bank-account-balance-value">
                                                R <?= number_format($acc['current_balance_cents'] / 100, 2) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- Import Statement Tab -->
                    <div class="fw-finance__tab-panel" id="importPanel">
                        
                        <div class="fw-finance__alert fw-finance__alert--info">
                            📥 <strong>Import CSV Bank Statement:</strong> Upload your bank statement in CSV format.
                        </div>

                        <div class="fw-finance__form-card">
                            <h3 class="fw-finance__form-card-title">Import Bank Statement</h3>
                            <form id="importForm">
                                <div class="fw-finance__form-group">
                                    <label class="fw-finance__label">Bank Account <span class="fw-finance__required">*</span></label>
                                    <select class="fw-finance__input" id="importBankAccount" required>
                                        <option value="">Select Bank Account</option>
                                        <?php foreach ($bankAccounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="fw-finance__form-group">
                                    <label class="fw-finance__label">CSV File <span class="fw-finance__required">*</span></label>
                                    <input type="file" class="fw-finance__input" id="csvFile" accept=".csv" required>
                                    <small class="fw-finance__help-text">
                                        Format: Date, Description, Amount (negative for debits, positive for credits)
                                    </small>
                                </div>

                                <div id="importMessage"></div>

                                <div class="fw-finance__form-actions">
                                    <button type="submit" class="fw-finance__btn fw-finance__btn--primary">
                                        Upload & Parse
                                    </button>
                                </div>
                            </form>
                        </div>

                    </div>

                    <!-- Transactions Tab -->
                    <div class="fw-finance__tab-panel" id="transactionsPanel">
                        
                        <div class="fw-finance__toolbar">
                            <select class="fw-finance__filter" id="filterBankAccount">
                                <option value="">All Bank Accounts</option>
                                <?php foreach ($bankAccounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select class="fw-finance__filter" id="filterMatched">
                                <option value="">All Transactions</option>
                                <option value="0">Unmatched</option>
                                <option value="1">Matched</option>
                            </select>

                            <button class="fw-finance__btn fw-finance__btn--primary" id="applyRulesBtn">
                                🤖 Apply Rules
                            </button>
                        </div>

                        <div class="fw-finance__list" id="transactionsList">
                            <div class="fw-finance__loading">Loading transactions...</div>
                        </div>

                    </div>

                    <!-- Rules Tab -->
                    <div class="fw-finance__tab-panel" id="rulesPanel">
                        
                        <div class="fw-finance__toolbar">
                            <button class="fw-finance__btn fw-finance__btn--primary" id="addRuleBtn">
                                + Add Rule
                            </button>
                        </div>

                        <div class="fw-finance__list" id="rulesList">
                            <div class="fw-finance__loading">Loading rules...</div>
                        </div>

                    </div>

                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance Bank v<?= ASSET_VERSION ?></span>
                <span id="statusText">Ready</span>
            </footer>

        </div>
    </main>

    <!-- Add Bank Account Modal -->
    <div class="fw-finance__modal-overlay" id="bankAccountModal" aria-hidden="true">
        <div class="fw-finance__modal">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title">Add Bank Account</h3>
                <button class="fw-finance__modal-close" id="modalClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <form id="bankAccountForm">
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Account Name <span class="fw-finance__required">*</span></label>
                        <input type="text" class="fw-finance__input" id="bankAccountName" required placeholder="e.g. FNB Cheque Account">
                    </div>

                    <div class="fw-finance__form-row">
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Bank Name</label>
                            <input type="text" class="fw-finance__input" id="bankName" placeholder="e.g. FNB">
                        </div>
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Account Number</label>
                            <input type="text" class="fw-finance__input" id="accountNo" placeholder="Last 4 digits">
                        </div>
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Link to GL Account</label>
                        <select class="fw-finance__input" id="glAccountId">
                            <option value="">Select GL Account</option>
                        </select>
                        <small class="fw-finance__help-text">Link to your Chart of Accounts (usually 1110 - Bank Accounts)</small>
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Opening Balance (R)</label>
                        <input type="number" class="fw-finance__input" id="openingBalance" step="0.01" value="0">
                    </div>

                    <div id="bankAccountMessage"></div>
                </form>
            </div>
            <div class="fw-finance__modal-footer">
                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="cancelBtn">Cancel</button>
                <button type="submit" form="bankAccountForm" class="fw-finance__btn fw-finance__btn--primary">Save</button>
            </div>
        </div>
    </div>

    <!-- Add Rule Modal -->
    <div class="fw-finance__modal-overlay" id="ruleModal" aria-hidden="true">
        <div class="fw-finance__modal">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title">Add Bank Rule</h3>
                <button class="fw-finance__modal-close" id="ruleModalClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <form id="ruleForm">
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Rule Name <span class="fw-finance__required">*</span></label>
                        <input type="text" class="fw-finance__input" id="ruleName" required placeholder="e.g. Yoco Payouts">
                    </div>

                    <div class="fw-finance__form-row">
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Match Field</label>
                            <select class="fw-finance__input" id="matchField">
                                <option value="description">Description</option>
                                <option value="reference">Reference</option>
                            </select>
                        </div>
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Match Type</label>
                            <select class="fw-finance__input" id="matchOperator">
                                <option value="contains">Contains</option>
                                <option value="starts_with">Starts With</option>
                                <option value="equals">Equals</option>
                            </select>
                        </div>
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Match Value <span class="fw-finance__required">*</span></label>
                        <input type="text" class="fw-finance__input" id="matchValue" required placeholder="e.g. YOCO PAYOUT">
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Post to GL Account <span class="fw-finance__required">*</span></label>
                        <select class="fw-finance__input" id="ruleGlAccount" required>
                            <option value="">Select Account</option>
                        </select>
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Description Template</label>
                        <input type="text" class="fw-finance__input" id="descriptionTemplate" placeholder="e.g. Yoco card sales deposit">
                    </div>

                    <div id="ruleMessage"></div>
                </form>
            </div>
            <div class="fw-finance__modal-footer">
                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="ruleCancelBtn">Cancel</button>
                <button type="submit" form="ruleForm" class="fw-finance__btn fw-finance__btn--primary">Save Rule</button>
            </div>
        </div>
    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/bank.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>