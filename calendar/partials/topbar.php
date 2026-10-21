<?php
// /calendar/partials/topbar.php — Calendar top bar (same chrome as Mail).
// Inputs: $companyName, $isAdmin, $withSearch (bool)
$sealDate = new DateTimeImmutable('now', CalendarRepo::companyTz($DB, (int)$_SESSION['company_id']));
?>
<header class="fwm-top">
    <button class="icon-btn only-narrow" id="drawerBtn" type="button" aria-label="Open calendars"><svg class="i"><use href="#i-menu"/></svg></button>
    <a class="fwm-brand" href="/calendar/" aria-label="Calendar — today">
        <span class="fwm-brand__tile fwc-seal" aria-hidden="true">
            <span class="fwc-seal__month"><?= htmlspecialchars($sealDate->format('M')) ?></span>
            <span class="fwc-seal__day"><?= (int)$sealDate->format('j') ?></span>
        </span>
        <span class="fwm-brand__text">
            <span class="fwm-brand__name">Calendar</span>
            <span class="fwm-brand__company"><?= htmlspecialchars($companyName) ?></span>
        </span>
    </a>

    <?php if (!empty($withSearch)): ?>
    <form class="fwm-search" id="searchForm" role="search" autocomplete="off">
        <label class="fwm-search__box">
            <svg class="i"><use href="#i-search"/></svg>
            <span class="sr-only">Search events</span>
            <input type="search" id="searchInput" placeholder="Search events" enterkeyhint="search" spellcheck="false">
            <button type="button" class="icon-btn icon-btn--sm" id="searchClear" aria-label="Clear search" hidden><svg class="i i--sm"><use href="#i-x"/></svg></button>
            <kbd class="fwm-kbd hide-narrow" aria-hidden="true">/</kbd>
        </label>
    </form>
    <?php endif; ?>

    <div class="fwm-top__actions">
        <a class="icon-btn hide-narrow" href="/calendar/settings.php" aria-label="Calendar settings" data-tip="Settings"><svg class="i"><use href="#i-settings"/></svg></a>
        <button class="icon-btn" id="themeToggle" type="button" aria-label="Switch light or dark theme" data-tip="Theme">
            <svg class="i"><use href="#i-moon"/></svg>
        </button>
        <a class="icon-btn" href="/home.php" aria-label="Flowwork home" data-tip="Home"><svg class="i"><use href="#i-home"/></svg></a>
        <button class="icon-btn" id="kebabBtn" type="button" aria-label="More" aria-haspopup="menu"
                data-admin="<?= !empty($isAdmin) ? '1' : '0' ?>"><svg class="i"><use href="#i-more-v"/></svg></button>
    </div>
</header>
