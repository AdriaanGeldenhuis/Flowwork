<?php
// /qi/partials/topbar.php — Quotes & Invoices top bar (same chrome as Mail, Calendar and Projects).
// Inputs: $companyName, $withSearch (bool), $withDrawer (bool, default true)
?>
<header class="fwm-top">
    <?php if ($withDrawer ?? true): ?>
    <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open navigation"><svg class="i"><use href="#i-menu"/></svg></button>
    <?php endif; ?>
    <a class="fwm-brand" href="/qi/" aria-label="Quotes & Invoices — overview">
        <span class="fwm-brand__tile" aria-hidden="true"><svg class="i"><use href="#iq-seal"/></svg></span>
        <span class="fwm-brand__text">
            <span class="fwm-brand__name">Quotes &amp; Invoices</span>
            <span class="fwm-brand__company"><?= htmlspecialchars($companyName) ?></span>
        </span>
    </a>

    <?php if (!empty($withSearch)): ?>
    <form class="fwm-search" id="searchForm" role="search" autocomplete="off">
        <label class="fwm-search__box">
            <svg class="i"><use href="#i-search"/></svg>
            <span class="sr-only">Search quotes, invoices and customers</span>
            <input type="search" id="searchInput" placeholder="Search numbers, customers, references" enterkeyhint="search" spellcheck="false">
            <button type="button" class="icon-btn icon-btn--sm" id="searchClear" aria-label="Clear search" hidden><svg class="i i--sm"><use href="#i-x"/></svg></button>
            <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
        </label>
    </form>
    <?php endif; ?>

    <div class="fwm-top__actions">
        <a class="icon-btn hide-narrow" href="/qi/designer.php" aria-label="Document designer" data-tip="Designer"><svg class="i"><use href="#iq-designer"/></svg></a>
        <a class="icon-btn hide-narrow" href="/qi/settings.php" aria-label="Quotes & Invoices settings" data-tip="Settings"><svg class="i"><use href="#i-settings"/></svg></a>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="Theme">
            <svg class="i"><use href="#i-moon"/></svg>
        </button>
        <a class="icon-btn" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
        <button class="icon-btn" id="kebabBtn" type="button" aria-label="More" aria-haspopup="menu"><svg class="i"><use href="#i-more-v"/></svg></button>
    </div>
</header>
