<?php
// /shopping/templates.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

define('ASSET_VERSION', '2025-01-21-SHOP-1');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Fetch templates
$stmt = $DB->prepare("
    SELECT * FROM shopping_templates 
    WHERE company_id = ?
    ORDER BY name
");
$stmt->execute([$companyId]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Templates – Shopping AI</title>
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
                        </svg>
                        <svg class="fw-shopping__theme-icon fw-shopping__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Templates -->
            <div class="fw-shopping__section">
                <h2 class="fw-shopping__section-title">List Templates</h2>

                <?php if (empty($templates)): ?>
                    <div style="padding: 48px; text-align: center; color: var(--fw-text-muted);">
                        <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
                        <p style="font-size: 16px;">No templates yet</p>
                        <p style="font-size: 14px; margin-top: 8px;">
                            Templates let you quickly create lists from predefined items.<br>
                            Create a list, then save it as a template from the list menu.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="fw-shopping__lists-grid">
                        <?php foreach ($templates as $tpl): ?>
                            <?php
                            $items = json_decode($tpl['items_json'], true);
                            $itemCount = is_array($items) ? count($items) : 0;
                            ?>
                            <div class="fw-shopping__list-card">
                                <div class="fw-shopping__list-card-header">
                                    <h3 class="fw-shopping__list-card-title"><?= htmlspecialchars($tpl['name']) ?></h3>
                                    <span class="fw-shopping__list-card-badge fw-shopping__list-card-badge--open">
                                        Template
                                    </span>
                                </div>
                                <div class="fw-shopping__list-card-meta">
                                    <?= ucfirst($tpl['purpose']) ?> • <?= $itemCount ?> items
                                </div>
                                <div style="margin-top: 16px; display: flex; gap: 8px;">
                                    <button class="fw-shopping__btn fw-shopping__btn--primary" 
                                            onclick="alert('Create from template: Coming soon')">
                                        📋 Use Template
                                    </button>
                                    <button class="fw-shopping__btn fw-shopping__btn--secondary" 
                                            onclick="if(confirm('Delete template?')) alert('Delete: Coming soon')">
                                        🗑️
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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