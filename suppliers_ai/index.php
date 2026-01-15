<?php
// /suppliers_ai/index.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-22-SAI-3');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch user info
$stmt = $DB->prepare("SELECT first_name, role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';
$userRole = $user['role'] ?? 'member';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch AI settings
$stmt = $DB->prepare("SELECT * FROM ai_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$aiSettings = $stmt->fetch();
if (!$aiSettings) {
    // Create default settings with API key
    $defaultApiKeys = json_encode([
        'openai_api_key' => 'OPENAI_KEY_REMOVED',
        'openai_model' => 'gpt-4o-mini',
        'openai_max_tokens' => 500,
        'openai_enabled' => 1
    ]);
    
    $stmt = $DB->prepare("
        INSERT INTO ai_settings (company_id, default_radius_km, min_score_threshold, require_compliance, block_expired_compliance, rfq_max_recipients, daily_search_cap, per_hour_cap, api_keys_json, created_at)
        VALUES (?, 25.00, 0.55, 1, 1, 8, 100, 20, ?, NOW())
    ");
    $stmt->execute([$companyId, $defaultApiKeys]);
    $aiSettings = [
        'default_radius_km' => 25.00,
        'min_score_threshold' => 0.55,
        'require_compliance' => 1,
        'block_expired_compliance' => 1,
        'rfq_max_recipients' => 8,
        'per_hour_cap' => 20
    ];
}

// Get recent search count
$stmt = $DB->prepare("
    SELECT COUNT(*) as cnt 
    FROM ai_queries 
    WHERE company_id = ? 
    AND user_id = ? 
    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute([$companyId, $userId]);
$recentSearches = $stmt->fetch()['cnt'] ?? 0;
$searchesRemaining = max(0, ($aiSettings['per_hour_cap'] ?? 20) - $recentSearches);

// Get stats
$stmt = $DB->prepare("
    SELECT 
        COUNT(DISTINCT query_id) as total_searches,
        COUNT(*) as total_results,
        COUNT(DISTINCT CASE WHEN account_id IS NOT NULL THEN account_id END) as linked_to_crm
    FROM ai_candidates 
    WHERE company_id = ? 
    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers AI – <?= htmlspecialchars($companyName) ?></title>
    <link rel="stylesheet" href="/suppliers_ai/style.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-suppliers-ai">
        <div class="fw-suppliers-ai__container">
            
            <!-- Header -->
            <header class="fw-suppliers-ai__header">
                <div class="fw-suppliers-ai__brand">
                    <div class="fw-suppliers-ai__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <line x1="12" y1="22.08" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="fw-suppliers-ai__brand-text">
                        <div class="fw-suppliers-ai__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-suppliers-ai__app-name">Suppliers AI</div>
                    </div>
                </div>

                <div class="fw-suppliers-ai__greeting">
                    Hello, <span class="fw-suppliers-ai__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-suppliers-ai__controls">
                    <a href="/" class="fw-suppliers-ai__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
                    <button class="fw-suppliers-ai__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-suppliers-ai__theme-icon fw-suppliers-ai__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                        <svg class="fw-suppliers-ai__theme-icon fw-suppliers-ai__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>

                    <div class="fw-suppliers-ai__menu-wrapper">
                        <button class="fw-suppliers-ai__kebab-toggle" id="kebabToggle" aria-label="Menu">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                            </svg>
                        </button>
                        <nav class="fw-suppliers-ai__kebab-menu" id="kebabMenu" aria-hidden="true">
                            <a href="/suppliers_ai/history.php" class="fw-suppliers-ai__kebab-item">Search History</a>
                            <?php if (in_array($userRole, ['admin', 'bookkeeper'])): ?>
                            <a href="/suppliers_ai/settings.php" class="fw-suppliers-ai__kebab-item">Settings</a>
                            <?php endif; ?>
                            <a href="/suppliers_ai/help.php" class="fw-suppliers-ai__kebab-item">Help</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Stats Bar -->
            <div class="fw-suppliers-ai__stats">
                <div class="fw-suppliers-ai__stat-card">
                    <div class="fw-suppliers-ai__stat-value"><?= number_format($stats['total_searches'] ?? 0) ?></div>
                    <div class="fw-suppliers-ai__stat-label">Searches (30d)</div>
                </div>
                <div class="fw-suppliers-ai__stat-card">
                    <div class="fw-suppliers-ai__stat-value"><?= number_format($stats['total_results'] ?? 0) ?></div>
                    <div class="fw-suppliers-ai__stat-label">Suppliers Found</div>
                </div>
                <div class="fw-suppliers-ai__stat-card">
                    <div class="fw-suppliers-ai__stat-value"><?= number_format($stats['linked_to_crm'] ?? 0) ?></div>
                    <div class="fw-suppliers-ai__stat-label">Added to CRM</div>
                </div>
                <div class="fw-suppliers-ai__stat-card">
                    <div class="fw-suppliers-ai__stat-value"><?= $searchesRemaining ?></div>
                    <div class="fw-suppliers-ai__stat-label">Searches Left (1h)</div>
                </div>
            </div>

            <!-- Search Panel -->
            <div class="fw-suppliers-ai__search-panel">
                <div class="fw-suppliers-ai__search-header">
                    <h2 class="fw-suppliers-ai__search-title">🤖 Find Suppliers with AI</h2>
                    <p class="fw-suppliers-ai__search-subtitle">
                        Natural language search: "Need 3 plumbers in Vanderbijlpark for geysers tomorrow under R5k"
                    </p>
                </div>

                <div class="fw-suppliers-ai__search-box">
                    <textarea 
                        id="nlSearchInput" 
                        class="fw-suppliers-ai__nl-input" 
                        placeholder="Describe what you need... (e.g., 'Need 2 electricians in Johannesburg with COC, available next week')"
                        rows="3"
                    ></textarea>
                    <button id="searchBtn" class="fw-suppliers-ai__search-btn">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
                            <path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Search
                    </button>
                </div>

                <!-- Advanced Filters -->
                <details class="fw-suppliers-ai__filters">
                    <summary class="fw-suppliers-ai__filters-toggle">Advanced Filters</summary>
                    <div class="fw-suppliers-ai__filters-grid">
                        <div class="fw-suppliers-ai__filter-group">
                            <label class="fw-suppliers-ai__label">Radius (km)</label>
                            <input type="number" id="filterRadius" class="fw-suppliers-ai__input" value="<?= $aiSettings['default_radius_km'] ?>" min="1" max="500">
                        </div>
                        <div class="fw-suppliers-ai__filter-group">
                            <label class="fw-suppliers-ai__label">Min Score</label>
                            <input type="number" id="filterMinScore" class="fw-suppliers-ai__input" value="<?= $aiSettings['min_score_threshold'] ?>" min="0" max="1" step="0.05">
                        </div>
                        <div class="fw-suppliers-ai__filter-group">
                            <label class="fw-suppliers-ai__label">Compliance</label>
                            <select id="filterCompliance" class="fw-suppliers-ai__input">
                                <option value="">Any</option>
                                <option value="valid" <?= $aiSettings['require_compliance'] ? 'selected' : '' ?>>Valid Only</option>
                                <option value="expiring">Expiring Soon</option>
                                <option value="expired">Expired</option>
                                <option value="missing">Missing</option>
                            </select>
                        </div>
                        <div class="fw-suppliers-ai__filter-group">
                            <label class="fw-suppliers-ai__label">Source</label>
                            <select id="filterSource" class="fw-suppliers-ai__input">
                                <option value="">All Sources</option>
                                <option value="crm">CRM Only</option>
                                <option value="external">External APIs</option>
                                <option value="history">Past Winners</option>
                            </select>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Results Area -->
            <div class="fw-suppliers-ai__results" id="resultsArea" style="display:none;">
                <div class="fw-suppliers-ai__results-header">
                    <h3 class="fw-suppliers-ai__results-title">
                        <span id="resultsCount">0</span> Suppliers Found
                    </h3>
                    <div class="fw-suppliers-ai__results-actions">
                        <button id="clearResultsBtn" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary">Clear</button>
                        <button id="shortlistBtn" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--primary" disabled>
                            Shortlist (<span id="shortlistCount">0</span>)
                        </button>
                    </div>
                </div>

                <div class="fw-suppliers-ai__results-list" id="resultsList">
                    <!-- Dynamic cards inserted here -->
                </div>
            </div>

            <!-- Shortlist Panel -->
            <aside class="fw-suppliers-ai__shortlist" id="shortlistPanel" aria-hidden="true">
                <div class="fw-suppliers-ai__shortlist-header">
                    <h3 class="fw-suppliers-ai__shortlist-title">Shortlist</h3>
                    <button class="fw-suppliers-ai__shortlist-close" id="shortlistCloseBtn">
                        <svg viewBox="0 0 24 24" fill="none">
                            <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2"/>
                            <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </button>
                </div>
                <div class="fw-suppliers-ai__shortlist-body" id="shortlistBody">
                    <p class="fw-suppliers-ai__empty-state">No suppliers shortlisted yet</p>
                </div>
                <div class="fw-suppliers-ai__shortlist-footer">
                    <button id="clearShortlistBtn" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary">Clear All</button>
                    <button id="generateRfqBtn" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--primary" disabled>Generate RFQ</button>
                </div>
            </aside>

            <!-- Email Modal -->
            <div class="fw-suppliers-ai__modal-overlay" id="emailModal" aria-hidden="true">
                <div class="fw-suppliers-ai__modal">
                    <div class="fw-suppliers-ai__modal-header">
                        <h3 class="fw-suppliers-ai__modal-title">📧 Send RFQ Email</h3>
                        <button class="fw-suppliers-ai__modal-close" onclick="SupplierAI.closeEmailModal()">
                            <svg viewBox="0 0 24 24" fill="none">
                                <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2"/>
                                <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                    <div class="fw-suppliers-ai__modal-body">
                        <div class="fw-suppliers-ai__form-group">
                            <label class="fw-suppliers-ai__label">To:</label>
                            <input type="email" id="emailTo" class="fw-suppliers-ai__input" readonly>
                        </div>
                        <div class="fw-suppliers-ai__form-group">
                            <label class="fw-suppliers-ai__label">Subject:</label>
                            <input type="text" id="emailSubject" class="fw-suppliers-ai__input">
                        </div>
                        <div class="fw-suppliers-ai__form-group">
                            <label class="fw-suppliers-ai__label">Message:</label>
                            <textarea id="emailBody" class="fw-suppliers-ai__textarea" rows="12"></textarea>
                        </div>
                        <div class="fw-suppliers-ai__form-group">
                            <label class="fw-suppliers-ai__label">Additional Scope (optional):</label>
                            <textarea id="emailScope" class="fw-suppliers-ai__textarea" rows="3" placeholder="e.g., Must include materials, 2-year warranty required"></textarea>
                        </div>
                    </div>
                    <div class="fw-suppliers-ai__modal-footer">
                        <button class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary" onclick="SupplierAI.closeEmailModal()">Cancel</button>
                        <button class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary" id="regenerateEmailBtn">🔄 Regenerate</button>
                        <button class="fw-suppliers-ai__btn fw-suppliers-ai__btn--primary" id="sendEmailBtn">Send Email</button>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="fw-suppliers-ai__footer">
                <span>Suppliers AI v<?= ASSET_VERSION ?> 🤖 Powered by OpenAI</span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>
    </main>

    <script>
    // Global state for email modal
    window.currentEmailSupplier = null;
    window.currentQueryText = '';
    </script>
    <script src="/suppliers_ai/suppliers_ai.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>