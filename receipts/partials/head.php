<?php
// /receipts/partials/head.php — <head>, the top bar and the sidebar shared by
// every Receipts page; opens the main pane (closed by foot.php).
// Built on the Maison design system: /mail/css/mail.css, then /receipts/css/receipts.css.
// Inputs: $page (nav key), $pageTitle, $navView (optional inbox view), $wide (optional: full-width pane), $RC_* from RcBoot
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$rcV = fn(string $rel): string => (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
$companyName = (string)($RC_COMPANY['name'] ?? 'Your company');
$navView = $navView ?? '';
$wide = $wide ?? false;
$nav = RcReceipts::navCounts($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE);
$navItems = [
    ['index', '', 'Overview', 'if-overview', '/receipts/', null],
    ['inbox', 'todo', RcKit::canSeeAll($RC_ROLE) ? 'To do' : 'My receipts', 'i-inbox', '/receipts/inbox.php', $nav['todo'] ?: null],
    ['inbox', 'blocked', 'Held by a check', 'i-alert', '/receipts/inbox.php?view=blocked', $nav['blocked'] ?: null],
    ['inbox', 'done', 'In the books', 'if-ledger', '/receipts/inbox.php?view=done', null],
    ['inbox', 'aside', 'Set aside', 'i-archive', '/receipts/inbox.php?view=aside', $nav['aside'] ?: null],
    ['inbox', 'all', 'Every receipt', 'i-all', '/receipts/inbox.php?view=all', null],
];
if (!$nav['blocked']) $navItems = array_values(array_filter($navItems, fn($i) => $i[1] !== 'blocked'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <meta name="robots" content="noindex">
    <title><?= $e($pageTitle) ?> · Receipts · <?= $e($companyName) ?></title>
    <?php include __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $rcV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/receipts/css/receipts.css?v=<?= $rcV('receipts/css/receipts.css') ?>">
</head>
<body class="fwm fwr" data-mod="receipts" data-theme="<?= $fwTheme ?>" data-page="<?= $e($page) ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/../../finances/partials/icons.php'; ?>
<?php include __DIR__ . '/../../home/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
<div class="fwm-app fwr-app">
    <header class="fwm-top">
        <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open the receipts menu"><svg class="i"><use href="#i-menu"/></svg></button>
        <a class="fwm-brand" href="/receipts/" aria-label="Receipts — overview">
            <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#ir-seal"/></svg></span>
            <span class="fwm-brand__text">
                <span class="fwm-brand__name">Receipts</span>
                <span class="fwm-brand__company"><?= $e($companyName) ?></span>
            </span>
        </a>

        <div class="fwm-search fwr-jump" role="search">
            <label class="fwm-search__box">
                <svg class="i"><use href="#i-search"/></svg>
                <span class="sr-only">Find a receipt</span>
                <input type="search" id="jumpInput" placeholder="Find a supplier, slip number or amount" autocomplete="off" spellcheck="false" enterkeyhint="search"
                       role="combobox" aria-expanded="false" aria-controls="jumpList" aria-autocomplete="list">
                <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
            </label>
            <div class="fwr-jump__list" id="jumpList" role="listbox" aria-label="Receipts and pages" hidden></div>
        </div>

        <div class="fwm-top__actions">
            <?php if ($RC_CAPS['upload']): ?>
            <button class="icon-btn only-narrow" type="button" data-capture="camera" aria-label="Take a photo of a slip"><svg class="i"><use href="#ir-camera"/></svg></button>
            <?php endif; ?>
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="<?= $fwTheme === 'dark' ? 'Light theme' : 'Dark theme' ?>">
                <svg class="i"><use href="#<?= $fwTheme === 'dark' ? 'i-sun' : 'i-moon' ?>"/></svg>
            </button>
            <a class="icon-btn" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
            <button class="icon-btn" id="kebabBtn" type="button" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" data-tip="Menu" data-tip-pos="left"><svg class="i"><use href="#i-more-v"/></svg></button>
        </div>
    </header>

    <div class="fwm-body">
        <aside class="fwm-side fwr-side" id="side" aria-label="Receipts sections">
            <div class="fwm-side__scroll scroll">
                <?php if ($RC_CAPS['upload']): ?>
                <div style="padding:2px 4px 12px">
                    <button class="fwm-compose-btn" type="button" data-capture="pick"><svg class="i"><use href="#ir-drop"/></svg><span>Send in receipts</span></button>
                </div>
                <?php endif; ?>
                <nav class="fwm-nav" aria-label="Receipts">
                    <?php foreach ($navItems as [$navKey, $navItemView, $navLabel, $navIcon, $navHref, $navCount]):
                        $navOn = $page === $navKey && ($navKey !== 'inbox' || $navView === $navItemView); ?>
                        <a class="fwm-nav__item<?= $navOn ? ' is-active' : '' ?><?= $navItemView === 'blocked' ? ' is-alert' : '' ?>" href="<?= $e($navHref) ?>"<?= $navOn ? ' aria-current="page"' : '' ?>>
                            <svg class="i"><use href="#<?= $e($navIcon) ?>"/></svg><span class="fwm-nav__label"><?= $e($navLabel) ?></span>
                            <?php if ($navCount !== null): ?><span class="fwm-nav__count" data-count="<?= $e($navItemView) ?>"><?= (int)$navCount ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($RC_CAPS['settings']): ?>
                        <a class="fwm-nav__item<?= $page === 'settings' ? ' is-active' : '' ?>" href="/receipts/settings.php"<?= $page === 'settings' ? ' aria-current="page"' : '' ?>>
                            <svg class="i"><use href="#i-settings"/></svg><span class="fwm-nav__label">Settings</span>
                        </a>
                    <?php endif; ?>
                </nav>
                <div class="fwm-nav__head"><h3>Elsewhere</h3></div>
                <nav class="fwm-nav" aria-label="Related">
                    <?php if (RcKit::canSeeAll($RC_ROLE)): ?>
                    <a class="fwm-nav__item" href="/finances/#payables"><svg class="i"><use href="#if-out"/></svg><span class="fwm-nav__label">Finance · Payables</span><svg class="i i--xs" aria-label="Opens Finance"><use href="#i-external"/></svg></a>
                    <?php endif; ?>
                    <a class="fwm-nav__item" href="/crm/#suppliers"><svg class="i"><use href="#i-building"/></svg><span class="fwm-nav__label">Suppliers</span><svg class="i i--xs" aria-label="Opens the CRM"><use href="#i-external"/></svg></a>
                </nav>
            </div>
            <div class="fwm-side__foot">
                <a class="fwr-side__plan" href="/receipts/">
                    <span class="fwr-side__row"><small><?= $e(date('F')) ?> from receipts</small></span>
                    <b><?= $e(RcKit::money((int)round($nav['month'] * 100))) ?></b>
                    <small><?= $nav['todo'] ? $e($nav['todo'] . ' still to do') : 'Nothing waiting — well done' ?></small>
                </a>
            </div>
        </aside>
        <main class="fwm-pane fwr-main<?= $wide ? ' is-wide' : '' ?>" id="main" tabindex="-1">
            <div class="fwr-scroll scroll" id="scroll">
                <div class="fwr-content">
