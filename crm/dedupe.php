<?php
// /crm/dedupe.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-CRM-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check if user has admin rights
$stmt = $DB->prepare("SELECT role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$userRole = $stmt->fetchColumn();

if (!in_array($userRole, ['admin', 'owner'])) {
    header('Location: /crm/');
    exit;
}

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

// Fetch duplicate candidates (not resolved)
$stmt = $DB->prepare("
    SELECT 
        mc.*,
        a1.name as left_name,
        a1.email as left_email,
        a1.phone as left_phone,
        a1.vat_no as left_vat,
        a2.name as right_name,
        a2.email as right_email,
        a2.phone as right_phone,
        a2.vat_no as right_vat
    FROM crm_merge_candidates mc
    JOIN crm_accounts a1 ON a1.id = mc.left_id
    JOIN crm_accounts a2 ON a2.id = mc.right_id
    WHERE mc.company_id = ? AND mc.resolved = 0
    ORDER BY mc.match_score DESC, mc.created_at DESC
    LIMIT 50
");
$stmt->execute([$companyId]);
$candidates = $stmt->fetchAll();

// Fetch merge history (resolved)
$stmt = $DB->prepare("
    SELECT 
        mc.*,
        a.name as winner_name
    FROM crm_merge_candidates mc
    LEFT JOIN crm_accounts a ON a.id = mc.left_id
    WHERE mc.company_id = ? AND mc.resolved = 1
    ORDER BY mc.created_at DESC
    LIMIT 20
");
$stmt->execute([$companyId]);
$mergeHistory = $stmt->fetchAll();

// Get duplicate detection settings
$enabledStmt = $DB->prepare("
    SELECT setting_value FROM company_settings 
    WHERE company_id = ? AND setting_key = 'crm_enable_duplicate_check'
");
$enabledStmt->execute([$companyId]);
$dupeCheckEnabled = $enabledStmt->fetchColumn() === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dedupe & Merge – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/crm/assets/crm.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-crm">
    <div class="fw-crm__container">
        
        <!-- Header -->
        <header class="fw-crm__header">
            <div class="fw-crm__brand">
                <div class="fw-crm__logo-tile">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="fw-crm__brand-text">
                    <div class="fw-crm__company-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="fw-crm__app-name">CRM – Dedupe & Merge</div>
                </div>
            </div>

            <div class="fw-crm__greeting">
                Hello, <span class="fw-crm__greeting-name"><?= htmlspecialchars($firstName) ?></span>
            </div>

            <div class="fw-crm__controls">
                <a href="/" class="fw-crm__home-btn" title="Home">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                
                <button class="fw-crm__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                    <svg class="fw-crm__theme-icon fw-crm__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="fw-crm__menu-wrapper">
                    <button class="fw-crm__kebab-toggle" id="kebabToggle" aria-label="Menu">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                            <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <nav class="fw-crm__kebab-menu" id="kebabMenu" aria-hidden="true">
                        <a href="/crm/" class="fw-crm__kebab-item">Back to CRM</a>
                        <a href="/crm/settings.php" class="fw-crm__kebab-item">CRM Settings</a>
                        <a href="/crm/import.php" class="fw-crm__kebab-item">Import/Export</a>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="fw-crm__main">
            
            <div class="fw-crm__page-header">
                <h1 class="fw-crm__page-title">Duplicate Detection & Merge</h1>
                <p class="fw-crm__page-subtitle">
                    Find and merge duplicate accounts to keep your CRM clean
                </p>
            </div>

            <?php if (!$dupeCheckEnabled): ?>
            <div class="fw-crm__alert fw-crm__alert--warning">
                ⚠️ Duplicate detection is currently disabled. 
                <a href="/crm/settings.php" style="color:inherit; text-decoration:underline;">Enable it in Settings</a>
            </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="fw-crm__view-tabs">
                <button class="fw-crm__view-tab fw-crm__view-tab--active" data-tab="candidates">
                    Candidates (<?= count($candidates) ?>)
                </button>
                <button class="fw-crm__view-tab" data-tab="history">
                    Merge History (<?= count($mergeHistory) ?>)
                </button>
                <button class="fw-crm__view-tab" data-tab="scan">
                    Scan for Duplicates
                </button>
            </div>

            <!-- Tab Content -->
            <div class="fw-crm__view-content">
                
                <!-- Candidates Tab -->
                <div class="fw-crm__tab-panel fw-crm__tab-panel--active" data-panel="candidates">
                    <?php if (count($candidates) > 0): ?>
                        <div class="fw-crm__dedupe-list">
                            <?php foreach ($candidates as $candidate): ?>
                            <div class="fw-crm__dedupe-card" data-candidate-id="<?= $candidate['id'] ?>">
                                <div class="fw-crm__dedupe-header">
                                    <div class="fw-crm__dedupe-score">
                                        <div class="fw-crm__dedupe-score-value"><?= round($candidate['match_score'] * 100) ?>%</div>
                                        <div class="fw-crm__dedupe-score-label">Match</div>
                                    </div>
                                    <div class="fw-crm__dedupe-reason">
                                        <?php
                                        $reasons = json_decode($candidate['reason'], true);
                                        if ($reasons) {
                                            echo implode(' • ', $reasons);
                                        } else {
                                            echo 'Potential duplicate';
                                        }
                                        ?>
                                    </div>
                                </div>

                                <div class="fw-crm__dedupe-comparison">
                                    <!-- Left Account -->
                                    <div class="fw-crm__dedupe-account">
                                        <div class="fw-crm__dedupe-account-header">
                                            <h3><?= htmlspecialchars($candidate['left_name']) ?></h3>
                                            <a href="/crm/account_view.php?id=<?= $candidate['left_id'] ?>" target="_blank" class="fw-crm__btn fw-crm__btn--small fw-crm__btn--secondary">
                                                View →
                                            </a>
                                        </div>
                                        <dl class="fw-crm__dedupe-details">
                                            <?php if ($candidate['left_email']): ?>
                                            <div class="fw-crm__dedupe-detail">
                                                <dt>Email:</dt>
                                                <dd><?= htmlspecialchars($candidate['left_email']) ?></dd>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($candidate['left_phone']): ?>
                                            <div class="fw-crm__dedupe-detail">
                                                <dt>Phone:</dt>
                                                <dd><?= htmlspecialchars($candidate['left_phone']) ?></dd>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($candidate['left_vat']): ?>
                                            <div class="fw-crm__dedupe-detail">
                                                <dt>VAT:</dt>
                                                <dd><?= htmlspecialchars($candidate['left_vat']) ?></dd>
                                            </div>
                                            <?php endif; ?>
                                        </dl>
                                    </div>

                                    <div class="fw-crm__dedupe-vs">VS</div>

                                    <!-- Right Account -->
                                    <div class="fw-crm__dedupe-account">
                                        <div class="fw-crm__dedupe-account-header">
                                            <h3><?= htmlspecialchars($candidate['right_name']) ?></h3>
                                            <a href="/crm/account_view.php?id=<?= $candidate['right_id'] ?>" target="_blank" class="fw-crm__btn fw-crm__btn--small fw-crm__btn--secondary">
                                                View →
                                            </a>
                                        </div>
                                        <dl class="fw-crm__dedupe-details">
                                            <?php if ($candidate['right_email']): ?>
                                            <div class="fw-crm__dedupe-detail">
                                                <dt>Email:</dt>
                                                <dd><?= htmlspecialchars($candidate['right_email']) ?></dd>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($candidate['right_phone']): ?>
                                            <div class="fw-crm__dedupe-detail">
                                                <dt>Phone:</dt>
                                                <dd><?= htmlspecialchars($candidate['right_phone']) ?></dd>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($candidate['right_vat']): ?>
                                            <div class="fw-crm__dedupe-detail">
                                                <dt>VAT:</dt>
                                                <dd><?= htmlspecialchars($candidate['right_vat']) ?></dd>
                                            </div>
                                            <?php endif; ?>
                                        </dl>
                                    </div>
                                </div>

                                <div class="fw-crm__dedupe-actions">
                                    <button class="fw-crm__btn fw-crm__btn--primary" onclick="openMergeModal(<?= $candidate['left_id'] ?>, <?= $candidate['right_id'] ?>, <?= $candidate['id'] ?>)">
                                        Merge These Accounts
                                    </button>
                                    <button class="fw-crm__btn fw-crm__btn--secondary" onclick="dismissCandidate(<?= $candidate['id'] ?>)">
                                        Not a Duplicate
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">
                            No duplicate candidates found.<br>
                            <small>Run a scan to check for duplicates</small>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- History Tab -->
                <div class="fw-crm__tab-panel" data-panel="history">
                    <?php if (count($mergeHistory) > 0): ?>
                        <div class="fw-crm__history-list">
                            <?php foreach ($mergeHistory as $history): ?>
                            <div class="fw-crm__history-card">
                                <div class="fw-crm__history-header">
                                    <div>
                                        <strong>Merged into: <?= htmlspecialchars($history['winner_name']) ?></strong><br>
                                        <small><?= date('d M Y H:i', strtotime($history['created_at'])) ?></small>
                                    </div>
                                    <span class="fw-crm__badge fw-crm__badge--completed">Merged</span>
                                </div>
                                <?php if ($history['reason']): ?>
                                <div class="fw-crm__history-meta">
                                    Reason: <?= htmlspecialchars(implode(', ', json_decode($history['reason'], true) ?: [])) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="fw-crm__empty-state">
                            No merge history yet
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Scan Tab -->
                <div class="fw-crm__tab-panel" data-panel="scan">
                    <div class="fw-crm__info-card">
                        <h3 class="fw-crm__info-card-title">Scan for Duplicate Accounts</h3>
                        <p>This will search all accounts for potential duplicates based on:</p>
                        <ul style="margin:var(--fw-spacing-md) 0; padding-left:var(--fw-spacing-lg);">
                            <li>Exact email match</li>
                            <li>Exact phone match</li>
                            <li>Exact VAT number match</li>
                            <li>Similar names (configurable threshold)</li>
                        </ul>

                        <div class="fw-crm__form-group">
                            <label class="fw-crm__label">Account Type</label>
                            <select id="scanType" class="fw-crm__input">
                                <option value="all">All Accounts</option>
                                <option value="supplier">Suppliers Only</option>
                                <option value="customer">Customers Only</option>
                            </select>
                        </div>

                        <div class="fw-crm__form-group">
                            <label class="fw-crm__checkbox-wrapper">
                                <input type="checkbox" id="clearExisting" class="fw-crm__checkbox" checked>
                                <span>Clear existing candidates before scan</span>
                            </label>
                        </div>

                        <div id="scanProgress" style="display:none; margin:var(--fw-spacing-lg) 0;">
                            <div class="fw-crm__progress-bar">
                                <div class="fw-crm__progress-fill" id="scanProgressFill"></div>
                            </div>
                            <div class="fw-crm__progress-text" id="scanProgressText">Starting scan...</div>
                        </div>

                        <div id="scanResults" style="display:none; margin:var(--fw-spacing-lg) 0;"></div>

                        <div class="fw-crm__form-actions">
                            <button type="button" class="fw-crm__btn fw-crm__btn--primary" id="startScanBtn" onclick="startScan()">
                                🔍 Start Scan
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        </main>

        <!-- Footer -->
        <footer class="fw-crm__footer">
            <span>CRM v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>

    </div>

    <!-- Merge Modal -->
    <div class="fw-crm__modal-overlay" id="mergeModal">
        <div class="fw-crm__modal fw-crm__modal--large">
            <div class="fw-crm__modal-header">
                <h2 class="fw-crm__modal-title">Merge Accounts</h2>
                <button class="fw-crm__modal-close" onclick="CRMModal.close('mergeModal')">
                    <svg viewBox="0 0 24 24" fill="none">
                        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="fw-crm__modal-body" id="mergeModalBody">
                <div class="fw-crm__loading">Loading accounts...</div>
            </div>
            <div class="fw-crm__modal-footer">
                <button type="button" class="fw-crm__btn fw-crm__btn--secondary" onclick="CRMModal.close('mergeModal')">Cancel</button>
                <button type="button" class="fw-crm__btn fw-crm__btn--primary" id="executeMergeBtn" style="display:none;">
                    Merge Accounts
                </button>
            </div>
        </div>
    </div>

    <script src="/crm/assets/crm.js?v=<?= ASSET_VERSION ?>"></script>
    <script src="/crm/assets/dedupe.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>