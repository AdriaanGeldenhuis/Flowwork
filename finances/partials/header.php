<?php
// /finances/partials/header.php
// Shared finances page header. Configure before including:
//   $finTitle  (string)      app-name line, e.g. 'Journal Entries'
//   $finBack   (string|null) back-button href; null hides the button
//   $finKebab  (array|null)  label => href map for the kebab menu; null hides it
// Company name and first name are looked up (and memoised) here so legacy
// pages can adopt the header without adding their own queries.

$finTitle = $finTitle ?? 'Finances';
$finBack = $finBack ?? '/finances/';
if (!isset($finKebab)) {
    // Default: full role-aware finance menu so every section is reachable
    // from any finance page. Pass an explicit array to customise, or [] to hide.
    require_once __DIR__ . '/nav.php';
    try {
        $finKebab = fw_finance_nav_items($DB);
    } catch (Throwable $e) {
        $finKebab = null; // Header must never take a page down.
    }
}

if (!isset($finCompanyName) || !isset($finFirstName)) {
    static $finHeaderCtx = null;
    if ($finHeaderCtx === null) {
        $finHeaderCtx = ['company' => 'Company', 'first' => 'User'];
        try {
            if (!empty($_SESSION['company_id'])) {
                $st = $DB->prepare("SELECT name FROM companies WHERE id = ?");
                $st->execute([$_SESSION['company_id']]);
                $finHeaderCtx['company'] = $st->fetchColumn() ?: 'Company';
            }
            if (!empty($_SESSION['user_id'])) {
                $st = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
                $st->execute([$_SESSION['user_id']]);
                $finHeaderCtx['first'] = $st->fetchColumn() ?: 'User';
            }
        } catch (Exception $e) {
            // Header must never take a page down.
        }
    }
    $finCompanyName = $finCompanyName ?? $finHeaderCtx['company'];
    $finFirstName = $finFirstName ?? $finHeaderCtx['first'];
}
?>
<header class="fw-finance__header">
    <div class="fw-finance__brand">
        <div class="fw-finance__logo-tile">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="fw-finance__brand-text">
            <div class="fw-finance__company-name"><?= htmlspecialchars($finCompanyName) ?></div>
            <div class="fw-finance__app-name"><?= htmlspecialchars($finTitle) ?></div>
        </div>
    </div>

    <div class="fw-finance__greeting">
        Hello, <span class="fw-finance__greeting-name"><?= htmlspecialchars($finFirstName) ?></span>
    </div>

    <div class="fw-finance__controls">
        <?php if ($finBack !== null): ?>
        <a href="<?= htmlspecialchars($finBack) ?>" class="fw-finance__back-btn" title="Back">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        <?php endif; ?>

        <a href="/" class="fw-finance__home-btn" title="Home">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>

        <button class="fw-finance__theme-toggle" id="themeToggle" aria-label="Toggle theme">
            <svg class="fw-finance__theme-icon fw-finance__theme-icon--light" viewBox="0 0 24 24" fill="none">
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
            <svg class="fw-finance__theme-icon fw-finance__theme-icon--dark" viewBox="0 0 24 24" fill="none">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>

        <?php if (is_array($finKebab) && $finKebab): ?>
        <div class="fw-finance__menu-wrapper">
            <button class="fw-finance__kebab-toggle" id="kebabToggle" aria-label="Menu" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="5" r="1.5" fill="currentColor"/>
                    <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                    <circle cx="12" cy="19" r="1.5" fill="currentColor"/>
                </svg>
            </button>
            <nav class="fw-finance__kebab-menu" id="kebabMenu" aria-hidden="true">
                <?php foreach ($finKebab as $label => $href): ?>
                <a href="<?= htmlspecialchars($href) ?>" class="fw-finance__kebab-item"><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</header>
