<?php
// /finances/partials/side.php — the Finance sidebar: create, the parts the company switched on, saved views and tools.
// On the Finance home the links are hash routes; on other pages they lead back to /finances/#route.
// Inputs: $boot, $sideActive (route key), $onHome (bool)
$base = ($onHome ?? false) ? '' : '/finances/';
$act = $sideActive ?? '';
$icons = [
    'overview' => 'if-overview', 'journals' => 'if-journal', 'ledger' => 'if-ledger', 'accounts' => 'if-tree',
    'bank' => 'if-bank', 'receivables' => 'if-in', 'payables' => 'if-out', 'vat' => 'if-vat', 'assets' => 'if-asset',
    'payroll' => 'if-payroll', 'budgets' => 'if-budget', 'reports' => 'if-report', 'close' => 'if-close',
];
$groups = [
    'Books' => ['overview', 'journals', 'ledger', 'accounts'],
    'Money in & out' => ['bank', 'receivables', 'payables'],
    'Tax & assets' => ['vat', 'assets', 'payroll'],
    'Plan & report' => ['budgets', 'reports', 'close'],
];
$on = [];
foreach ($boot['routes'] as $r) $on[$r['key']] = $r['label'];
?>
<aside class="fwm-side fwf-side" id="side" aria-label="<?= htmlspecialchars($boot['config']['labels']['finance']) ?>">
    <?php if ($boot['can_write']): ?>
    <div class="fwf-new">
        <button class="fwm-compose-btn" id="newBtn" type="button" data-new="journal"><svg class="i"><use href="#i-plus"/></svg><span id="newLabel">New <?= htmlspecialchars(strtolower($boot['config']['labels']['journal'])) ?></span></button>
        <button class="fwf-new__more" id="newMore" type="button" aria-label="Create something else" aria-haspopup="menu"><svg class="i"><use href="#i-chev-down"/></svg></button>
    </div>
    <?php endif; ?>
    <div class="fwm-side__scroll scroll">
        <?php foreach ($groups as $title => $keys): $keys = array_values(array_filter($keys, fn($k) => isset($on[$k]))); if (!$keys) continue; ?>
        <div class="fwm-nav__head"><h3><?= htmlspecialchars($title) ?></h3></div>
        <nav class="fwm-nav" aria-label="<?= htmlspecialchars($title) ?>">
            <?php foreach ($keys as $key): $count = $boot['counts'][$key] ?? null; ?>
            <a class="fwm-nav__item<?= $act === $key ? ' is-active' : '' ?>" href="<?= $base ?>#<?= $key ?>" data-route="<?= $key ?>"><svg class="i"><use href="#<?= $icons[$key] ?>"/></svg><span class="fwm-nav__label"><?= htmlspecialchars($on[$key]) ?></span><span class="fwm-nav__count<?= $count ? ' fwf-due' : '' ?>" data-count="<?= $key ?>"><?= $count ? (int)$count : '' ?></span></a>
            <?php endforeach; ?>
        </nav>
        <?php endforeach; ?>
        <div id="savedViews"<?= $boot['prefs']['views'] ? '' : ' hidden' ?>>
            <div class="fwm-nav__head"><h3>My views</h3></div>
            <nav class="fwm-nav" id="viewsNav" aria-label="Saved views">
                <?php foreach ($boot['prefs']['views'] as $v): ?>
                <a class="fwm-nav__item fwf-view-link" href="<?= $base ?>#<?= htmlspecialchars($v['route']) ?><?= $v['query'] !== '' ? '?' . htmlspecialchars($v['query']) : '' ?>" data-view="<?= htmlspecialchars($v['id']) ?>"><svg class="i"><use href="#i-bookmark"/></svg><span class="fwm-nav__label"><?= htmlspecialchars($v['name']) ?></span></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="fwm-nav__head"><h3>Tools</h3></div>
        <nav class="fwm-nav" aria-label="Tools">
            <a class="fwm-nav__item<?= $act === 'settings' ? ' is-active' : '' ?>" href="/finances/settings.php"><svg class="i"><use href="#if-sliders"/></svg><span class="fwm-nav__label">Make it yours</span></a>
        </nav>
    </div>
    <div class="fwm-side__foot">
        <span><kbd class="fwm-kbd">Ctrl K</kbd> search · <kbd class="fwm-kbd">?</kbd> shortcuts</span>
    </div>
</aside>
