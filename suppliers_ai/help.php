<?php
// /suppliers_ai/help.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-22-SAI-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';

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
    <title>Help – Suppliers AI – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-suppliers-ai__app-name">Help & Documentation</div>
                    </div>
                </div>

                <div class="fw-suppliers-ai__greeting">
                    Hello, <span class="fw-suppliers-ai__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-suppliers-ai__controls">
                    <a href="/suppliers_ai/" class="fw-suppliers-ai__home-btn" title="Back to Search">
                        <svg viewBox="0 0 24 24" fill="none">
                            <line x1="19" y1="12" x2="5" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <polyline points="12 19 5 12 12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    
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
                </div>
            </header>

            <!-- Help Content -->
            <div class="fw-suppliers-ai__help-panel">
                
                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">🚀 Getting Started</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <p>Suppliers AI uses natural language processing to find the best suppliers for your needs. Simply describe what you're looking for in plain English.</p>
                        
                        <h3>Example Queries:</h3>
                        <ul class="fw-suppliers-ai__help-list">
                            <li><code>"Need 3 plumbers in Vanderbijlpark for geyser installation tomorrow under R5000"</code></li>
                            <li><code>"Find electricians in Johannesburg with valid COC certificates"</code></li>
                            <li><code>"Roofing contractors near me with good track record"</code></li>
                            <li><code>"Concrete suppliers within 50km, preferred vendors only"</code></li>
                        </ul>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">🎯 How It Works</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <ol class="fw-suppliers-ai__help-list">
                            <li><strong>Query Parsing:</strong> AI extracts keywords, location, budget, quantity, and required qualifications</li>
                            <li><strong>Multi-Source Search:</strong> Searches your CRM, historical data, and external directories (if configured)</li>
                            <li><strong>Smart Deduplication:</strong> Merges suppliers found across multiple sources</li>
                            <li><strong>Intelligent Scoring:</strong> Ranks by category match, distance, compliance status, performance history, and preferences</li>
                            <li><strong>Results:</strong> Shows top suppliers with AI-generated explanations</li>
                        </ol>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">🏷️ Understanding Scores</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <p>Each supplier gets a score from 0-100 based on:</p>
                        <ul class="fw-suppliers-ai__help-list">
                            <li><strong>Category Match (+20%):</strong> How well their services match your query</li>
                            <li><strong>CRM Presence (+15%):</strong> Existing suppliers in your database</li>
                            <li><strong>Compliance (+15%):</strong> Valid COC/Tax/BEE documents</li>
                            <li><strong>Preferred Status (+10%):</strong> Marked as preferred supplier</li>
                            <li><strong>Performance (+10%):</strong> On-time delivery, low defect rate, fast response</li>
                            <li><strong>Distance:</strong> Proximity to specified location</li>
                        </ul>
                        <p><strong>Note:</strong> Suppliers with expired compliance docs get -30% penalty (or blocked entirely if settings require it)</p>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">📋 Compliance Badges</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <div class="fw-suppliers-ai__badge-examples">
                            <div class="fw-suppliers-ai__badge-example">
                                <span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--valid">✓ Compliant</span>
                                <span>All required documents are valid</span>
                            </div>
                            <div class="fw-suppliers-ai__badge-example">
                                <span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--expiring">⚠ Expiring Soon</span>
                                <span>Documents expiring within 30 days</span>
                            </div>
                            <div class="fw-suppliers-ai__badge-example">
                                <span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--expired">✗ Expired</span>
                                <span>One or more documents expired</span>
                            </div>
                            <div class="fw-suppliers-ai__badge-example">
                                <span class="fw-suppliers-ai__badge fw-suppliers-ai__badge--missing">? No Docs</span>
                                <span>No compliance documents on file</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">⚡ Quick Actions</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <ul class="fw-suppliers-ai__help-list">
                            <li><strong>Call:</strong> Opens phone dialer (mobile) or shows number (desktop)</li>
                            <li><strong>WhatsApp:</strong> Opens WhatsApp chat in new tab</li>
                            <li><strong>Email:</strong> Opens email client with supplier's address</li>
                            <li><strong>Add to CRM:</strong> Creates new supplier account (checks for duplicates first)</li>
                            <li><strong>View in CRM:</strong> Opens existing supplier profile</li>
                            <li><strong>Add to Shortlist:</strong> Builds RFQ recipient list</li>
                        </ul>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">📨 Generating RFQs</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <ol class="fw-suppliers-ai__help-list">
                            <li>Click "Add to Shortlist" on suppliers you want to invite</li>
                            <li>Review shortlist (click "Shortlist" button top-right)</li>
                            <li>Click "Generate RFQ"</li>
                            <li>System creates RFQ/PO stub and logs all actions</li>
                            <li>Redirects to Procurement module to complete scope/terms</li>
                        </ol>
                        <p><strong>Limit:</strong> Max <?= htmlspecialchars($settings['rfq_max_recipients'] ?? 8) ?> suppliers per RFQ (configurable in Settings)</p>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">🔧 Advanced Filters</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <p>Click "Advanced Filters" to refine your search:</p>
                        <ul class="fw-suppliers-ai__help-list">
                            <li><strong>Radius (km):</strong> Distance from location (1-500km)</li>
                            <li><strong>Min Score:</strong> Filter out low-scoring results (0.0-1.0)</li>
                            <li><strong>Compliance:</strong> Valid/Expiring/Expired/Missing only</li>
                            <li><strong>Source:</strong> CRM only, External APIs, or Past Winners</li>
                        </ul>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">⚙️ Settings (Admin Only)</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <p>Admins and Bookkeepers can configure:</p>
                        <ul class="fw-suppliers-ai__help-list">
                            <li><strong>Default Radius:</strong> Starting search radius</li>
                            <li><strong>Min Score Threshold:</strong> Hide results below this score</li>
                            <li><strong>Compliance Policy:</strong> Require/block expired docs</li>
                            <li><strong>Rate Limits:</strong> Searches per hour/day</li>
                            <li><strong>RFQ Max Recipients:</strong> Bulk email limit</li>
                            <li><strong>AI Rules (JSON):</strong> Synonyms, category mappings, allow/deny lists</li>
                            <li><strong>API Keys:</strong> External connector credentials (Google Places, Bing Local)</li>
                        </ul>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">🛡️ Privacy & Security</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <ul class="fw-suppliers-ai__help-list">
                            <li>All searches are <strong>tenant-isolated</strong> (your company data only)</li>
                            <li>Search history and actions are <strong>audit-logged</strong></li>
                            <li>Rate limits prevent abuse</li>
                            <li>Supplier contact details only stored when added to CRM</li>
                            <li>External API calls respect TOS and rate limits</li>
                            <li>No data shared between companies</li>
                        </ul>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">❓ Troubleshooting</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <dl class="fw-suppliers-ai__help-faq">
                            <dt><strong>Q: No results found?</strong></dt>
                            <dd>
                                <ul>
                                    <li>Try broader keywords (e.g., "builder" instead of "brick layer")</li>
                                    <li>Increase search radius in Advanced Filters</li>
                                    <li>Lower Min Score threshold</li>
                                    <li>Check if external APIs are configured (Settings)</li>
                                </ul>
                            </dd>

                            <dt><strong>Q: Rate limit exceeded?</strong></dt>
                            <dd>Wait for the next hour or contact admin to increase limits in Settings.</dd>

                            <dt><strong>Q: Can't add supplier to CRM?</strong></dt>
                            <dd>
                                <ul>
                                    <li>Check if duplicate already exists (phone/email match)</li>
                                    <li>Verify you have Member or Admin role (Viewers are read-only)</li>
                                    <li>Check network connection</li>
                                </ul>
                            </dd>

                            <dt><strong>Q: RFQ generation failed?</strong></dt>
                            <dd>
                                <ul>
                                    <li>Ensure Procurement module is installed</li>
                                    <li>Check RFQ max recipients limit not exceeded</li>
                                    <li>Verify at least one supplier in shortlist has valid contact info</li>
                                </ul>
                            </dd>

                            <dt><strong>Q: Scores seem wrong?</strong></dt>
                            <dd>Contact admin to review AI Rules (Settings). Category mappings and synonyms may need tuning.</dd>
                        </dl>
                    </div>
                </section>

                <section class="fw-suppliers-ai__help-section">
                    <h2 class="fw-suppliers-ai__help-title">📞 Support</h2>
                    <div class="fw-suppliers-ai__help-content">
                        <p>Need help? Contact your system administrator or:</p>
                        <ul class="fw-suppliers-ai__help-list">
                            <li>Email: <a href="mailto:support@example.com">support@example.com</a></li>
                            <li>View <a href="/suppliers_ai/history.php">Search History</a> to review past queries</li>
                            <li>Check <a href="/suppliers_ai/settings.php">Settings</a> for configuration options</li>
                        </ul>
                    </div>
                </section>

            </div>

            <!-- Footer -->
            <footer class="fw-suppliers-ai__footer">
                <span>Suppliers AI v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>
    </main>

    <script src="/suppliers_ai/suppliers_ai.js?v=<?= ASSET_VERSION ?>"></script>
    <style>
        .fw-suppliers-ai__help-panel {
            display: flex;
            flex-direction: column;
            gap: var(--fw-spacing-xl);
        }
        .fw-suppliers-ai__help-section {
            background: var(--fw-panel-bg);
            border: 1px solid var(--fw-panel-border);
            border-radius: var(--fw-radius-lg);
            padding: var(--fw-spacing-xl);
            box-shadow: var(--fw-shadow-md);
            backdrop-filter: blur(12px);
        }
        .fw-suppliers-ai__help-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--fw-text-primary);
            margin: 0 0 var(--fw-spacing-lg) 0;
            padding-bottom: var(--fw-spacing-md);
            border-bottom: 2px solid var(--fw-border);
        }
        .fw-suppliers-ai__help-content {
            font-size: 15px;
            line-height: 1.7;
            color: var(--fw-text-secondary);
        }
        .fw-suppliers-ai__help-content h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--fw-text-primary);
            margin: var(--fw-spacing-lg) 0 var(--fw-spacing-sm) 0;
        }
        .fw-suppliers-ai__help-content p {
            margin: 0 0 var(--fw-spacing-md) 0;
        }
        .fw-suppliers-ai__help-list {
            margin: var(--fw-spacing-md) 0;
            padding-left: var(--fw-spacing-xl);
        }
        .fw-suppliers-ai__help-list li {
            margin-bottom: var(--fw-spacing-sm);
        }
        .fw-suppliers-ai__help-list code {
            background: var(--fw-highlight);
            padding: 2px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            color: var(--accent-ai-primary);
        }
        .fw-suppliers-ai__badge-examples {
            display: flex;
            flex-direction: column;
            gap: var(--fw-spacing-md);
            margin-top: var(--fw-spacing-md);
        }
        .fw-suppliers-ai__badge-example {
            display: flex;
            align-items: center;
            gap: var(--fw-spacing-md);
        }
        .fw-suppliers-ai__help-faq dt {
            font-weight: 600;
            color: var(--fw-text-primary);
            margin-top: var(--fw-spacing-lg);
            margin-bottom: var(--fw-spacing-sm);
        }
        .fw-suppliers-ai__help-faq dd {
            margin: 0 0 var(--fw-spacing-md) var(--fw-spacing-lg);
            color: var(--fw-text-secondary);
        }
        .fw-suppliers-ai__help-content a {
            color: var(--accent-ai-primary);
            text-decoration: none;
            font-weight: 600;
        }
        .fw-suppliers-ai__help-content a:hover {
            text-decoration: underline;
        }
    </style>
</body>
</html>