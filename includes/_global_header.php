<?php
// /includes/_global_header.php
// Shared global header partial used across Flowwork sections.
//
// Inputs (set by caller before include):
//   $headerScope  - BEM root class, e.g. 'fw-admin', 'fw-home', 'fw-proj'.
//                   Defaults to 'fw-home'. Used to emit "<scope>__header" etc.
//                   so each section can keep its own scoped CSS.
//   $companyName  - string, falls back to $_SESSION['company_name'] or 'Your Company'
//   $firstName    - string, falls back to $_SESSION['user_first_name'] or 'Welcome'
//   $companyLogo  - optional string URL to a company logo image (null = svg fallback)

$scope       = isset($headerScope) && $headerScope ? $headerScope : 'fw-home';
$companyName = isset($companyName) && $companyName !== '' ? $companyName : ($_SESSION['company_name'] ?? 'Your Company');
$firstName   = isset($firstName)   && $firstName   !== '' ? $firstName   : ($_SESSION['user_first_name'] ?? 'Welcome');
$companyLogo = $companyLogo ?? null;
?>
<header class="<?= $scope ?>__header">
    <div class="<?= $scope ?>__brand">
        <div class="<?= $scope ?>__logo-tile">
            <?php if ($companyLogo): ?>
                <img src="<?= htmlspecialchars($companyLogo) ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="<?= $scope ?>__company-logo">
            <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M20 7L12 3L4 7V17L12 21L20 17V7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 12L20 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 12V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 12L4 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            <?php endif; ?>
        </div>
        <div class="<?= $scope ?>__brand-text">
            <div class="<?= $scope ?>__company-name"><?= htmlspecialchars($companyName) ?></div>
            <div class="<?= $scope ?>__app-name">Flowwork</div>
        </div>
    </div>

    <div class="<?= $scope ?>__greeting">
        Hello, <span class="<?= $scope ?>__greeting-name"><?= htmlspecialchars($firstName) ?></span>
    </div>

    <div class="<?= $scope ?>__controls">
        <button class="<?= $scope ?>__theme-toggle" id="themeToggle" aria-label="Toggle theme">
            <svg class="<?= $scope ?>__theme-icon <?= $scope ?>__theme-icon--light" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
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
            <svg class="<?= $scope ?>__theme-icon <?= $scope ?>__theme-icon--dark" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>

        <div class="<?= $scope ?>__menu-wrapper">
            <button class="<?= $scope ?>__kebab-toggle" id="kebabToggle" aria-label="Open menu" aria-expanded="false" aria-controls="kebabMenu">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                    <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                    <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                </svg>
            </button>
            <nav class="<?= $scope ?>__kebab-menu" id="kebabMenu" role="menu" aria-hidden="true">
                <a href="/admin/" class="<?= $scope ?>__kebab-item" role="menuitem">Admin/Settings</a>
                <a href="/contact/" class="<?= $scope ?>__kebab-item" role="menuitem">Contact Us</a>
                <a href="/help/" class="<?= $scope ?>__kebab-item" role="menuitem">Help</a>
                <a href="/logout.php" class="<?= $scope ?>__kebab-item" role="menuitem">Logout</a>
            </nav>
        </div>
    </div>
</header>
