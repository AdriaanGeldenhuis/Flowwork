<?php
// /suppliers_ai/partials/head.php — <head>, the top bar and the sidebar shared by
// every Suppliers AI page; opens the main pane (closed by foot.php).
// Built on the Maison design system: /mail/css/mail.css, then /suppliers_ai/css/suppliers_ai.css.
// Inputs: $page (nav key), $pageTitle, $activeRequestId (optional), $bodyClass (optional), $SA_* from SaBoot
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$saV = fn(string $rel): string => (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
$companyName = (string)($SA_COMPANY['name'] ?? 'Your company');
$activeRequestId = (int)($activeRequestId ?? 0);
$bodyClass = $bodyClass ?? '';
$SA_COUNTS = SaRequests::counts($DB, $SA_COMPANY_ID, $SA_USER_ID);
$SA_NAV = array_slice(SaRequests::list($DB, $SA_COMPANY_ID, $SA_USER_ID, ['status' => 'active']), 0, 7);
$SA_QUOTA = SaKit::quota($DB, $SA_COMPANY_ID, SaKit::settings($DB, $SA_COMPANY_ID));
$navItems = [
    ['index', 'Find suppliers', 'isa-seal', '/suppliers_ai/', null],
    ['requests', 'Requests for quotes', 'isa-quote', '/suppliers_ai/requests.php', $SA_COUNTS['active'] ?: null],
    ['history', 'Searches', 'i-clock', '/suppliers_ai/history.php', null],
];
if ($SA_CAPS['configure']) $navItems[] = ['settings', 'Settings', 'i-settings', '/suppliers_ai/settings.php', null];
$navItems[] = ['help', 'How it works', 'i-info', '/suppliers_ai/help.php', null];
$statusColor = ['draft' => '#9a6512', 'open' => '#2f5c84', 'awarded' => '#2f7457', 'closed' => '#85766b', 'cancelled' => '#85766b'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <meta name="robots" content="noindex">
    <title><?= $e($pageTitle) ?> · Suppliers AI · <?= $e($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $saV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/suppliers_ai/css/suppliers_ai.css?v=<?= $saV('suppliers_ai/css/suppliers_ai.css') ?>">
</head>
<body class="fwm fsa <?= $e($bodyClass) ?>" data-theme="<?= $fwTheme ?>" data-page="<?= $e($page) ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/../../home/partials/icons.php'; ?>
<?php include __DIR__ . '/../../shopping/partials/icons.php'; ?>
<?php include __DIR__ . '/../../crm/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
<a class="fsa-skip" href="#main">Skip to the content</a>
<div class="fwm-app fsa-app">
    <header class="fwm-top">
        <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open the Suppliers AI menu"><svg class="i"><use href="#i-menu"/></svg></button>
        <a class="fwm-brand" href="/suppliers_ai/" aria-label="Suppliers AI — find suppliers">
            <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#isa-seal"/></svg></span>
            <span class="fwm-brand__text">
                <span class="fwm-brand__name">Suppliers AI</span>
                <span class="fwm-brand__company"><?= $e($companyName) ?></span>
            </span>
        </a>

        <div class="fwm-search fsa-jump" role="search">
            <label class="fwm-search__box">
                <svg class="i"><use href="#i-search"/></svg>
                <span class="sr-only">Find a request, a supplier or a search</span>
                <input type="search" id="jumpInput" placeholder="Find a request, a supplier or a search" autocomplete="off" spellcheck="false" enterkeyhint="search"
                       role="combobox" aria-expanded="false" aria-controls="jumpList" aria-autocomplete="list">
                <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
            </label>
            <div class="fsa-jump__list" id="jumpList" role="listbox" aria-label="Requests, suppliers, searches and pages" hidden></div>
        </div>

        <div class="fwm-top__actions">
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="<?= $fwTheme === 'dark' ? 'Light theme' : 'Dark theme' ?>">
                <svg class="i"><use href="#<?= $fwTheme === 'dark' ? 'i-sun' : 'i-moon' ?>"/></svg>
            </button>
            <a class="icon-btn hide-narrow" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
            <button class="icon-btn" id="kebabBtn" type="button" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" data-tip="Menu" data-tip-pos="left"><svg class="i"><use href="#i-more-v"/></svg></button>
        </div>
    </header>

    <div class="fwm-body">
        <aside class="fwm-side fsa-side" id="side" aria-label="Suppliers AI sections">
            <div class="fwm-side__scroll scroll">
                <?php if ($SA_CAPS['search']): ?>
                <div class="fsa-side__new">
                    <a class="fwm-compose-btn" href="/suppliers_ai/#find"><svg class="i"><use href="#isa-seal"/></svg><span>New search</span></a>
                </div>
                <?php endif; ?>
                <nav class="fwm-nav" aria-label="Suppliers AI">
                    <?php foreach ($navItems as [$navKey, $navLabel, $navIcon, $navHref, $navCount]): $navOn = $page === $navKey; ?>
                        <a class="fwm-nav__item<?= $navOn ? ' is-active' : '' ?>" href="<?= $e($navHref) ?>"<?= $navOn ? ' aria-current="page"' : '' ?>>
                            <svg class="i"><use href="#<?= $e($navIcon) ?>"/></svg><span class="fwm-nav__label"><?= $e($navLabel) ?></span>
                            <?php if ($navCount !== null): ?><span class="fwm-nav__count"><?= (int)$navCount ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="fwm-nav__head"><h3>Waiting for quotes</h3></div>
                <nav class="fwm-nav fsa-nav-req" aria-label="Open requests">
                    <?php foreach ($SA_NAV as $nr): $navOn = $activeRequestId === $nr['id']; ?>
                        <a class="fwm-nav__item<?= $navOn ? ' is-active' : '' ?>" href="<?= $e($nr['url']) ?>"<?= $navOn ? ' aria-current="page"' : '' ?>>
                            <span class="fwm-nav__dot<?= $nr['late'] ? ' is-late' : '' ?>" style="background:<?= $e($nr['trade']['color'] ?? $statusColor[$nr['status']]) ?>" aria-hidden="true"></span>
                            <span class="fwm-nav__label"><?= $e($nr['title']) ?></span>
                            <span class="fwm-nav__count" aria-label="<?= (int)$nr['quoted'] ?> quotes"><?= $nr['status'] === 'draft' ? '·' : (int)$nr['quoted'] . '/' . (int)$nr['suppliers'] ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$SA_NAV): ?><div class="fwm-nav__empty">Nothing waiting</div><?php endif; ?>
                </nav>
                <div class="fwm-nav__head"><h3>Elsewhere</h3></div>
                <nav class="fwm-nav" aria-label="Related">
                    <a class="fwm-nav__item" href="/crm/#suppliers"><svg class="i"><use href="#i-building"/></svg><span class="fwm-nav__label">Suppliers in the CRM</span><svg class="i i--xs" aria-label="Opens the CRM"><use href="#i-external"/></svg></a>
                    <?php if ($SA_CAPS['order']): ?>
                    <a class="fwm-nav__item" href="/procurement/po_list.php"><svg class="i"><use href="#is-order"/></svg><span class="fwm-nav__label">Purchase orders</span><svg class="i i--xs" aria-label="Opens Procurement"><use href="#i-external"/></svg></a>
                    <?php endif; ?>
                    <a class="fwm-nav__item" href="/mail/"><svg class="i"><use href="#i-mail"/></svg><span class="fwm-nav__label">Mail</span><svg class="i i--xs" aria-label="Opens Mail"><use href="#i-external"/></svg></a>
                </nav>
            </div>
            <div class="fwm-side__foot">
                <<?= $SA_CAPS['configure'] ? 'a href="/suppliers_ai/settings.php"' : 'div' ?> class="fsa-side__sources">
                    <small>Where suppliers come from</small>
                    <span class="fsa-src is-on"><svg class="i i--xs"><use href="#i-building"/></svg>Your CRM</span>
                    <span class="fsa-src<?= $SA_SETTINGS['google_ready'] ? ' is-on' : '' ?>"><svg class="i i--xs"><use href="#i-globe"/></svg>Google<?= $SA_SETTINGS['google_ready'] ? '' : ' · off' ?></span>
                    <span class="fsa-src<?= $SA_SETTINGS['ai_ready'] ? ' is-on' : '' ?>"><svg class="i i--xs"><use href="#i-sparkle"/></svg>AI<?= $SA_SETTINGS['ai_ready'] ? '' : ' · off' ?></span>
                    <?php if ($SA_SETTINGS['google_ready'] || $SA_SETTINGS['ai_ready']): ?>
                    <small><?= (int)$SA_QUOTA['day'] ?> of <?= (int)$SA_QUOTA['day_cap'] ?> look-ups used today</small>
                    <?php endif; ?>
                </<?= $SA_CAPS['configure'] ? 'a' : 'div' ?>>
            </div>
        </aside>
        <main class="fwm-pane fsa-main" id="main" tabindex="-1">
            <div class="fsa-scroll scroll" id="scroll">
                <div class="fsa-content">
