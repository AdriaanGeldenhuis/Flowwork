<?php
// /finances/bank/index.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/Csrf.php';
requireRoles(['admin', 'bookkeeper']);

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
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

            <?php
            $finTitle = 'Bank Feeds';
            $finBack = '/finances/';
            $finCompanyName = $companyName;
            $finFirstName = $firstName;
            include __DIR__ . '/../partials/header.php';
            ?>

            <!-- Main Content -->
            <main class="fw-finance__main">
                
                <!-- Tabs -->
                <div class="fw-finance__tabs">
                    <button class="fw-finance__tab fw-finance__tab--active" data-tab="accounts">
                        Bank Accounts
                    </button>
                    <button class="fw-finance__tab" data-tab="import">
                        Import Statement
                    </button>
                    <button class="fw-finance__tab" data-tab="transactions">
                        Transactions <span class="fw-finance__tab-badge" id="txBadge" style="display:none"></span>
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
                            <button class="fw-finance__btn fw-finance__btn--secondary" id="reconcileBtn">
                                Reconcile
                            </button>
                        </div>

                        <div class="fw-finance__bank-accounts-grid">
                            <?php if (empty($bankAccounts)): ?>
                                <div class="fw-finance__empty-state">
                                    No bank accounts configured. Add one to get started.
                                </div>
                            <?php else: ?>
                                <?php foreach ($bankAccounts as $acc): ?>
                                    <div class="fw-finance__bank-account-card" data-account-id="<?= $acc['id'] ?>">
                                        <div class="fw-finance__bank-account-header">
                                            <div class="fw-finance__bank-account-icon">🏦</div>
                                            <div class="fw-finance__bank-account-info">
                                                <div class="fw-finance__bank-account-name"><?= htmlspecialchars($acc['name']) ?></div>
                                                <div class="fw-finance__bank-account-meta">
                                                    <?= htmlspecialchars($acc['bank_name'] ?? 'Unknown Bank') ?> &bull;
                                                    <?= htmlspecialchars($acc['account_no'] ?? 'No account #') ?>
                                                    <?php if (!empty($acc['last_reconciled_date'])): ?>
                                                        &bull; Reconciled: <?= htmlspecialchars($acc['last_reconciled_date']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="fw-finance__badge fw-finance__badge--warning bank-unmatched-badge" data-account-id="<?= $acc['id'] ?>" style="display:none"></span>
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
                                    <label class="fw-finance__label">Statement File <span class="fw-finance__required">*</span></label>
                                    <input type="file" class="fw-finance__input" id="statementFile" accept=".csv,.pdf" required>
                                    <small class="fw-finance__help-text">
                                        Accepts CSV or PDF bank statements. SA banks supported: FNB, ABSA, Nedbank, Standard Bank, Capitec.
                                    </small>
                                </div>

                                <div id="importMessage"></div>

                                <div class="fw-finance__form-actions">
                                    <button type="submit" class="fw-finance__btn fw-finance__btn--primary" id="importSubmitBtn">
                                        Upload & Parse
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- PDF Preview Section (hidden until PDF is parsed) -->
                        <div class="fw-finance__form-card" id="pdfPreviewCard" style="display:none">
                            <div class="fw-finance__form-card-header" style="display:flex;justify-content:space-between;align-items:center">
                                <h3 class="fw-finance__form-card-title">Preview Extracted Transactions</h3>
                                <span class="fw-finance__badge fw-finance__badge--success" id="pdfBankBadge"></span>
                            </div>
                            <div id="pdfWarnings"></div>
                            <div class="fw-finance__table-wrapper" style="max-height:400px;overflow-y:auto">
                                <table class="fw-finance__table" id="pdfPreviewTable">
                                    <thead>
                                        <tr>
                                            <th style="width:30px"><input type="checkbox" id="pdfSelectAll" checked></th>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th style="text-align:right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pdfPreviewBody"></tbody>
                                </table>
                            </div>
                            <div class="fw-finance__form-actions" style="margin-top:16px">
                                <span id="pdfSelectedCount" style="font-size:13px;color:var(--fw-text-secondary)"></span>
                                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="pdfCancelBtn">Cancel</button>
                                <button type="button" class="fw-finance__btn fw-finance__btn--primary" id="pdfConfirmBtn">Confirm Import</button>
                            </div>
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

                            <input type="date" class="fw-finance__filter" id="filterDateFrom" title="From date">
                            <input type="date" class="fw-finance__filter" id="filterDateTo" title="To date">

                            <button class="fw-finance__btn fw-finance__btn--primary" id="applyRulesBtn">
                                Apply Rules
                            </button>
                        </div>

                        <div class="fw-finance__list" id="transactionsList">
                            <div class="fw-finance__loading">Loading transactions...</div>
                        </div>

                        <div class="fw-finance__pagination" id="txPagination" style="display:none">
                            <button class="fw-finance__btn fw-finance__btn--secondary fw-finance__btn--small" id="txPrevBtn" disabled>Prev</button>
                            <span class="fw-finance__pagination-info" id="txPageInfo"></span>
                            <button class="fw-finance__btn fw-finance__btn--secondary fw-finance__btn--small" id="txNextBtn" disabled>Next</button>
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

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Bank Feeds v<?= ASSET_VERSION ?></span>
                <span id="statusText">Ready</span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

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
                        <label class="fw-finance__label">Tax Code (SARS)</label>
                        <select class="fw-finance__input" id="ruleTaxCode">
                            <option value="">No Tax Code</option>
                        </select>
                        <small class="fw-finance__help-text">Assign a tax code for SARS VAT tracking</small>
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

    <!-- Reconciliation Modal -->
    <div class="fw-finance__modal-overlay" id="reconcileModal" aria-hidden="true">
        <div class="fw-finance__modal">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title">Close Bank Reconciliation</h3>
                <button class="fw-finance__modal-close" id="reconcileModalClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <form id="reconcileForm">
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Bank Account <span class="fw-finance__required">*</span></label>
                        <select class="fw-finance__input" id="reconcileBankAccount" required>
                            <option value="">Select Bank Account</option>
                            <?php foreach ($bankAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Statement Date <span class="fw-finance__required">*</span></label>
                        <input type="date" class="fw-finance__input" id="reconcileDate" required>
                    </div>
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Closing Balance (R)</label>
                        <input type="number" class="fw-finance__input" id="reconcileBalance" step="0.01" placeholder="Optional">
                    </div>
                    <div id="reconcileMessage"></div>
                </form>
            </div>
            <div class="fw-finance__modal-footer">
                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="reconcileCancelBtn">Cancel</button>
                <button type="submit" form="reconcileForm" class="fw-finance__btn fw-finance__btn--primary">Close Reconciliation</button>
            </div>
        </div>
    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/bank.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>