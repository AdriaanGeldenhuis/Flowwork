<?php
// /shopping/share.php?t= — a shopping list for someone with the link and no
// Flowwork account: the items by aisle, ticked off on their phone (and added
// to, when the link allows it). They type their name once so everyone knows
// who ticked what. No prices or budget are shown. A link that ran out or was
// stopped gets a friendly page, never the list.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/lib/Sh.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
ShSchema::ensure($DB);
$token = (string)($_GET['t'] ?? '');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$share = ShKit::rateLimit('share:page:' . $ip, 120, 60) > 0 ? null : ShShare::resolve($DB, $token);
$view = $share ? ShShare::view($DB, $share) : null;
if (!$view) http_response_code(404);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fwTheme = (($_COOKIE['fw_theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$v = fn(string $rel): string => (string)(@filemtime(__DIR__ . '/../' . ltrim($rel, '/')) ?: '1');
$boot = $view ? ['token' => $token, 'view' => $view, 'categories' => array_merge(array_map(fn($c) => ['label' => $c[0], 'icon' => $c[1], 'color' => $c[2]], ShParse::CATEGORIES), ['other' => ['label' => ShParse::OTHER[0], 'icon' => ShParse::OTHER[1], 'color' => ShParse::OTHER[2]]])] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $e(Csrf::token()) ?>">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="<?= $fwTheme === 'dark' ? '#0d0a09' : '#f2ece3' ?>">
    <title><?= $view ? $e($view['list']['name']) . ' · Shopping list' : 'Shopping list' ?></title>
    <?php include __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mail/css/mail.css?v=<?= $v('mail/css/mail.css') ?>">
    <link rel="stylesheet" href="/shopping/css/shopping.css?v=<?= $v('shopping/css/shopping.css') ?>">
</head>
<body class="fwm fws fws-guest" data-mod="shopping" data-theme="<?= $fwTheme ?>" data-page="share">
<?php include __DIR__ . '/../mail/partials/icons.php'; ?>
<?php include __DIR__ . '/../calendar/partials/icons.php'; ?>
<?php include __DIR__ . '/partials/icons.php'; ?>
<div class="fws-guest__app">
    <header class="fws-guest__top">
        <span class="fwm-brand">
            <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#is-cart"/></svg></span>
            <span class="fwm-brand__text"><span class="fwm-brand__name">Shopping</span><span class="fwm-brand__company"><?= $view ? $e($view['company']) : 'Flowwork' ?></span></span>
        </span>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme"><svg class="i"><use href="#<?= $fwTheme === 'dark' ? 'i-sun' : 'i-moon' ?>"/></svg></button>
    </header>
    <main class="fws-scroll scroll" id="main">
        <div class="fws-content">
        <?php if (!$view): ?>
            <div class="fwm-empty">
                <span class="fwm-empty__art" aria-hidden="true"><svg class="i"><use href="#i-link"/></svg></span>
                <p class="fwm-empty__eyebrow">Shopping list</p>
                <h3>This link no longer works</h3>
                <p>It ran out or was stopped. Ask whoever sent it for a new one.</p>
            </div>
        <?php else: $L = $view['list']; ?>
            <header class="fws-listhead">
                <p class="fws-eyebrow"><?= $e(ShKit::PURPOSES[$L['purpose']] ?? 'Shopping list') ?> <i>◆</i> from <?= $e($L['owner']) ?></p>
                <h1 id="listName"><?= $e($L['name']) ?></h1>
                <div class="fws-meta">
                    <?php if ($L['needed_by']): ?><span class="fws-tag"><svg class="i i--xs"><use href="#i-calendar"/></svg>Needed <?= $e(ShKit::due((string)$L['needed_by'])) ?></span><?php endif; ?>
                    <?php if ($L['notes'] !== ''): ?><span class="fws-tag fws-tag--note"><?= $e($L['notes']) ?></span><?php endif; ?>
                </div>
            </header>
            <?php if ($view['can_check'] || $view['can_edit']): ?>
            <label class="fws-guest__name"><svg class="i i--sm"><use href="#i-user"/></svg><span>Your name</span><input class="input" id="guestName" maxlength="60" placeholder="So they know who ticked it" autocomplete="name"></label>
            <?php endif; ?>
            <div class="fws-trolley" id="trolley"></div>
            <?php if ($view['can_edit']): ?>
            <form class="fws-add" id="addForm" autocomplete="off">
                <span class="fws-add__icon" aria-hidden="true"><svg class="i"><use href="#i-plus"/></svg></span>
                <label class="sr-only" for="addText">Add items</label>
                <textarea class="fws-add__text" id="addText" rows="1" placeholder="Add something — one a line"></textarea>
                <button class="btn btn--primary btn--sm" type="submit">Add</button>
            </form>
            <?php endif; ?>
            <div class="fws-groups" id="groups" aria-live="polite"></div>
            <p class="fws-guest__note">Shared from Flowwork by <?= $e($L['owner']) ?>. This link works until <?= $e(ShKit::date((string)$view['expires_at'])) ?>.<br>Ticks show up for them as you go.</p>
        <?php endif; ?>
        </div>
    </main>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<?php if ($view): ?>
<script id="shBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/shopping/js/shopping.js?v=<?= $v('shopping/js/shopping.js') ?>"></script>
<script src="/shopping/js/share.js?v=<?= $v('shopping/js/share.js') ?>"></script>
<?php else: ?>
<script src="/shopping/js/shopping.js?v=<?= $v('shopping/js/shopping.js') ?>"></script>
<?php endif; ?>
</body>
</html>
