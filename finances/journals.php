<?php
// /finances/journals.php
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

// Check for period locks
$stmt = $DB->prepare("SELECT MAX(lock_date) as latest_lock FROM gl_period_locks WHERE company_id = ?");
$stmt->execute([$companyId]);
$lockInfo = $stmt->fetch();
$latestLock = $lockInfo['latest_lock'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Entries – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-finance__app-name">Journal Entries</div>
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
                
                <?php if ($latestLock): ?>
                <div class="fw-finance__alert fw-finance__alert--info">
                    ⚠️ Period locked up to <strong><?= date('j M Y', strtotime($latestLock)) ?></strong>. 
                    Entries before this date cannot be posted or modified.
                </div>
                <?php endif; ?>

                <!-- Toolbar -->
                <div class="fw-finance__toolbar">
                    <input 
                        type="date" 
                        class="fw-finance__filter" 
                        id="filterDateFrom"
                        title="From Date"
                    >
                    <input 
                        type="date" 
                        class="fw-finance__filter" 
                        id="filterDateTo"
                        title="To Date"
                    >
                    
                    <select class="fw-finance__filter" id="filterModule">
                        <option value="">All Modules</option>
                        <option value="manual">Manual</option>
                        <option value="ar">Accounts Receivable</option>
                        <option value="ap">Accounts Payable</option>
                        <option value="bank">Bank</option>
                        <option value="payroll">Payroll</option>
                        <option value="pos">Point of Sale</option>
                        <option value="depreciation">Depreciation</option>
                    </select>

                    <input 
                        type="search" 
                        class="fw-finance__search" 
                        placeholder="Search memo, reference..." 
                        id="searchInput"
                        autocomplete="off"
                    >

                    <button class="fw-finance__btn fw-finance__btn--primary" id="addJournalBtn">
                        + New Journal Entry
                    </button>
                </div>

                <!-- Journal List -->
                <div class="fw-finance__journal-list" id="journalList">
                    <div class="fw-finance__loading">Loading journal entries...</div>
                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance v<?= ASSET_VERSION ?></span>
                <span id="journalCount">0 entries</span>
            </footer>

        </div>
    </main>

    <!-- Add/Edit Journal Modal -->
    <div class="fw-finance__modal-overlay" id="journalModal" aria-hidden="true">
        <div class="fw-finance__modal fw-finance__modal--large">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title" id="modalTitle">New Journal Entry</h3>
                <button class="fw-finance__modal-close" id="modalClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <form id="journalForm">
                    <input type="hidden" id="journalId" name="journal_id">
                    
                    <div class="fw-finance__form-row">
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">
                                Entry Date <span class="fw-finance__required">*</span>
                            </label>
                            <input 
                                type="date" 
                                class="fw-finance__input" 
                                id="entryDate" 
                                name="entry_date"
                                required
                                value="<?= date('Y-m-d') ?>"
                            >
                        </div>
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Reference</label>
                            <input 
                                type="text" 
                                class="fw-finance__input" 
                                id="reference" 
                                name="reference"
                                placeholder="Optional"
                            >
                        </div>
                    </div>

                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Memo</label>
                        <input 
                            type="text" 
                            class="fw-finance__input" 
                            id="memo" 
                            name="memo"
                            placeholder="Brief description of this entry"
                        >
                    </div>

                    <!-- Journal Lines -->
                    <div class="fw-finance__journal-lines">
                        <h4 class="fw-finance__section-title">Journal Lines</h4>
                        
                        <div class="fw-finance__lines-header">
                            <div class="fw-finance__line-col fw-finance__line-col--account">Account</div>
                            <div class="fw-finance__line-col fw-finance__line-col--desc">Description</div>
                            <div class="fw-finance__line-col fw-finance__line-col--debit">Debit</div>
                            <div class="fw-finance__line-col fw-finance__line-col--credit">Credit</div>
                            <div class="fw-finance__line-col fw-finance__line-col--action"></div>
                        </div>

                        <div id="linesContainer">
                            <!-- Lines will be added here dynamically -->
                        </div>

                        <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="addLineBtn">
                            + Add Line
                        </button>

                        <div class="fw-finance__balance-check">
                            <div class="fw-finance__balance-row">
                                <span>Total Debits:</span>
                                <span id="totalDebits" class="fw-finance__balance-value">R 0.00</span>
                            </div>
                            <div class="fw-finance__balance-row">
                                <span>Total Credits:</span>
                                <span id="totalCredits" class="fw-finance__balance-value">R 0.00</span>
                            </div>
                            <div class="fw-finance__balance-row fw-finance__balance-row--difference">
                                <span>Difference:</span>
                                <span id="difference" class="fw-finance__balance-value">R 0.00</span>
                            </div>
                        </div>
                    </div>

                    <div id="formMessage"></div>
                </form>
            </div>
            <div class="fw-finance__modal-footer">
                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="cancelBtn">
                    Cancel
                </button>
                <button type="submit" form="journalForm" class="fw-finance__btn fw-finance__btn--primary" id="saveBtn">
                    Post Journal Entry
                </button>
            </div>
        </div>
    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/journals.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>