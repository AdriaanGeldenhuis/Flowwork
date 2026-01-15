<?php
// /shopping/preferences.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-SHOP-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch preferences
$stmt = $DB->prepare("SELECT * FROM shopping_preferences WHERE company_id = ? AND user_id = ?");
$stmt->execute([$companyId, $userId]);
$prefs = $stmt->fetch(PDO::FETCH_ASSOC);

// Create default if not exists
if (!$prefs) {
    $stmt = $DB->prepare("
        INSERT INTO shopping_preferences (company_id, user_id, default_radius_km, route_mode)
        VALUES (?, ?, 25.00, 'drive')
    ");
    $stmt->execute([$companyId, $userId]);
    $prefs = [
        'default_radius_km' => 25.00,
        'avoid_stores_json' => '[]',
        'prefer_stores_json' => '[]',
        'brand_prefs_json' => '[]',
        'unit_prefs_json' => '[]',
        'route_mode' => 'drive'
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $radiusKm = floatval($_POST['radius_km'] ?? 25.00);
    $routeMode = $_POST['route_mode'] ?? 'drive';
    
    $stmt = $DB->prepare("
        UPDATE shopping_preferences 
        SET default_radius_km = ?, route_mode = ?, updated_at = NOW()
        WHERE company_id = ? AND user_id = ?
    ");
    $stmt->execute([$radiusKm, $routeMode, $companyId, $userId]);
    
    $successMsg = 'Preferences saved successfully!';
    
    // Reload prefs
    $stmt = $DB->prepare("SELECT * FROM shopping_preferences WHERE company_id = ? AND user_id = ?");
    $stmt->execute([$companyId, $userId]);
    $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch company & user
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();
$companyName = $company['name'] ?? 'Company';

$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$firstName = $user['first_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preferences – Shopping AI</title>
    <link rel="stylesheet" href="/shopping/assets/shopping.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
    <main class="fw-shopping">
        <div class="fw-shopping__container">
            
            <!-- Header -->
            <header class="fw-shopping__header">
                <div class="fw-shopping__brand">
                    <div class="fw-shopping__logo-tile">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 2L7.17 4H4c-1.1 0-2 .9-2 2v13c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z" stroke="currentColor" stroke-width="1.5" fill="none"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" fill="none"/>
                        </svg>
                    </div>
                    <div class="fw-shopping__brand-text">
                        <div class="fw-shopping__company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="fw-shopping__app-name">Shopping AI</div>
                    </div>
                </div>

                <div class="fw-shopping__greeting">
                    Hello, <span class="fw-shopping__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-shopping__controls">
                    <a href="/shopping/" class="fw-shopping__home-btn" title="Back">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </a>
                    
                    <a href="/" class="fw-shopping__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </a>

                    <button class="fw-shopping__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--light" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
                            <line x1="12" y1="1" x2="12" y2="3" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Preferences Form -->
            <div class="fw-shopping__quick-add-panel">
                <h2 class="fw-shopping__section-title">Shopping Preferences</h2>

                <?php if (isset($successMsg)): ?>
                    <div style="padding: 16px; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); border-radius: 12px; margin-bottom: 24px; color: #10b981; font-weight: 600;">
                        ✓ <?= htmlspecialchars($successMsg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" style="display: flex; flex-direction: column; gap: 24px;">
                    
                    <!-- Search Settings -->
                    <div>
                        <h3 style="font-size: 18px; font-weight: 600; margin: 0 0 16px 0; color: var(--fw-text-primary);">
                            Search Settings
                        </h3>
                        
                        <div class="fw-shopping__form-group" style="margin-bottom: 16px;">
                            <label class="fw-shopping__form-label">Default Search Radius (km)</label>
                            <input type="number" 
                                   name="radius_km" 
                                   class="fw-shopping__form-input" 
                                   value="<?= htmlspecialchars($prefs['default_radius_km']) ?>"
                                   step="0.1"
                                   min="1"
                                   max="100"
                                   required>
                            <small style="font-size: 12px; color: var(--fw-text-muted); margin-top: 4px; display: block;">
                                How far to search for stores (1-100 km)
                            </small>
                        </div>

                        <div class="fw-shopping__form-group">
                            <label class="fw-shopping__form-label">Route Mode</label>
                            <select name="route_mode" class="fw-shopping__form-select">
                                <option value="drive" <?= $prefs['route_mode'] === 'drive' ? 'selected' : '' ?>>🚗 Drive</option>
                                <option value="walk" <?= $prefs['route_mode'] === 'walk' ? 'selected' : '' ?>>🚶 Walk</option>
                                <option value="bike" <?= $prefs['route_mode'] === 'bike' ? 'selected' : '' ?>>🚴 Bike</option>
                            </select>
                            <small style="font-size: 12px; color: var(--fw-text-muted); margin-top: 4px; display: block;">
                                Preferred travel method for route planning
                            </small>
                        </div>
                    </div>

                    <!-- Store Preferences (Placeholder) -->
                    <div>
                        <h3 style="font-size: 18px; font-weight: 600; margin: 0 0 16px 0; color: var(--fw-text-primary);">
                            Store Preferences
                        </h3>
                        <div style="padding: 16px; background: var(--fw-highlight); border-radius: 8px; color: var(--fw-text-muted); font-size: 14px;">
                            🚧 Preferred/avoided stores management coming soon
                        </div>
                    </div>

                    <!-- Brand Preferences (Placeholder) -->
                    <div>
                        <h3 style="font-size: 18px; font-weight: 600; margin: 0 0 16px 0; color: var(--fw-text-primary);">
                            Brand Preferences
                        </h3>
                        <div style="padding: 16px; background: var(--fw-highlight); border-radius: 8px; color: var(--fw-text-muted); font-size: 14px;">
                            🚧 Brand preferences coming soon
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 16px; border-top: 1px solid var(--fw-border);">
                        <a href="/shopping/" class="fw-shopping__btn fw-shopping__btn--secondary">Cancel</a>
                        <button type="submit" class="fw-shopping__btn fw-shopping__btn--primary">Save Preferences</button>
                    </div>
                </form>
            </div>

        </div>

        <footer class="fw-shopping__footer">
            <span>Shopping AI v<?= ASSET_VERSION ?></span>
            <span id="themeIndicator">Theme: Light</span>
        </footer>
    </main>

    <script src="/shopping/assets/shopping.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>