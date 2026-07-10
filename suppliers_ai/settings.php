<?php
// /suppliers_ai/settings.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2026-07-10-suppliers-crm-parity');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Check admin role
$stmt = $DB->prepare("SELECT first_name, role FROM users WHERE id = ? AND company_id = ?");
$stmt->execute([$userId, $companyId]);
$user = $stmt->fetch();
$userRole = $user['role'] ?? 'member';

if (!in_array($userRole, ['admin', 'bookkeeper'])) {
    header('Location: /suppliers_ai/');
    exit;
}

$firstName = $user['first_name'] ?? 'User';

// Fetch company name
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

// Fetch settings
$stmt = $DB->prepare("SELECT * FROM ai_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();

if (!$settings) {
    // Create defaults with API key
    $defaultApiKeys = json_encode([
        'openai_api_key' => 'OPENAI_KEY_REMOVED',
        'openai_model' => 'gpt-4o-mini',
        'openai_max_tokens' => 500,
        'openai_enabled' => 1,
        'google_places_key' => '',
        'bing_local_key' => ''
    ]);
    
    $stmt = $DB->prepare("
        INSERT INTO ai_settings (company_id, default_radius_km, min_score_threshold, require_compliance, 
                                  block_expired_compliance, rfq_max_recipients, daily_search_cap, per_hour_cap, 
                                  api_keys_json, created_at)
        VALUES (?, 25.00, 0.55, 1, 1, 8, 100, 20, ?, NOW())
    ");
    $stmt->execute([$companyId, $defaultApiKeys]);
    
    $settings = [
        'default_radius_km' => 25.00,
        'min_score_threshold' => 0.55,
        'require_compliance' => 1,
        'block_expired_compliance' => 1,
        'rfq_max_recipients' => 8,
        'daily_search_cap' => 100,
        'per_hour_cap' => 20,
        'rules_json' => null,
        'api_keys_json' => $defaultApiKeys
    ];
}

// Parse API keys
$apiKeys = !empty($settings['api_keys_json']) ? json_decode($settings['api_keys_json'], true) : [];

// Handle form submission
$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_settings') {
        try {
            $stmt = $DB->prepare("
                UPDATE ai_settings SET
                    default_radius_km = ?,
                    min_score_threshold = ?,
                    require_compliance = ?,
                    block_expired_compliance = ?,
                    rfq_max_recipients = ?,
                    daily_search_cap = ?,
                    per_hour_cap = ?,
                    updated_at = NOW()
                WHERE company_id = ?
            ");
            $stmt->execute([
                $_POST['default_radius_km'] ?? 25,
                $_POST['min_score_threshold'] ?? 0.55,
                isset($_POST['require_compliance']) ? 1 : 0,
                isset($_POST['block_expired_compliance']) ? 1 : 0,
                $_POST['rfq_max_recipients'] ?? 8,
                $_POST['daily_search_cap'] ?? 100,
                $_POST['per_hour_cap'] ?? 20,
                $companyId
            ]);

            // Audit log
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'ai_settings_updated', ?, ?, NOW())
            ");
            $stmt->execute([
                $companyId,
                $userId,
                json_encode($_POST),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $message = 'Settings saved successfully';
            $messageType = 'success';

            // Refresh settings
            $stmt = $DB->prepare("SELECT * FROM ai_settings WHERE company_id = ?");
            $stmt->execute([$companyId]);
            $settings = $stmt->fetch();

        } catch (Exception $e) {
            $message = 'Error saving settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($_POST['action'] === 'save_rules') {
        try {
            $rulesJson = $_POST['rules_json'] ?? '{}';
            json_decode($rulesJson);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON format');
            }

            $stmt = $DB->prepare("
                UPDATE ai_settings SET rules_json = ?, updated_at = NOW()
                WHERE company_id = ?
            ");
            $stmt->execute([$rulesJson, $companyId]);

            $message = 'Rules saved successfully';
            $messageType = 'success';

            $stmt = $DB->prepare("SELECT * FROM ai_settings WHERE company_id = ?");
            $stmt->execute([$companyId]);
            $settings = $stmt->fetch();

        } catch (Exception $e) {
            $message = 'Error saving rules: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($_POST['action'] === 'save_api_keys') {
        try {
            $newApiKeys = [
                'openai_api_key' => trim($_POST['openai_api_key'] ?? ''),
                'openai_model' => $_POST['openai_model'] ?? 'gpt-4o-mini',
                'openai_max_tokens' => (int)($_POST['openai_max_tokens'] ?? 500),
                'openai_enabled' => isset($_POST['openai_enabled']) ? 1 : 0,
                'google_places_key' => trim($_POST['google_places_key'] ?? ''),
                'bing_local_key' => trim($_POST['bing_local_key'] ?? '')
            ];

            $stmt = $DB->prepare("
                UPDATE ai_settings SET api_keys_json = ?, updated_at = NOW()
                WHERE company_id = ?
            ");
            $stmt->execute([json_encode($newApiKeys), $companyId]);

            // Audit log
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
                VALUES (?, ?, 'ai_api_keys_updated', 'OpenAI configuration updated', ?, NOW())
            ");
            $stmt->execute([$companyId, $userId, $_SERVER['REMOTE_ADDR'] ?? null]);

            $message = 'API keys saved successfully';
            $messageType = 'success';

            $stmt = $DB->prepare("SELECT * FROM ai_settings WHERE company_id = ?");
            $stmt->execute([$companyId]);
            $settings = $stmt->fetch();
            $apiKeys = json_decode($settings['api_keys_json'], true);

        } catch (Exception $e) {
            $message = 'Error saving API keys: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Default rules template
$defaultRules = [
    'defaults' => [
        'radius_km' => 25,
        'min_score' => 0.55,
        'language' => 'en',
        'country' => 'ZA'
    ],
    'synonyms' => [
        'geyser' => ['water heater', 'plumbing'],
        'roof sheeting' => ['roofing', 'sheet metal'],
        'contractor' => ['builder', 'subcontractor']
    ],
    'categories' => [
        'plumber' => ['plumbing', 'blocked drains', 'geyser'],
        'electrician' => ['electrical', 'wiring', 'COC', 'certificate'],
        'contractor' => ['construction', 'building', 'renovation']
    ],
    'allow_list' => [
        'phones' => [],
        'domains' => [],
        'ids' => []
    ],
    'deny_list' => [
        'phones' => [],
        'domains' => [],
        'ids' => []
    ],
    'policy' => [
        'require_compliance' => true,
        'block_expired' => true,
        'rfq_max_recipients' => 8
    ]
];

$rulesDisplay = $settings['rules_json'] ?? json_encode($defaultRules, JSON_PRETTY_PRINT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Settings – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/suppliers_ai/style.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-suppliers-ai">
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
                        <div class="fw-suppliers-ai__app-name">Suppliers AI – Settings</div>
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

                    <div class="fw-suppliers-ai__menu-wrapper">
                        <button class="fw-suppliers-ai__kebab-toggle" id="kebabToggle" aria-label="Menu">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                            </svg>
                        </button>
                        <nav class="fw-suppliers-ai__kebab-menu" id="kebabMenu" aria-hidden="true">
                            <a href="/suppliers_ai/" class="fw-suppliers-ai__kebab-item">New Search</a>
                            <a href="/suppliers_ai/history.php" class="fw-suppliers-ai__kebab-item">History</a>
                            <a href="/suppliers_ai/help.php" class="fw-suppliers-ai__kebab-item">Help</a>
                            <a href="/suppliers_ai/settings.php" class="fw-suppliers-ai__kebab-item">Settings</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="fw-suppliers-ai__main">
            <div class="fw-suppliers-ai__content">

            <div class="fw-suppliers-ai__page-header">
                <h1 class="fw-suppliers-ai__page-title">Suppliers AI Settings</h1>
                <p class="fw-suppliers-ai__page-subtitle">
                    Configure AI search, scoring, and integration options
                </p>
            </div>

            <!-- View Tabs -->
            <div class="fw-suppliers-ai__view-tabs">
                <a href="/suppliers_ai/" class="fw-suppliers-ai__view-tab">Search</a>
                <a href="/suppliers_ai/history.php" class="fw-suppliers-ai__view-tab">History</a>
                <a href="/suppliers_ai/help.php" class="fw-suppliers-ai__view-tab">Help</a>
                <a href="/suppliers_ai/settings.php" class="fw-suppliers-ai__view-tab fw-suppliers-ai__view-tab--active">Settings</a>
            </div>

            <?php if ($message): ?>
            <div class="fw-suppliers-ai__alert fw-suppliers-ai__alert--<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <!-- Settings Forms -->
            <div class="fw-suppliers-ai__settings-panel">
                
                <!-- OpenAI Configuration -->
                <?php if ($userRole === 'admin'): ?>
                <form method="POST" class="fw-suppliers-ai__settings-form">
                    <input type="hidden" name="action" value="save_api_keys">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                    
                    <div class="fw-suppliers-ai__section">
                        <h2 class="fw-suppliers-ai__section-title">🤖 OpenAI Configuration</h2>
                        <p class="fw-suppliers-ai__help-text fw-suppliers-ai__help-text--lead">
                            Enable AI-powered query parsing, intelligent matching, and RFQ generation
                        </p>

                        <div class="fw-suppliers-ai__form-group">
                            <label class="fw-suppliers-ai__checkbox-wrapper">
                                <input type="checkbox" name="openai_enabled" class="fw-suppliers-ai__checkbox" 
                                       <?= !empty($apiKeys['openai_enabled']) ? 'checked' : '' ?>>
                                <span>Enable OpenAI Processing</span>
                            </label>
                            <small class="fw-suppliers-ai__help-text">Disable to use basic keyword matching (free, faster)</small>
                        </div>

                        <div class="fw-suppliers-ai__form-grid">
                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">OpenAI API Key <span class="fw-suppliers-ai__required">*</span></label>
                                <input type="password" name="openai_api_key" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($apiKeys['openai_api_key'] ?? '') ?>" 
                                       placeholder="sk-proj-xxxxx...">
                                <small class="fw-suppliers-ai__help-text">
                                    Get your key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>
                                </small>
                            </div>

                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Model</label>
                                <select name="openai_model" class="fw-suppliers-ai__input">
                                    <option value="gpt-4o-mini" <?= ($apiKeys['openai_model'] ?? 'gpt-4o-mini') === 'gpt-4o-mini' ? 'selected' : '' ?>>GPT-4o Mini (Fast, Cheap)</option>
                                    <option value="gpt-4o" <?= ($apiKeys['openai_model'] ?? '') === 'gpt-4o' ? 'selected' : '' ?>>GPT-4o (Smartest)</option>
                                    <option value="gpt-3.5-turbo" <?= ($apiKeys['openai_model'] ?? '') === 'gpt-3.5-turbo' ? 'selected' : '' ?>>GPT-3.5 Turbo</option>
                                </select>
                            </div>

                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Max Tokens per Request</label>
                                <input type="number" name="openai_max_tokens" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($apiKeys['openai_max_tokens'] ?? 500) ?>" 
                                       min="100" max="4000">
                                <small class="fw-suppliers-ai__help-text">Recommended: 500</small>
                            </div>
                        </div>

                        <hr class="fw-suppliers-ai__divider">

                        <h3 class="fw-suppliers-ai__subsection-title">Optional: External Directory APIs</h3>

                        <div class="fw-suppliers-ai__form-grid">
                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Google Places API Key</label>
                                <input type="password" name="google_places_key" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($apiKeys['google_places_key'] ?? '') ?>" 
                                       placeholder="AIzaSy...">
                            </div>

                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Bing Local Search Key</label>
                                <input type="password" name="bing_local_key" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($apiKeys['bing_local_key'] ?? '') ?>" 
                                       placeholder="f8a3b2...">
                            </div>
                        </div>

                        <div class="fw-suppliers-ai__form-actions">
                            <button type="submit" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--primary">
                                💾 Save API Configuration
                            </button>
                            <button type="button" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary" 
                                    onclick="testOpenAI()">
                                🧪 Test OpenAI Connection
                            </button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>

                <!-- General Settings -->
                <form method="POST" class="fw-suppliers-ai__settings-form">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                    
                    <div class="fw-suppliers-ai__section">
                        <h2 class="fw-suppliers-ai__section-title">General Settings</h2>
                        
                        <div class="fw-suppliers-ai__form-grid">
                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Default Search Radius (km)</label>
                                <input type="number" name="default_radius_km" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($settings['default_radius_km']) ?>" 
                                       min="1" max="500" step="0.5" required>
                            </div>

                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Minimum Score Threshold</label>
                                <input type="number" name="min_score_threshold" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($settings['min_score_threshold']) ?>" 
                                       min="0" max="1" step="0.05" required>
                            </div>

                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">RFQ Max Recipients</label>
                                <input type="number" name="rfq_max_recipients" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($settings['rfq_max_recipients']) ?>" 
                                       min="1" max="50" required>
                            </div>

                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Daily Search Cap</label>
                                <input type="number" name="daily_search_cap" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($settings['daily_search_cap']) ?>" 
                                       min="10" max="1000" required>
                            </div>

                            <div class="fw-suppliers-ai__form-group">
                                <label class="fw-suppliers-ai__label">Per-Hour Cap</label>
                                <input type="number" name="per_hour_cap" class="fw-suppliers-ai__input" 
                                       value="<?= htmlspecialchars($settings['per_hour_cap']) ?>" 
                                       min="5" max="100" required>
                            </div>
                        </div>

                        <div class="fw-suppliers-ai__form-group">
                            <label class="fw-suppliers-ai__checkbox-wrapper">
                                <input type="checkbox" name="require_compliance" class="fw-suppliers-ai__checkbox" 
                                       <?= $settings['require_compliance'] ? 'checked' : '' ?>>
                                <span>Require Valid Compliance Documents</span>
                            </label>
                        </div>

                        <div class="fw-suppliers-ai__form-group">
                            <label class="fw-suppliers-ai__checkbox-wrapper">
                                <input type="checkbox" name="block_expired_compliance" class="fw-suppliers-ai__checkbox" 
                                       <?= $settings['block_expired_compliance'] ? 'checked' : '' ?>>
                                <span>Block RFQs to Suppliers with Expired Docs</span>
                            </label>
                        </div>

                        <div class="fw-suppliers-ai__form-actions">
                            <button type="submit" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--primary">
                                Save General Settings
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Rules Configuration -->
                <form method="POST" class="fw-suppliers-ai__settings-form">
                    <input type="hidden" name="action" value="save_rules">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                    
                    <div class="fw-suppliers-ai__section">
                        <h2 class="fw-suppliers-ai__section-title">AI Rules (JSON)</h2>
                        <p class="fw-suppliers-ai__help-text fw-suppliers-ai__help-text--lead">
                            Configure synonyms, category mappings, allow/deny lists
                        </p>

                        <div class="fw-suppliers-ai__form-group">
                            <textarea name="rules_json" class="fw-suppliers-ai__textarea fw-suppliers-ai__textarea--code" rows="20"><?= htmlspecialchars($rulesDisplay) ?></textarea>
                        </div>

                        <div class="fw-suppliers-ai__form-actions">
                            <button type="submit" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--primary">
                                Save Rules
                            </button>
                            <button type="button" class="fw-suppliers-ai__btn fw-suppliers-ai__btn--secondary" 
                                    onclick="document.querySelector('[name=rules_json]').value = JSON.stringify(<?= json_encode($defaultRules) ?>, null, 2)">
                                Reset to Defaults
                            </button>
                        </div>
                    </div>
                </form>

            </div>

            </div>
            </main>

            <!-- Footer -->
            <footer class="fw-suppliers-ai__footer">
                <span>Suppliers AI v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Dark</span>
            </footer>

    </div>

    <script src="/suppliers_ai/suppliers_ai.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    async function testOpenAI() {
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = '⏳ Testing...';
        
        try {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const response = await fetch('/suppliers_ai/ajax/test_openai.php', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '' }
            });
            const data = await response.json();
            
            if (data.ok) {
                alert('✅ OpenAI Connection Successful!\n\nModel: ' + data.model + '\nLatency: ' + data.latency_ms + 'ms\nResponse: ' + data.response);
            } else {
                alert('❌ Test Failed: ' + (data.error || 'Unknown error'));
            }
        } catch (err) {
            alert('❌ Network error: ' + err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = '🧪 Test OpenAI Connection';
        }
    }
    </script>
</body>
</html>