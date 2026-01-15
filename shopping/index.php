<?php
// /shopping/index.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-SHOP-1');

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

// Fetch list counts
$stmt = $DB->prepare("
  SELECT status, COUNT(*) as cnt 
  FROM shopping_lists 
  WHERE company_id = ? AND (owner_id = ? OR shared_mode IN ('team', 'token'))
  GROUP BY status
");
$stmt->execute([$companyId, $userId]);
$counts = ['open' => 0, 'buying' => 0, 'done' => 0];
while ($row = $stmt->fetch()) {
    $counts[$row['status']] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping AI – <?= htmlspecialchars($companyName) ?></title>
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
                            <line x1="12" y1="21" x2="12" y2="23" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/>
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
                            <a href="/shopping/templates.php" class="fw-shopping__kebab-item">Templates</a>
                            <a href="/shopping/preferences.php" class="fw-shopping__kebab-item">Preferences</a>
                        </nav>
                    </div>
                </div>
            </header>

            <!-- Stats -->
            <div class="fw-shopping__stats-grid">
                <div class="fw-shopping__stat-card">
                    <div class="fw-shopping__stat-value"><?= $counts['open'] ?></div>
                    <div class="fw-shopping__stat-label">Open Lists</div>
                </div>
                <div class="fw-shopping__stat-card">
                    <div class="fw-shopping__stat-value"><?= $counts['buying'] ?></div>
                    <div class="fw-shopping__stat-label">Buying Now</div>
                </div>
                <div class="fw-shopping__stat-card">
                    <div class="fw-shopping__stat-value"><?= $counts['done'] ?></div>
                    <div class="fw-shopping__stat-label">Completed</div>
                </div>
            </div>

            <!-- Quick Add -->
            <div class="fw-shopping__quick-add-panel">
                <h2 class="fw-shopping__section-title">Quick Add Items</h2>
                <div class="fw-shopping__quick-add-form">
                    <textarea 
                        id="quickAddInput" 
                        class="fw-shopping__quick-add-textarea" 
                        placeholder="Type or paste items, e.g:&#10;2x PVC elbow 20mm&#10;6m conduit 16mm&#10;milk 2L&#10;bread"
                        rows="5"
                    ></textarea>
                    <div class="fw-shopping__quick-add-actions">
                        <button class="fw-shopping__btn fw-shopping__btn--secondary" id="btnQuickParse">Parse Items</button>
                        <button class="fw-shopping__btn fw-shopping__btn--primary" id="btnQuickCreate" disabled>Create List</button>
                    </div>
                </div>
                <div id="quickAddPreview" class="fw-shopping__quick-add-preview" style="display:none;"></div>
            </div>

            <!-- My Lists -->
            <div class="fw-shopping__section">
                <h2 class="fw-shopping__section-title">My Lists</h2>
                <div id="myListsContainer" class="fw-shopping__lists-grid">
                    <div class="fw-shopping__loading">Loading lists...</div>
                </div>
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