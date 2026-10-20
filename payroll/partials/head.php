<?php
// /payroll/partials/head.php — <head>, the top bar and the sidebar shared by
// every Payroll page; opens the main pane (closed by foot.php).
// Built on the Maison design system: /mail/css/mail.css, then /payroll/css/payroll.css.
// Inputs: $page (nav key), $pageTitle, $PAY_COMPANY, $PAY_COMPANY_ID, $bare (optional: no sidebar)
$fwTheme = (($_COOKIE['fw_theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
$payV = fn(string $rel): string => (string)(@filemtime(__DIR__ . '/../../' . ltrim($rel, '/')) ?: '1');
$companyName = (string)($PAY_COMPANY['name'] ?? 'Your company');
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$bare = $bare ?? false;

$navCounts = ['runs' => null, 'employees' => null];
$nextPay = null;
if (!$bare) {
    try {
        $st = $DB->prepare("SELECT COUNT(*) FROM pay_runs WHERE company_id = ? AND status IN ('draft','calculated','review','approved')");
        $st->execute([$PAY_COMPANY_ID]);
        $navCounts['runs'] = (int)$st->fetchColumn() ?: null;
        $st = $DB->prepare("SELECT COUNT(*) FROM employees WHERE company_id = ? AND (termination_date IS NULL OR termination_date >= CURDATE())");
        $st->execute([$PAY_COMPANY_ID]);
        $navCounts['employees'] = (int)$st->fetchColumn();
        $nextPay = PayKit::suggest($DB, $PAY_COMPANY_ID, $PAY_SETTINGS['default_frequency'], $PAY_SETTINGS);
        $st = $DB->prepare("SELECT pay_date, name, status FROM pay_runs WHERE company_id = ? AND status IN ('draft','calculated','review','approved') ORDER BY pay_date LIMIT 1");
        $st->execute([$PAY_COMPANY_ID]);
        if ($open = $st->fetch(PDO::FETCH_ASSOC)) $nextPay = ['pay_date' => $open['pay_date'], 'name' => $open['name'], 'open' => true];
    } catch (Throwable $ex) {
        error_log('[payroll] nav: ' . $ex->getMessage());
    }
}
$nav = [
    'index' => ['Overview', 'if-overview', '/payroll/'],
    'runs' => ['Pay runs', 'ip-run', '/payroll/runs.php'],
    'employees' => ['Employees', 'i-users', '/payroll/employees.php'],
    'payitems' => ['Pay items', 'i-list', '/payroll/payitems.php'],
    'reports' => ['SARS & reports', 'ip-sars', '/payroll/reports.php'],
    'settings' => ['Settings', 'i-settings', '/payroll/settings.php'],
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
    <title><?= $e($pageTitle) ?> · Payroll · <?= $e($companyName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $payV('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/payroll/css/payroll.css?v=<?= $payV('payroll/css/payroll.css') ?>">
</head>
<body class="fwm fwp" data-theme="<?= $fwTheme ?>" data-page="<?= $e($page) ?>">
<?php include __DIR__ . '/../../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/../../projects/partials/icons.php'; ?>
<?php include __DIR__ . '/../../finances/partials/icons.php'; ?>
<?php include __DIR__ . '/../../home/partials/icons.php'; ?>
<?php include __DIR__ . '/icons.php'; ?>
<div class="fwm-app fwp-app">
    <header class="fwm-top">
        <?php if (!$bare): ?>
        <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open the payroll menu"><svg class="i"><use href="#i-menu"/></svg></button>
        <?php endif; ?>
        <a class="fwm-brand" href="/payroll/" aria-label="Payroll — overview">
            <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#ip-seal"/></svg></span>
            <span class="fwm-brand__text">
                <span class="fwm-brand__name">Payroll</span>
                <span class="fwm-brand__company"><?= $e($companyName) ?></span>
            </span>
        </a>

        <?php if (!$bare): ?>
        <div class="fwm-search fwp-jump" role="search">
            <label class="fwm-search__box">
                <svg class="i"><use href="#i-search"/></svg>
                <span class="sr-only">Find a person or run</span>
                <input type="search" id="jumpInput" placeholder="Find a person or a pay run" autocomplete="off" spellcheck="false" enterkeyhint="go"
                       role="combobox" aria-expanded="false" aria-controls="jumpList" aria-autocomplete="list">
                <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
            </label>
            <div class="fwp-jump__list" id="jumpList" role="listbox" aria-label="People and pay runs" hidden></div>
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
        <aside class="fwm-side fwp-side" id="side" aria-label="Payroll sections">
            <div class="fwm-side__scroll scroll">
                <div style="padding:2px 4px 12px">
                    <a class="fwm-compose-btn" href="/payroll/runs.php?new=1"><svg class="i"><use href="#i-plus"/></svg><span>New pay run</span></a>
                </div>
                <nav class="fwm-nav" aria-label="Payroll">
                    <?php foreach ($nav as $key => [$label, $icon, $href]):
                        $count = $navCounts[$key] ?? null; ?>
                        <a class="fwm-nav__item<?= $page === $key ? ' is-active' : '' ?>" href="<?= $e($href) ?>"<?= $page === $key ? ' aria-current="page"' : '' ?>>
                            <svg class="i"><use href="#<?= $e($icon) ?>"/></svg><span class="fwm-nav__label"><?= $e($label) ?></span>
                            <?php if ($count !== null): ?><span class="fwm-nav__count"><?= (int)$count ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="fwm-nav__head"><h3>Elsewhere</h3></div>
                <nav class="fwm-nav" aria-label="Related">
                    <a class="fwm-nav__item" href="/finances/"><svg class="i"><use href="#if-ledger"/></svg><span class="fwm-nav__label">Finance</span><svg class="i i--xs" aria-label="Opens Finance"><use href="#i-external"/></svg></a>
                    <?php if (PayKit::canConfigure($PAY_ROLE)): ?>
                    <a class="fwm-nav__item" href="/admin/payroll.php"><svg class="i"><use href="#i-shield"/></svg><span class="fwm-nav__label">Admin</span><svg class="i i--xs" aria-label="Opens Admin"><use href="#i-external"/></svg></a>
                    <?php endif; ?>
                </nav>
            </div>
            <?php if ($nextPay): ?>
            <div class="fwm-side__foot">
                <a class="fwp-side__plan" href="<?= !empty($nextPay['open']) ? '/payroll/runs.php' : '/payroll/runs.php?new=1' ?>">
                    <span class="fwp-side__row"><small>Next pay day</small></span>
                    <b><?= $e(date('l j F', strtotime($nextPay['pay_date']))) ?></b>
                    <small><?= !empty($nextPay['open']) ? $e($nextPay['name']) . ' is being prepared' : 'Start the ' . $e($nextPay['name']) . ' run' ?></small>
                </a>
            </div>
            <?php endif; ?>
        </aside>
        <?php endif; ?>
        <main class="fwm-pane fwp-main" id="main" tabindex="-1">
            <div class="fwp-scroll scroll" id="scroll">
                <div class="fwp-content">
