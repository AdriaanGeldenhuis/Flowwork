<?php
// /crm/partials/side.php — the CRM sidebar: create, views (switched on in settings), saved views and tools.
// On the CRM home the links are hash routes; on other pages they lead back to /crm/#route.
// Inputs: $boot, $sideActive (route key), $onHome (bool)
$L = $boot['config']['labels'];
$mods = $boot['config']['modules'];
$base = ($onHome ?? false) ? '' : '/crm/';
$act = $sideActive ?? '';
$nav = [
    ['overview', 'ic-overview', 'Overview', null, true],
    ['customers', 'ic-customer', $L['customers'], $boot['counts']['customers'], $mods['customers']],
    ['suppliers', 'ic-supplier', $L['suppliers'], $boot['counts']['suppliers'], $mods['suppliers']],
    ['pipeline', 'i-kanban', $L['pipeline'], $boot['counts']['open_deals'] ?: null, $mods['pipeline']],
    ['tasks', 'i-check-square', $L['tasks'], $boot['counts']['tasks_due'] ?: null, $mods['tasks']],
    ['contacts', 'i-users', $L['contacts'], null, $mods['contacts']],
    ['activity', 'ic-activity', 'Activity', null, $mods['activity']],
    ['compliance', 'i-shield', 'Compliance', null, $mods['compliance'] && $mods['suppliers']],
];
$firstNew = $mods['customers'] ? 'customer' : 'supplier';
?>
<aside class="fwm-side fwc-side" id="side" aria-label="<?= htmlspecialchars($L['crm']) ?>">
    <?php if ($boot['can_write']): ?>
    <div class="fwc-new">
        <button class="fwm-compose-btn" id="newBtn" type="button" data-new="<?= $firstNew ?>"><svg class="i"><use href="#i-plus"/></svg><span id="newLabel">New <?= htmlspecialchars(strtolower($L[$firstNew])) ?></span></button>
        <button class="fwc-new__more" id="newMore" type="button" aria-label="Create something else" aria-haspopup="menu"><svg class="i"><use href="#i-chev-down"/></svg></button>
    </div>
    <?php endif; ?>
    <div class="fwm-side__scroll scroll">
        <nav class="fwm-nav" id="nav" aria-label="Views">
            <?php foreach ($nav as [$key, $icon, $label, $count, $on]): if (!$on) continue; ?>
            <a class="fwm-nav__item<?= $act === $key ? ' is-active' : '' ?>" href="<?= $base ?>#<?= $key ?>" data-route="<?= $key ?>"><svg class="i"><use href="#<?= $icon ?>"/></svg><span class="fwm-nav__label"><?= htmlspecialchars($label) ?></span><span class="fwm-nav__count<?= $key === 'tasks' && $count ? ' fwc-due' : '' ?>" data-count="<?= $key ?>"><?= $count !== null ? (int)$count : '' ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div id="savedViews"<?= $boot['prefs']['views'] ? '' : ' hidden' ?>>
            <div class="fwm-nav__head"><h3>My views</h3></div>
            <nav class="fwm-nav" id="viewsNav" aria-label="Saved views">
                <?php foreach ($boot['prefs']['views'] as $v): ?>
                <a class="fwm-nav__item fwc-view-link" href="<?= $base ?>#<?= htmlspecialchars($v['route']) ?><?= $v['query'] !== '' ? '?' . htmlspecialchars($v['query']) : '' ?>" data-view="<?= htmlspecialchars($v['id']) ?>"><svg class="i"><use href="#i-bookmark"/></svg><span class="fwm-nav__label"><?= htmlspecialchars($v['name']) ?></span></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="fwm-nav__head"><h3>Tools</h3></div>
        <nav class="fwm-nav" aria-label="Tools">
            <?php if ($boot['is_admin']): ?>
            <a class="fwm-nav__item<?= $act === 'import' ? ' is-active' : '' ?>" href="/crm/import.php"><svg class="i"><use href="#i-upload"/></svg><span class="fwm-nav__label">Import &amp; export</span></a>
            <a class="fwm-nav__item<?= $act === 'dedupe' ? ' is-active' : '' ?>" href="/crm/dedupe.php"><svg class="i"><use href="#ic-merge"/></svg><span class="fwm-nav__label">Duplicates</span></a>
            <?php endif; ?>
            <a class="fwm-nav__item<?= $act === 'settings' ? ' is-active' : '' ?>" href="/crm/settings.php"><svg class="i"><use href="#ic-sliders"/></svg><span class="fwm-nav__label">Make it yours</span></a>
        </nav>
    </div>
    <div class="fwm-side__foot">
        <span><kbd class="fwm-kbd">Ctrl K</kbd> search · <kbd class="fwm-kbd">?</kbd> shortcuts</span>
    </div>
</aside>
