<?php
// /finances/chart.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-FIN-1');

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chart of Accounts – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-finance__app-name">Chart of Accounts</div>
                    </div>
                </div>

                <div class="fw-finance__greeting">
                    Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-finance__controls">
                    <a href="/finances/" class="fw-finance__back-btn" title="Back to Dashboard">
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
                
                <!-- Toolbar -->
                <div class="fw-finance__toolbar">
                    <input 
                        type="search" 
                        class="fw-finance__search" 
                        placeholder="Search accounts..." 
                        id="searchInput"
                        autocomplete="off"
                    >
                    
                    <select class="fw-finance__filter" id="filterType">
                        <option value="">All Types</option>
                        <option value="asset">Assets</option>
                        <option value="liability">Liabilities</option>
                        <option value="equity">Equity</option>
                        <option value="revenue">Revenue</option>
                        <option value="expense">Expenses</option>
                    </select>

                    <select class="fw-finance__filter" id="filterStatus">
                        <option value="1">Active Only</option>
                        <option value="0">Inactive Only</option>
                        <option value="">All</option>
                    </select>

                    <button class="fw-finance__btn fw-finance__btn--primary" id="addAccountBtn">
                        + New Account
                    </button>
                    
                    <button class="fw-finance__btn fw-finance__btn--secondary" id="exportBtn">
                        Export CSV
                    </button>
                </div>

                <!-- Accounts Tree -->
                <div class="fw-finance__chart-container">
                    <div class="fw-finance__chart-tree" id="accountsTree">
                        <div class="fw-finance__loading">Loading chart of accounts...</div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance v<?= ASSET_VERSION ?></span>
                <span id="accountCount">0 accounts</span>
            </footer>

        </div>
    </main>

    <!-- Add/Edit Account Modal -->
    <div class="fw-finance__modal-overlay" id="accountModal" aria-hidden="true">
        <div class="fw-finance__modal">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title" id="modalTitle">Add Account</h3>
                <button class="fw-finance__modal-close" id="modalClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <form id="accountForm">
                    <input type="hidden" id="accountId" name="account_id">
                    
                    <div class="fw-finance__form-row">
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">
                                Account Code <span class="fw-finance__required">*</span>
                            </label>
                            <input 
                                type="text" 
                                class="fw-finance__input" 
                                id="accountCode" 
                                name="account_code"
                                required
                                maxlength="20"
                                placeholder="e.g. 1100"
                            >
                        </div>
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">
                                Account Type <span class="fw-finance__required">*</span>
                            </label>
                            <select class="fw-finance__input" id="accountType" name="account_type" required>
                                <option value="">Select Type</option>
                                <option value="asset">Asset</option>
                                <option value="liability">Liability</option>
                                <option value="equity">Equity</option>
                                <option value="revenue">Revenue</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">
                            Account Name <span class="fw-finance__required">*</span>
                        </label>
                        <input 
                            type="text" 
                            class="fw-finance__input" 
                            id="accountName" 
                            name="account_name"
                            required
                            maxlength="255"
                            placeholder="e.g. Bank - FNB Cheque"
                        >
                    </div>

                    <div class="fw-finance__form-row">
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Parent Account</label>
                            <select class="fw-finance__input" id="parentId" name="parent_id">
                                <option value="">None (Top Level)</option>
                            </select>
                        </div>
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Default Tax Code</label>
                            <select class="fw-finance__input" id="taxCodeId" name="tax_code_id">
                                <option value="">None</option>
                            </select>
                        </div>
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__checkbox-wrapper">
                            <input 
                                type="checkbox" 
                                class="fw-finance__checkbox" 
                                id="isActive" 
                                name="is_active"
                                checked
                            >
                            Active
                        </label>
                    </div>

                    <div id="formMessage"></div>
                </form>
            </div>
            <div class="fw-finance__modal-footer">
                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="cancelBtn">
                    Cancel
                </button>
                <button type="submit" form="accountForm" class="fw-finance__btn fw-finance__btn--primary" id="saveBtn">
                    Save Account
                </button>
            </div>
        </div>
    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/chart.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>