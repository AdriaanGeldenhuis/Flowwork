<?php
// /finances/journals.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/Csrf.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/permissions.php';
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

// Check for period locks
$stmt = $DB->prepare("SELECT MAX(lock_date) as latest_lock FROM gl_period_locks WHERE company_id = ? AND is_active = 1");
$stmt->execute([$companyId]);
$lockInfo = $stmt->fetch();
$latestLock = $lockInfo['latest_lock'] ?? null;

// Earliest date a new/edited entry may carry: the backend rejects any entry
// dated on or before the latest lock (PeriodService::isLocked uses <=), so the
// first allowed day is the day after the lock. Used to constrain the date input.
$lockMinDate = $latestLock ? date('Y-m-d', strtotime($latestLock . ' +1 day')) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Journal Entries – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/finances/assets/finance.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-finance">
    <div class="fw-finance__container">

            <!-- Header -->
            <?php $finTitle = 'Journal Entries'; $finBack = '/finances/'; $finCompanyName = $companyName; $finFirstName = $firstName; include __DIR__ . '/partials/header.php'; ?>

            <!-- Main Content -->
            <main class="fw-finance__main">

                <?php if ($latestLock): ?>
                <div class="fw-finance__alert fw-finance__alert--info">
                    Period locked up to <strong><?= date('j M Y', strtotime($latestLock)) ?></strong>.
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

                    <!-- Options populated from the modules actually present
                         in the data (see journals.js populateModuleFilter). -->
                    <select class="fw-finance__filter" id="filterModule">
                        <option value="">All Modules</option>
                    </select>

                    <select class="fw-finance__filter" id="filterStatus">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="posted">Posted</option>
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

                <!-- Pagination -->
                <div class="fw-finance__pagination" id="pagination"></div>

            </main>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Journal Entries v<?= ASSET_VERSION ?></span>
                <span id="journalCount">0 entries</span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

    </div>

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
                                <?= $lockMinDate ? 'min="' . htmlspecialchars($lockMinDate) . '"' : '' ?>
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
                    Save Draft
                </button>
            </div>
        </div>
    </div>

    <!-- View Journal Modal -->
    <div class="fw-finance__modal-overlay" id="viewModal" aria-hidden="true">
        <div class="fw-finance__modal fw-finance__modal--large">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title" id="viewModalTitle">Journal Entry</h3>
                <button class="fw-finance__modal-close" id="viewModalClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <div class="fw-finance__view-header" id="viewHeader">
                    <!-- Populated by JS -->
                </div>
                <table class="fw-finance__table" id="viewLinesTable">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Description</th>
                            <th style="text-align:right">Debit</th>
                            <th style="text-align:right">Credit</th>
                        </tr>
                    </thead>
                    <tbody id="viewLinesBody">
                        <!-- Populated by JS -->
                    </tbody>
                    <tfoot id="viewLinesTotals">
                        <!-- Populated by JS -->
                    </tfoot>
                </table>
                <div id="viewMessage"></div>
            </div>
            <div class="fw-finance__modal-footer" id="viewModalFooter">
                <!-- Action buttons populated by JS based on status -->
            </div>
        </div>
    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/journals.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
