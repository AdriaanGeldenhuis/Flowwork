<?php
// /finances/partials/topbar.php — Finance top bar (same chrome as Mail, Calendar, Projects, Quotes & Invoices and the CRM).
// Inputs: $boot, $withDrawer (bool, default true)
$L = $boot['config']['labels'];
?>
<header class="fwm-top">
    <?php if ($withDrawer ?? true): ?>
    <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open navigation"><svg class="i"><use href="#i-menu"/></svg></button>
    <?php endif; ?>
    <a class="fwm-brand" href="/finances/" aria-label="<?= htmlspecialchars($L['finance']) ?> — overview">
        <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#if-seal"/></svg></span>
        <span class="fwm-brand__text">
            <span class="fwm-brand__name"><?= htmlspecialchars($L['finance']) ?></span>
            <span class="fwm-brand__company"><?= htmlspecialchars($boot['company']) ?></span>
        </span>
    </a>

    <div class="fwm-search fwf-search">
        <button class="fwm-search__box fwf-search__btn" id="paletteBtn" type="button" aria-label="Search Finance (Ctrl K)">
            <svg class="i"><use href="#i-search"/></svg>
            <span class="fwf-search__ph">Search accounts, journals, invoices, <?= htmlspecialchars(strtolower($L['bills'])) ?>…</span>
            <kbd class="fwm-kbd hide-narrow" aria-hidden="true">Ctrl K</kbd>
        </button>
    </div>

    <div class="fwm-top__actions">
        <?php if ($boot['can_write']): ?>
        <button class="icon-btn" id="quickNew" type="button" aria-label="Create" aria-haspopup="menu" data-tip="Create"><svg class="i"><use href="#i-plus"/></svg></button>
        <?php endif; ?>
        <a class="icon-btn hide-narrow" href="/finances/settings.php" aria-label="Make it yours — Finance settings" data-tip="Make it yours"><svg class="i"><use href="#if-sliders"/></svg></a>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="Theme"><svg class="i"><use href="#i-moon"/></svg></button>
        <a class="icon-btn" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
        <button class="icon-btn" id="kebabBtn" type="button" aria-label="More" aria-haspopup="menu"><svg class="i"><use href="#i-more-v"/></svg></button>
    </div>
</header>
