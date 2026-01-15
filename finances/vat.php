<?php
// /finances/vat.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

// Include permissions helper and restrict access to admins and bookkeepers
require_once __DIR__ . '/permissions.php';
requireRoles(['admin', 'bookkeeper']);

define('ASSET_VERSION', '2025-01-21-FIN-6');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user info
$stmt = $DB->prepare("SELECT first_name, role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';
$userRole = $user['role'] ?? 'member';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Get VAT periods
$stmt = $DB->prepare("
    SELECT * FROM gl_vat_periods 
    WHERE company_id = ?
    ORDER BY period_start DESC
    LIMIT 12
");
$stmt->execute([$companyId]);
$vatPeriods = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VAT Returns – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-finance__app-name">VAT Returns (VAT201)</div>
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
                
                <!-- Alert -->
                <div class="fw-finance__alert fw-finance__alert--info">
                    🧾 <strong>VAT201 Returns:</strong> Prepare and file your bi-monthly VAT returns for SARS.
                    <br><small>Standard VAT rate: 15% | Bi-monthly periods: Jan-Feb, Mar-Apr, May-Jun, Jul-Aug, Sep-Oct, Nov-Dec</small>
                </div>

                <!-- Tabs -->
                <div class="fw-finance__tabs">
                    <button class="fw-finance__tab fw-finance__tab--active" data-tab="periods">
                        VAT Periods
                    </button>
                    <button class="fw-finance__tab" data-tab="prepare">
                        Prepare Return
                    </button>
                    <button class="fw-finance__tab" data-tab="current">
                        Current Position
                    </button>
                </div>

                <!-- Tab Content -->
                <div class="fw-finance__tab-content">
                    
                    <!-- Periods Tab -->
                    <div class="fw-finance__tab-panel fw-finance__tab-panel--active" id="periodsPanel">
                        
                        <div class="fw-finance__toolbar">
                            <button class="fw-finance__btn fw-finance__btn--primary" id="createPeriodBtn">
                                + Create New Period
                            </button>
                        </div>

                        <div class="fw-finance__vat-periods-list">
                            <?php if (empty($vatPeriods)): ?>
                                <div class="fw-finance__empty-state">
                                    No VAT periods yet. Create your first period to get started.
                                </div>
                            <?php else: ?>
                                <?php foreach ($vatPeriods as $period): ?>
                                    <div class="fw-finance__vat-period-card" data-status="<?= $period['status'] ?>">
                                        <div class="fw-finance__vat-period-header">
                                            <div>
                                                <strong><?= date('M Y', strtotime($period['period_start'])) ?> - <?= date('M Y', strtotime($period['period_end'])) ?></strong>
                                                <span class="fw-finance__badge fw-finance__badge--<?= $period['status'] ?>">
                                                    <?= ucfirst($period['status']) ?>
                                                </span>
                                            </div>
                                            <div class="fw-finance__vat-period-amount">
                                                R <?= number_format($period['net_vat_cents'] / 100, 2) ?>
                                            </div>
                                        </div>
                                        <div class="fw-finance__vat-period-body">
                                            <div class="fw-finance__vat-period-grid">
                                                <div>
                                                    <div class="fw-finance__vat-period-label">Output VAT</div>
                                                    <div class="fw-finance__vat-period-value">R <?= number_format($period['output_vat_cents'] / 100, 2) ?></div>
                                                </div>
                                                <div>
                                                    <div class="fw-finance__vat-period-label">Input VAT</div>
                                                    <div class="fw-finance__vat-period-value">R <?= number_format($period['input_vat_cents'] / 100, 2) ?></div>
                                                </div>
                                                <div>
                                                    <div class="fw-finance__vat-period-label">Net Payable</div>
                                                    <div class="fw-finance__vat-period-value fw-finance__vat-period-value--net">
                                                        R <?= number_format($period['net_vat_cents'] / 100, 2) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="fw-finance__vat-period-actions">
                                            <?php if ($period['status'] === 'open'): ?>
                                                <button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--primary prepare-period" data-id="<?= $period['id'] ?>">
                                                    Prepare Return
                                                </button>
                                            <?php elseif ($period['status'] === 'prepared'): ?>
                                                <button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--primary view-vat201" data-id="<?= $period['id'] ?>">
                                                    View VAT201
                                                </button>
                                                <?php if ($userRole === 'admin'): ?>
                                                    <button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--secondary file-period" data-id="<?= $period['id'] ?>">
                                                        Mark as Filed
                                                    </button>
                                                <?php endif; ?>
                                            <?php elseif ($period['status'] === 'filed'): ?>
                                                <button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--secondary view-vat201" data-id="<?= $period['id'] ?>">
                                                    View Return
                                                </button>
                                                <span class="fw-finance__vat-period-meta">
                                                    Filed: <?= date('j M Y', strtotime($period['filed_at'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- Prepare Return Tab -->
                    <div class="fw-finance__tab-panel" id="preparePanel">
                        <div id="vat201Form">
                            <div class="fw-finance__empty-state">
                                Select "Prepare Return" from a VAT period to generate the VAT201 form.
                            </div>
                        </div>
                    </div>

                    <!-- Current Position Tab -->
                    <div class="fw-finance__tab-panel" id="currentPanel">
                        <div class="fw-finance__info-card">
                            <h3 class="fw-finance__info-card-title">Current VAT Position (Unfiled)</h3>
                            <div id="currentVatPosition">
                                <div class="fw-finance__loading">Loading...</div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

            <!-- Footer -->
            <footer class="fw-finance__footer">
                <span>Finance VAT v<?= ASSET_VERSION ?></span>
                <span id="statusText">Ready</span>
            </footer>

        </div>
    </main>

    <!-- Create Period Modal -->
    <div class="fw-finance__modal-overlay" id="periodModal" aria-hidden="true">
        <div class="fw-finance__modal">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title">Create VAT Period</h3>
                <button class="fw-finance__modal-close" id="modalClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <form id="periodForm">
                    <div class="fw-finance__form-row">
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Period Start <span class="fw-finance__required">*</span></label>
                            <input type="date" class="fw-finance__input" id="periodStart" required>
                        </div>
                        <div class="fw-finance__form-group">
                            <label class="fw-finance__label">Period End <span class="fw-finance__required">*</span></label>
                            <input type="date" class="fw-finance__input" id="periodEnd" required>
                        </div>
                    </div>

                    <div class="fw-finance__alert fw-finance__alert--info" style="margin-top: 1rem;">
                        💡 <strong>Tip:</strong> Standard bi-monthly periods:
                        <ul style="margin: 0.5rem 0 0 1.5rem; font-size: 13px;">
                            <li>Jan-Feb: 01 Jan - 28/29 Feb</li>
                            <li>Mar-Apr: 01 Mar - 30 Apr</li>
                            <li>May-Jun: 01 May - 30 Jun</li>
                            <li>Jul-Aug: 01 Jul - 31 Aug</li>
                            <li>Sep-Oct: 01 Sep - 31 Oct</li>
                            <li>Nov-Dec: 01 Nov - 31 Dec</li>
                        </ul>
                    </div>

                    <div id="periodMessage"></div>
                </form>
            </div>
            <div class="fw-finance__modal-footer">
                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="cancelBtn">Cancel</button>
                <button type="submit" form="periodForm" class="fw-finance__btn fw-finance__btn--primary">Create Period</button>
            </div>
        </div>
    </div>

    <!-- VAT Adjustment Modal -->
    <div class="fw-finance__modal-overlay" id="vatAdjustModal" aria-hidden="true">
        <div class="fw-finance__modal">
            <div class="fw-finance__modal-header">
                <h3 class="fw-finance__modal-title">Add VAT Adjustment</h3>
                <button class="fw-finance__modal-close" id="vatAdjustClose" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-finance__modal-body">
                <form id="vatAdjustForm">
                    <input type="hidden" id="adjustPeriodId" />
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Adjustment Type</label>
                        <select id="adjustAccount" class="fw-finance__input">
                            <option value="output">Output VAT (Sales)</option>
                            <option value="input">Input VAT (Purchases)</option>
                        </select>
                    </div>
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Amount (ZAR)</label>
                        <input type="number" step="0.01" id="adjustAmount" class="fw-finance__input" required>
                    </div>
                    <div class="fw-finance__form-group">
                        <label class="fw-finance__label">Note</label>
                        <textarea id="adjustNote" class="fw-finance__input" rows="2"></textarea>
                    </div>
                    <div id="adjustMessage"></div>
                </form>
            </div>
            <div class="fw-finance__modal-footer">
                <button type="button" class="fw-finance__btn fw-finance__btn--secondary" id="adjustCancelBtn">Cancel</button>
                <button type="submit" form="vatAdjustForm" class="fw-finance__btn fw-finance__btn--primary">Save Adjustment</button>
            </div>
        </div>
    </div>

    <script src="/finances/assets/finance.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/finances/assets/vat.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>