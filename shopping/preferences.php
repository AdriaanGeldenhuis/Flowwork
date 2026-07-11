<?php
// /shopping/preferences.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2026-07-10-shopping-crm-parity');

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
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title>Preferences – <?= htmlspecialchars($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/shopping/assets/shopping.css?v=<?= ASSET_VERSION ?>">
</head>
<body class="fw-shopping">
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
                        <div class="fw-shopping__app-name">Shopping AI – Preferences</div>
                    </div>
                </div>

                <div class="fw-shopping__greeting">
                    Hello, <span class="fw-shopping__greeting-name"><?= htmlspecialchars($firstName) ?></span>
                </div>

                <div class="fw-shopping__controls">
                    <a href="/shopping/" class="fw-shopping__home-btn" title="Back">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>

                    <a href="/" class="fw-shopping__home-btn" title="Home">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>

                    <button class="fw-shopping__theme-toggle" id="themeToggle" aria-label="Toggle theme">
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>

                    <div class="fw-shopping__menu-wrapper">
                        <button class="fw-shopping__kebab-toggle" id="kebabToggle" aria-label="Menu">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                                <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                            </svg>
                        </button>
                        <nav class="fw-shopping__kebab-menu" id="kebabMenu" aria-hidden="true">
                            <a href="/shopping/" class="fw-shopping__kebab-item">Shopping Lists</a>
                            <a href="/shopping/templates.php" class="fw-shopping__kebab-item">Templates</a>
                            <a href="/shopping/preferences.php" class="fw-shopping__kebab-item">Preferences</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="fw-shopping__main">
            <div class="fw-shopping__content">

            <div class="fw-shopping__page-header">
                <h1 class="fw-shopping__page-title">Shopping Preferences</h1>
                <p class="fw-shopping__page-subtitle">Tune how stores are found and routes are planned</p>
            </div>

            <!-- View Tabs -->
            <div class="fw-shopping__view-tabs">
                <a href="/shopping/" class="fw-shopping__view-tab">Lists</a>
                <a href="/shopping/templates.php" class="fw-shopping__view-tab">Templates</a>
                <a href="/shopping/preferences.php" class="fw-shopping__view-tab fw-shopping__view-tab--active">Preferences</a>
            </div>

            <!-- Preferences Form -->
            <div class="fw-shopping__form-card">

                <?php if (isset($successMsg)): ?>
                    <div class="fw-shopping__alert fw-shopping__alert--success">
                        ✓ <?= htmlspecialchars($successMsg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="fw-shopping__form">

                    <!-- Search Settings -->
                    <div class="fw-shopping__form-section">
                        <h3 class="fw-shopping__form-section-title">Search Settings</h3>

                        <div class="fw-shopping__form-group">
                            <label class="fw-shopping__form-label">Default Search Radius (km)</label>
                            <input type="number"
                                   name="radius_km"
                                   class="fw-shopping__form-input"
                                   value="<?= htmlspecialchars($prefs['default_radius_km']) ?>"
                                   step="0.1"
                                   min="1"
                                   max="100"
                                   required>
                            <small class="fw-shopping__form-help">
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
                            <small class="fw-shopping__form-help">
                                Preferred travel method for route planning
                            </small>
                        </div>
                    </div>

                    <!-- Store Preferences (Placeholder) -->
                    <div class="fw-shopping__form-section">
                        <h3 class="fw-shopping__form-section-title">Store Preferences</h3>
                        <div class="fw-shopping__form-placeholder">
                            🚧 Preferred/avoided stores management coming soon
                        </div>
                    </div>

                    <!-- Brand Preferences (Placeholder) -->
                    <div class="fw-shopping__form-section">
                        <h3 class="fw-shopping__form-section-title">Brand Preferences</h3>
                        <div class="fw-shopping__form-placeholder">
                            🚧 Brand preferences coming soon
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="fw-shopping__form-actions">
                        <a href="/shopping/" class="fw-shopping__btn fw-shopping__btn--secondary">Cancel</a>
                        <button type="submit" class="fw-shopping__btn fw-shopping__btn--primary">Save Preferences</button>
                    </div>
                </form>
            </div>

            </div>
            </main>

            <!-- Footer -->
            <footer class="fw-shopping__footer">
                <span>Shopping AI v<?= ASSET_VERSION ?></span>
                <span id="themeIndicator">Theme: Dark</span>
            </footer>

    </div>

    <script src="/shopping/assets/shopping.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>