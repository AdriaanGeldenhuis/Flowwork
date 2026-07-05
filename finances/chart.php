<?php
// /finances/chart.php — SARS bookkeeping-grade Chart of Accounts
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/lib/Csrf.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', FIN_ASSET_VERSION);

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];
$userRole  = $_SESSION['role'] ?? 'viewer';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$firstName = $row ? $row['first_name'] : 'User';

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$companyName = $row ? $row['name'] : 'Company';

$csrfToken = Csrf::token();
$isAdmin = ($userRole === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Chart of Accounts – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

            <!-- Header -->
            <?php $finTitle = 'Chart of Accounts'; $finBack = '/finances/'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/partials/header.php'; ?>

            <!-- Main Content -->
            <main class="fw-finance__main">

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

                    <?php if ($isAdmin): ?>
                    <button class="fw-finance__btn fw-finance__btn--secondary" id="importBtn" title="Import accounts from CSV (admin only)">
                        Import CSV
                    </button>
                    <input type="file" id="importFile" accept=".csv" style="display:none">
                    <?php endif; ?>
                </div>

                <!-- Accounts Tree -->
                <div class="fw-finance__chart-container">
                    <div class="fw-finance__chart-tree" id="accountsTree">
                        <div class="fw-finance__loading">Loading chart of accounts...</div>
                    </div>
                </div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Chart of Accounts v<?= ASSET_VERSION ?></span>
                <span id="accountCount">0 accounts</span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

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
                            <label class="fw-finance__label">Account Subtype</label>
                            <select class="fw-finance__input" id="accountSubtype" name="account_subtype">
                                <option value="">Select subtype...</option>
                            </select>
                        </div>
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Normal Balance</label>
                            <select class="fw-finance__input" id="normalBalance" name="normal_balance">
                                <option value="debit">Debit</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
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
                        <label class="fw-finance__label">Description</label>
                        <textarea
                            class="fw-finance__input fw-finance__textarea"
                            id="accountDescription"
                            name="description"
                            maxlength="500"
                            rows="2"
                            placeholder="Optional guidance for this account..."
                        ></textarea>
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

    <script>
        // Server-side config passed to chart.js
        window.__COA_CONFIG = {
            isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
            csrfToken: <?= json_encode($csrfToken) ?>
        };
    </script>
    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/chart.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
