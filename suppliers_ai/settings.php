<?php
// /suppliers_ai/settings.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-22-SAI-2');

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
    <title>Settings – Suppliers AI – <?= htmlspecialchars($companyName) ?></title>
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
                        <div class="fw-suppliers-ai__app-name">Suppliers AI Settings</div>
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
                    
                    <div class="fw-suppliers-ai__section">
                        <h2 class="fw-suppliers-ai__section-title">🤖 OpenAI Configuration</h2>
                        <p class="fw-suppliers-ai__help-text" style="margin-bottom: 16px;">
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
                                <label class="fw-suppliers-ai__label">OpenAI API Key <span style="color: var(--accent-danger);">*</span></label>
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

                        <hr style="margin: 24px 0; border: none; border-top: 1px solid var(--fw-border);">

                        <h3 style="font-size: 16px; margin-bottom: 12px;">Optional: External Directory APIs</h3>

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
                    
                    <div class="fw-suppliers-ai__section">
                        <h2 class="fw-suppliers-ai__section-title">AI Rules (JSON)</h2>
                        <p class="fw-suppliers-ai__help-text" style="margin-bottom: 16px;">
                            Configure synonyms, category mappings, allow/deny lists
                        </p>

                        <div class="fw-suppliers-ai__form-group">
                            <textarea name="rules_json" class="fw-suppliers-ai__textarea" rows="20" 
                                      style="font-family: monospace; font-size: 13px;"><?= htmlspecialchars($rulesDisplay) ?></textarea>
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

            <!-- Footer -->
            <footer class="fw-suppliers-ai__footer">
                <span>Suppliers AI v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Light</span>
            </footer>

        </div>
    </main>

    <script src="/suppliers_ai/suppliers_ai.js?v=<?= ASSET_VERSION ?>"></script>
    <script>
    async function testOpenAI() {
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = '⏳ Testing...';
        
        try {
            const response = await fetch('/suppliers_ai/ajax/test_openai.php', { method: 'POST' });
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
    <style>
        .fw-suppliers-ai__settings-panel {
            display: flex;
            flex-direction: column;
            gap: var(--fw-spacing-xl);
        }
        .fw-suppliers-ai__settings-form {
            background: var(--fw-panel-bg);
            border: 1px solid var(--fw-panel-border);
            border-radius: var(--fw-radius-lg);
            padding: var(--fw-spacing-xl);
            box-shadow: var(--fw-shadow-md);
            backdrop-filter: blur(12px);
        }
        .fw-suppliers-ai__form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--fw-spacing-lg);
            margin-bottom: var(--fw-spacing-lg);
        }
        .fw-suppliers-ai__form-group {
            display: flex;
            flex-direction: column;
            gap: var(--fw-spacing-xs);
        }
        .fw-suppliers-ai__textarea {
            width: 100%;
            padding: var(--fw-spacing-md);
            background: var(--fw-panel-bg);
            border: 1px solid var(--fw-border);
            border-radius: var(--fw-radius-md);
            color: var(--fw-text-primary);
            font-family: inherit;
            resize: vertical;
        }
        .fw-suppliers-ai__textarea:focus {
            outline: none;
            border-color: var(--accent-ai-primary);
            box-shadow: 0 0 0 3px var(--accent-ai-glow);
        }
        .fw-suppliers-ai__checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: var(--fw-spacing-sm);
            font-size: 14px;
            color: var(--fw-text-primary);
            cursor: pointer;
        }
        .fw-suppliers-ai__checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .fw-suppliers-ai__form-actions {
            display: flex;
            gap: var(--fw-spacing-md);
            padding-top: var(--fw-spacing-lg);
            border-top: 1px solid var(--fw-border);
        }
        .fw-suppliers-ai__alert {
            padding: var(--fw-spacing-md) var(--fw-spacing-lg);
            border-radius: var(--fw-radius-md);
            margin-bottom: var(--fw-spacing-lg);
            font-size: 14px;
            font-weight: 600;
        }
        .fw-suppliers-ai__alert--success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
        }
        .fw-suppliers-ai__alert--error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        .fw-suppliers-ai__help-text a {
            color: var(--accent-ai-primary);
            text-decoration: none;
            font-weight: 600;
        }
        .fw-suppliers-ai__help-text a:hover {
            text-decoration: underline;
        }
    </style>
</body>
</html>