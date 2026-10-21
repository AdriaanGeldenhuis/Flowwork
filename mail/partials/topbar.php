<?php
// /mail/partials/topbar.php — Mail top bar.
// Inputs: $companyName, $isAdmin, $withSearch (bool), $withSync (bool)
?>
<header class="fwm-top">
    <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open folders"><svg class="i"><use href="#i-menu"/></svg></button>
    <a class="fwm-brand" href="/mail/" aria-label="Mail inbox">
        <span class="fwm-brand__tile"><svg class="i"><use href="#i-mail"/></svg></span>
        <span class="fwm-brand__text">
            <span class="fwm-brand__name">Mail</span>
            <span class="fwm-brand__company"><?= htmlspecialchars($companyName) ?></span>
        </span>
    </a>

    <?php if (!empty($withSearch)): ?>
    <form class="fwm-search" id="searchForm" role="search" autocomplete="off">
        <label class="fwm-search__box">
            <svg class="i"><use href="#i-search"/></svg>
            <span class="sr-only">Search mail</span>
            <input type="search" id="searchInput" placeholder="Search mail" enterkeyhint="search" spellcheck="false">
            <button type="button" class="icon-btn icon-btn--sm" id="searchClear" aria-label="Clear search" hidden><svg class="i i--sm"><use href="#i-x"/></svg></button>
            <button type="button" class="icon-btn icon-btn--sm hide-narrow" id="searchHelp" aria-label="Search tips" data-tip="Search tips"><svg class="i i--sm"><use href="#i-filter"/></svg></button>
            <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
        </label>
    </form>
    <?php endif; ?>

    <div class="fwm-top__actions">
        <?php if (!empty($withSync)): ?>
        <button class="icon-btn" id="syncBtn" type="button" aria-label="Check for new mail" data-tip="Check for new mail">
            <svg class="i"><use href="#i-refresh"/></svg>
            <span class="fwm-sync-dot" id="syncDot" hidden></span>
        </button>
        <?php endif; ?>
        <a class="icon-btn hide-narrow" href="/mail/settings.php" aria-label="Mail settings" data-tip="Settings"><svg class="i"><use href="#i-settings"/></svg></a>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="Theme">
            <svg class="i fwm-theme-dark"><use href="#i-moon"/></svg>
        </button>
        <a class="icon-btn" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
        <button class="icon-btn" id="kebabBtn" type="button" aria-label="More" aria-haspopup="menu"
                data-admin="<?= !empty($isAdmin) ? '1' : '0' ?>"><svg class="i"><use href="#i-more-v"/></svg></button>
    </div>
</header>
