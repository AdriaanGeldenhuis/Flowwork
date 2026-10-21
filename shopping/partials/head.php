<?php
// /shopping/partials/head.php — <head>, the top bar and the sidebar shared by
// every signed-in Shopping page; opens the main pane (closed by foot.php).
// Built on the Maison design system: /mail/css/mail.css, then /shopping/css/shopping.css.
// Inputs: $page (nav key), $pageTitle, $activeListId (optional), $bodyClass (optional), $SH_* from ShBoot
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$shV = fn(string $rel): string => (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
$companyName = (string)($SH_COMPANY['name'] ?? 'Your company');
$activeListId = (int)($activeListId ?? 0);
$bodyClass = $bodyClass ?? '';
$SH_NAV = ShLists::navCounts($DB, $SH_COMPANY_ID, $SH_USER_ID);
$navItems = [
    ['index', 'Overview', 'is-cart', '/shopping/', $SH_NAV['open'] ?: null],
    ['templates', 'Templates', 'i-template', '/shopping/templates.php', null],
    ['prices', 'Prices & stores', 'is-price', '/shopping/prices.php', null],
    ['prefs', 'Preferences', 'i-settings', '/shopping/preferences.php', null],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <meta name="robots" content="noindex">
    <title><?= $e($pageTitle) ?> · Shopping · <?= $e($companyName) ?></title>
    <?php include __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $shV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/shopping/css/shopping.css?v=<?= $shV('shopping/css/shopping.css') ?>">
</head>
<body class="fwm fws <?= $e($bodyClass) ?>" data-mod="shopping" data-theme="<?= $fwTheme ?>" data-page="<?= $e($page) ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/../../home/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
<a class="fws-skip" href="#main">Skip to the content</a>
<div class="fwm-app fws-app">
    <header class="fwm-top">
        <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open the shopping menu"><svg class="i"><use href="#i-menu"/></svg></button>
        <a class="fwm-brand" href="/shopping/" aria-label="Shopping — overview">
            <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#is-cart"/></svg></span>
            <span class="fwm-brand__text">
                <span class="fwm-brand__name">Shopping</span>
                <span class="fwm-brand__company"><?= $e($companyName) ?></span>
            </span>
        </a>

        <div class="fwm-search fws-jump" role="search">
            <label class="fwm-search__box">
                <svg class="i"><use href="#i-search"/></svg>
                <span class="sr-only">Find a list or an item</span>
                <input type="search" id="jumpInput" placeholder="Find a list or an item" autocomplete="off" spellcheck="false" enterkeyhint="search"
                       role="combobox" aria-expanded="false" aria-controls="jumpList" aria-autocomplete="list">
                <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
            </label>
            <div class="fws-jump__list" id="jumpList" role="listbox" aria-label="Lists, items and pages" hidden></div>
        </div>

        <div class="fwm-top__actions">
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="<?= $fwTheme === 'dark' ? 'Light theme' : 'Dark theme' ?>">
                <svg class="i"><use href="#<?= $fwTheme === 'dark' ? 'i-sun' : 'i-moon' ?>"/></svg>
            </button>
            <a class="icon-btn" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
            <button class="icon-btn" id="kebabBtn" type="button" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" data-tip="Menu" data-tip-pos="left"><svg class="i"><use href="#i-more-v"/></svg></button>
        </div>
    </header>

    <div class="fwm-body">
        <aside class="fwm-side fws-side" id="side" aria-label="Shopping sections">
            <div class="fwm-side__scroll scroll">
                <?php if ($SH_CAPS['write']): ?>
                <div class="fws-side__new">
                    <button class="fwm-compose-btn" type="button" data-new-list><svg class="i"><use href="#i-plus"/></svg><span>New list</span></button>
                </div>
                <?php endif; ?>
                <nav class="fwm-nav" aria-label="Shopping">
                    <?php foreach ($navItems as [$navKey, $navLabel, $navIcon, $navHref, $navCount]): $navOn = $page === $navKey; ?>
                        <a class="fwm-nav__item<?= $navOn ? ' is-active' : '' ?>" href="<?= $e($navHref) ?>"<?= $navOn ? ' aria-current="page"' : '' ?>>
                            <svg class="i"><use href="#<?= $e($navIcon) ?>"/></svg><span class="fwm-nav__label"><?= $e($navLabel) ?></span>
                            <?php if ($navCount !== null): ?><span class="fwm-nav__count"><?= (int)$navCount ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="fwm-nav__head"><h3>Open lists</h3></div>
                <nav class="fwm-nav fws-nav-lists" aria-label="Open lists" id="navLists">
                    <?php foreach ($SH_NAV['lists'] as $nl): $navOn = $activeListId === $nl['id']; ?>
                        <a class="fwm-nav__item<?= $navOn ? ' is-active' : '' ?>" href="/shopping/list.php?id=<?= (int)$nl['id'] ?>"<?= $navOn ? ' aria-current="page"' : '' ?> data-nav-list="<?= (int)$nl['id'] ?>">
                            <span class="fwm-nav__dot fws-dot<?= $nl['shopping'] ? ' is-live' : '' ?>" style="background:<?= $e(ShKit::PURPOSE_COLOR[$nl['purpose']] ?? '#7a1f30') ?>" aria-hidden="true"></span>
                            <span class="fwm-nav__label"><?= $e($nl['name']) ?></span>
                            <span class="fwm-nav__count" aria-label="<?= (int)$nl['left'] ?> to buy"><?= $nl['left'] ? (int)$nl['left'] : '✓' ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$SH_NAV['lists']): ?><div class="fwm-nav__empty">No open lists</div><?php endif; ?>
                </nav>
                <div class="fwm-nav__head"><h3>Elsewhere</h3></div>
                <nav class="fwm-nav" aria-label="Related">
                    <?php if ($SH_CAPS['order']): ?>
                    <a class="fwm-nav__item" href="/procurement/po_list.php"><svg class="i"><use href="#is-order"/></svg><span class="fwm-nav__label">Purchase orders</span><svg class="i i--xs" aria-label="Opens Procurement"><use href="#i-external"/></svg></a>
                    <?php endif; ?>
                    <a class="fwm-nav__item" href="/receipts/"><svg class="i"><use href="#i-receipt"/></svg><span class="fwm-nav__label">Receipts</span><svg class="i i--xs" aria-label="Opens Receipts"><use href="#i-external"/></svg></a>
                    <a class="fwm-nav__item" href="/crm/#suppliers"><svg class="i"><use href="#i-building"/></svg><span class="fwm-nav__label">Suppliers</span><svg class="i i--xs" aria-label="Opens the CRM"><use href="#i-external"/></svg></a>
                </nav>
            </div>
            <div class="fwm-side__foot">
                <a class="fws-side__plan" href="/shopping/prices.php">
                    <small><?= $e(date('F')) ?> in the trolley</small>
                    <b><?= $e(ShKit::money($SH_NAV['month'])) ?></b>
                    <small><?= $SH_NAV['month_items'] ? $e($SH_NAV['month_items'] . ' ' . ($SH_NAV['month_items'] === 1 ? 'thing' : 'things') . ' bought') : 'Nothing bought yet this month' ?><?= $SH_NAV['items'] ? ' · ' . (int)$SH_NAV['items'] . ' to buy' : '' ?></small>
                </a>
            </div>
        </aside>
        <main class="fwm-pane fws-main" id="main" tabindex="-1">
            <div class="fws-scroll scroll" id="scroll">
                <div class="fws-content">
