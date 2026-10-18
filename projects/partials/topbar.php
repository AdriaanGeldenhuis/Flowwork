<?php
// /projects/partials/topbar.php — Projects top bar (same chrome as Mail and Calendar).
// Inputs: $companyName, $isAdmin, $withSearch (bool), $searchPlaceholder (optional)
?>
<header class="fwm-top">
    <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open navigation"><svg class="i"><use href="#i-menu"/></svg></button>
    <a class="fwm-brand" href="/projects/" aria-label="Projects — home">
        <span class="fwm-brand__tile fwp-seal" aria-hidden="true">
            <svg class="i" viewBox="0 0 24 24"><use href="#i-seal-board"/></svg>
        </span>
        <span class="fwm-brand__text">
            <span class="fwm-brand__name">Projects</span>
            <span class="fwm-brand__company"><?= htmlspecialchars($companyName) ?></span>
        </span>
    </a>

    <?php if (!empty($withSearch)): ?>
    <form class="fwm-search" id="searchForm" role="search" autocomplete="off">
        <label class="fwm-search__box">
            <svg class="i"><use href="#i-search"/></svg>
            <span class="sr-only"><?= htmlspecialchars($searchPlaceholder ?? 'Search projects, boards and items') ?></span>
            <input type="search" id="searchInput" placeholder="<?= htmlspecialchars($searchPlaceholder ?? 'Search projects, boards and items') ?>" enterkeyhint="search" spellcheck="false">
            <button type="button" class="icon-btn icon-btn--sm" id="searchClear" aria-label="Clear search" hidden><svg class="i i--sm"><use href="#i-x"/></svg></button>
            <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
        </label>
    </form>
    <?php endif; ?>

    <div class="fwm-top__actions">
        <a class="icon-btn hide-narrow" href="/projects/#mywork" aria-label="My work" data-tip="My work"><svg class="i"><use href="#i-check-square"/></svg></a>
        <a class="icon-btn hide-narrow" href="/calendar/" aria-label="Calendar" data-tip="Calendar"><svg class="i"><use href="#i-calendar"/></svg></a>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="Theme">
            <svg class="i"><use href="#i-moon"/></svg>
        </button>
        <a class="icon-btn hide-narrow" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
        <button class="icon-btn" id="kebabBtn" type="button" aria-label="More" aria-haspopup="menu"
                data-admin="<?= !empty($isAdmin) ? '1' : '0' ?>"><svg class="i"><use href="#i-more-v"/></svg></button>
    </div>
</header>
