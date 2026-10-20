<?php
// /admin/partials/head.php — <head>, the top bar and the sidebar shared by
// every admin page; opens the main pane (closed by foot.php).
// Built on the Maison design system: /mail/css/mail.css, then /admin/css/admin.css.
// Inputs: $page (nav key), $pageTitle, $ADMIN_COMPANY, $ADMIN_COMPANY_ID, $bare (optional: no sidebar)
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$adminV = fn(string $rel): string => (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
$companyName = (string)($ADMIN_COMPANY['name'] ?? 'Your company');
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$bare = $bare ?? false;

// Live counts for the sidebar (cheap, indexed)
$navCounts = ['users' => null, 'invites' => null];
$seatInfo = ['used' => 0, 'max' => 1];
if (!$bare) {
    try {
        $st = $DB->prepare("SELECT COUNT(*) FROM user_companies WHERE company_id = ?");
        $st->execute([$ADMIN_COMPANY_ID]);
        $navCounts['users'] = (int)$st->fetchColumn();
        $st = $DB->prepare("SELECT COUNT(*) FROM invites WHERE company_id = ? AND used = 0 AND expires_at > NOW()");
        $st->execute([$ADMIN_COMPANY_ID]);
        $navCounts['invites'] = (int)$st->fetchColumn() ?: null;
        $seatInfo = AdminKit::seats($DB, $ADMIN_COMPANY_ID);
    } catch (Throwable $ex) {
        error_log('[admin] nav counts: ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <meta name="robots" content="noindex">
    <title><?= $e($pageTitle) ?> · Admin · <?= $e($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $adminV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/admin/css/admin.css?v=<?= $adminV('admin/css/admin.css') ?>">
</head>
<body class="fwm fwa" data-theme="<?= $fwTheme ?>" data-page="<?= $e($page) ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/../../qi/partials/icons.php'; ?>
<?php include __DIR__ . '/../../finances/partials/icons.php'; ?>
<?php include __DIR__ . '/../../home/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
<div class="fwm-app fwa-app">
    <header class="fwm-top">
        <?php if (!$bare): ?>
        <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open the admin menu"><svg class="i"><use href="#i-menu"/></svg></button>
        <?php endif; ?>
        <a class="fwm-brand" href="/admin/" aria-label="Admin — overview">
            <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#i-shield"/></svg></span>
            <span class="fwm-brand__text">
                <span class="fwm-brand__name">Admin</span>
                <span class="fwm-brand__company"><?= $e($companyName) ?></span>
            </span>
        </a>

        <?php if (!$bare): ?>
        <div class="fwm-search fwa-jump" role="search">
            <label class="fwm-search__box">
                <svg class="i"><use href="#i-search"/></svg>
                <span class="sr-only">Find a setting</span>
                <input type="search" id="jumpInput" placeholder="Find a setting — try “VAT”, “password” or “pay day”" autocomplete="off" spellcheck="false" enterkeyhint="go"
                       role="combobox" aria-expanded="false" aria-controls="jumpList" aria-autocomplete="list">
                <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
            </label>
            <div class="fwa-jump__list" id="jumpList" role="listbox" aria-label="Settings" hidden></div>
        </div>
        <?php endif; ?>

        <div class="fwm-top__actions">
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="<?= $fwTheme === 'dark' ? 'Light theme' : 'Dark theme' ?>">
                <svg class="i"><use href="#<?= $fwTheme === 'dark' ? 'i-sun' : 'i-moon' ?>"/></svg>
            </button>
            <a class="icon-btn hide-narrow" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
            <button class="icon-btn" id="kebabBtn" type="button" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" data-tip="Menu" data-tip-pos="left"><svg class="i"><use href="#i-more-v"/></svg></button>
        </div>
    </header>

    <div class="fwm-body">
        <?php if (!$bare): ?>
        <aside class="fwm-side fwa-side" id="side" aria-label="Admin sections">
            <div class="fwm-side__scroll scroll">
                <?php foreach (AdminKit::nav() as $group => $items): ?>
                    <?php if ($group !== ''): ?><div class="fwm-nav__head"><h3><?= $e($group) ?></h3></div><?php endif; ?>
                    <nav class="fwm-nav" aria-label="<?= $e($group ?: 'Overview') ?>">
                        <?php foreach ($items as $it):
                            [$key, $label, $icon] = $it;
                            $href = $it[5] ?? '/admin/' . ($key === 'index' ? '' : $key . '.php');
                            $out = !empty($it[6]);
                            $count = $navCounts[$key] ?? null; ?>
                            <a class="fwm-nav__item<?= $page === $key ? ' is-active' : '' ?>" href="<?= $e($href) ?>"<?= $page === $key ? ' aria-current="page"' : '' ?>>
                                <svg class="i"><use href="#<?= $e($icon) ?>"/></svg><span class="fwm-nav__label"><?= $e($label) ?></span>
                                <?php if ($out): ?><svg class="i i--xs" aria-label="Opens the module"><use href="#i-external"/></svg>
                                <?php elseif ($count !== null): ?><span class="fwm-nav__count"><?= (int)$count ?></span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                <?php endforeach; ?>
            </div>
            <div class="fwm-side__foot">
                <a class="fwa-side__plan" href="/admin/billing.php">
                    <span class="fwa-side__row"><b><?= $e($ADMIN_COMPANY['plan_name'] ?? 'Plan') ?></b><small>plan</small></span>
                    <span class="fwa-meter<?= $seatInfo['used'] >= $seatInfo['max'] ? ' is-full' : '' ?>"><i style="--p:<?= min(100, round($seatInfo['used'] / max(1, $seatInfo['max']) * 100)) ?>%"></i></span>
                    <small><?= (int)$seatInfo['used'] ?> of <?= (int)$seatInfo['max'] ?> seats in use</small>
                </a>
            </div>
        </aside>
        <?php endif; ?>
        <main class="fwm-pane fwa-main" id="main" tabindex="-1">
            <div class="fwa-scroll scroll" id="scroll">
                <div class="fwa-content">
