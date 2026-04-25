<?php
// /includes/_global_header.php
// Shared global header partial used across Flowwork sections.
//
// Inputs (set by caller before include):
//   $headerScope  - BEM root class, e.g. 'fw-admin', 'fw-home', 'fw-proj'.
//                   Defaults to 'fw-home'. Used to emit "<scope>__header" etc.
//                   so each section can keep its own scoped CSS.
//   $companyName  - string, falls back to a live lookup against
//                   $_SESSION['company_id'] (so company switches show up).
//   $firstName    - string, falls back to $_SESSION['user_first_name'] or 'Welcome'
//   $companyLogo  - optional string URL to a company logo image (null = svg fallback)

$scope       = isset($headerScope) && $headerScope ? $headerScope : 'fw-home';
$firstName   = isset($firstName)   && $firstName   !== '' ? $firstName   : ($_SESSION['user_first_name'] ?? 'Welcome');
$companyLogo = $companyLogo ?? null;

if (!isset($companyName) || $companyName === '') {
    $companyName = 'Your Company';
    if (!empty($_SESSION['company_id'])) {
        try {
            global $DB;
            $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
            $stmt->execute([(int)$_SESSION['company_id']]);
            $name = $stmt->fetchColumn();
            if ($name !== false && $name !== '') {
                $companyName = $name;
            }
        } catch (Exception $e) {
            error_log('Header company name lookup failed: ' . $e->getMessage());
        }
    }
}

// Multi-company switcher data — only meaningful when authenticated.
$fwHeaderCompanies = [];
$fwHeaderActiveId  = (int)($_SESSION['company_id'] ?? 0);
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/companies.php';
    $fwHeaderCompanies = fw_user_companies((int)$_SESSION['user_id']);
}
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
        <a href="/home.php" class="<?= $scope ?>__home-link" aria-label="Back to home" title="Home">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>

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
                <?php if (count($fwHeaderCompanies) > 1): ?>
                    <div class="<?= $scope ?>__kebab-section-label">Switch company</div>
                    <?php foreach ($fwHeaderCompanies as $fwUc): ?>
                        <?php $fwIsActive = ((int)$fwUc['id'] === $fwHeaderActiveId); ?>
                        <button type="button"
                                class="<?= $scope ?>__kebab-item <?= $scope ?>__kebab-item--company<?= $fwIsActive ? ' is-active' : '' ?>"
                                role="menuitem"
                                data-fw-switch-company="<?= (int)$fwUc['id'] ?>"
                                <?= $fwIsActive ? 'aria-current="true" disabled' : '' ?>>
                            <span class="<?= $scope ?>__kebab-item-name"><?= htmlspecialchars($fwUc['name']) ?></span>
                            <?php if ($fwIsActive): ?>
                                <span class="<?= $scope ?>__kebab-item-badge" aria-hidden="true">&#10003;</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                    <div class="<?= $scope ?>__kebab-divider" role="separator"></div>
                <?php endif; ?>
                <a href="/admin/" class="<?= $scope ?>__kebab-item" role="menuitem">Admin/Settings</a>
                <a href="/contact/" class="<?= $scope ?>__kebab-item" role="menuitem">Contact Us</a>
                <a href="/help/" class="<?= $scope ?>__kebab-item" role="menuitem">Help</a>
                <a href="/logout.php" class="<?= $scope ?>__kebab-item" role="menuitem">Logout</a>
            </nav>
        </div>
    </div>
</header>
