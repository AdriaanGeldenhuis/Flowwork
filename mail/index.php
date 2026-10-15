<?php
// /mail/index.php — Mail app (inbox, reader, composer).
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$isAdmin = fw_active_user_is_admin();

$companyName = 'Company';
try {
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn() ?: 'Company';
    MailSchema::ensure($DB);
} catch (Throwable $e) {
    error_log('[mail/index] ' . $e->getMessage());
}

$pageTitle = 'Mail';
$withSearch = true;
$withSync = true;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app" id="mailApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwm-body">
        <aside class="fwm-side" id="side" aria-label="Folders">
            <button class="fwm-compose-btn hide-narrow-drawer" id="composeBtn" type="button">
                <svg class="i"><use href="#i-pen"/></svg><span>Compose</span>
            </button>
            <div class="fwm-side__scroll scroll" id="sideNav">
                <nav class="fwm-nav" id="navFolders" aria-label="Mailboxes"></nav>
                <div class="fwm-nav__head fwm-nav--labels"><h3>Labels</h3>
                    <button class="icon-btn icon-btn--sm" id="newLabelBtn" type="button" aria-label="New label" data-tip="New label"><svg class="i i--sm"><use href="#i-plus"/></svg></button>
                </div>
                <nav class="fwm-nav fwm-nav--labels" id="navLabels" aria-label="Labels"></nav>
                <div class="fwm-nav__head fwm-nav--views" id="viewsHead" hidden><h3>Saved searches</h3></div>
                <nav class="fwm-nav fwm-nav--views" id="navViews" aria-label="Saved searches"></nav>
            </div>
            <div class="fwm-side__foot" id="sideFoot"></div>
        </aside>

        <section class="fwm-pane fwm-list" id="listPane" aria-label="Conversations">
            <div class="fwm-list__head" id="listHead">
                <label class="fwm-check" id="selectAll" role="checkbox" aria-checked="false" tabindex="0" aria-label="Select all"><svg class="i"><use href="#i-check"/></svg></label>
                <div class="fwm-list__title"><h2 id="listTitle">Inbox</h2><small id="listSub">&nbsp;</small></div>
                <button class="icon-btn" id="saveSearchBtn" type="button" aria-label="Save this search" data-tip="Save search" hidden><svg class="i"><use href="#i-bookmark"/></svg></button>
                <button class="icon-btn" id="listMoreBtn" type="button" aria-label="List options" data-tip="Options" data-tip-pos="left"><svg class="i"><use href="#i-more-h"/></svg></button>
            </div>
            <div class="fwm-bulk" id="bulkBar" hidden>
                <label class="fwm-check is-on" id="bulkAll" role="checkbox" aria-checked="true" tabindex="0" aria-label="Clear selection"><svg class="i"><use href="#i-check"/></svg></label>
                <span class="fwm-bulk__count" id="bulkCount">0 selected</span>
                <span class="fwm-bulk__spacer"></span>
                <div id="bulkActions" style="display:flex;gap:2px"></div>
            </div>
            <div class="fwm-list__filters" id="filterChips" role="toolbar" aria-label="Filter">
                <button class="fwm-chip is-on" data-filter="">All</button>
                <button class="fwm-chip" data-filter="unread">Unread</button>
                <button class="fwm-chip" data-filter="starred"><svg class="i"><use href="#i-star"/></svg>Starred</button>
                <button class="fwm-chip" data-filter="attachments"><svg class="i"><use href="#i-clip"/></svg>Attachments</button>
            </div>
            <div class="fwm-threads scroll" id="threads" role="listbox" aria-label="Conversations" tabindex="0"></div>
        </section>

        <section class="fwm-pane fwm-reader" id="readerPane" aria-label="Reading pane"></section>
    </div>
</div>

<button class="fwm-fab" id="fabCompose" type="button"><svg class="i"><use href="#i-pen"/></svg>Compose</button>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>

<!-- Keyboard shortcuts -->
<div class="fwm-modal" id="shortcutsModal" hidden>
    <div class="fwm-dialog fwm-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="shortcutsTitle">
        <div class="fwm-dialog__head"><h2 id="shortcutsTitle">Keyboard shortcuts</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="fwm-kbd-grid">
                <h4>Navigate</h4>
                <div class="fwm-kbd-row"><span>Next / previous conversation</span><span><kbd class="fwm-kbd">j</kbd><kbd class="fwm-kbd">k</kbd></span></div>
                <div class="fwm-kbd-row"><span>Open conversation</span><span><kbd class="fwm-kbd">Enter</kbd></span></div>
                <div class="fwm-kbd-row"><span>Back to list</span><span><kbd class="fwm-kbd">Esc</kbd></span></div>
                <div class="fwm-kbd-row"><span>Search</span><span><kbd class="fwm-kbd">/</kbd></span></div>
                <div class="fwm-kbd-row"><span>Go to Inbox / Starred / Sent / Drafts</span><span><kbd class="fwm-kbd">g</kbd><kbd class="fwm-kbd">i</kbd>·<kbd class="fwm-kbd">s</kbd>·<kbd class="fwm-kbd">t</kbd>·<kbd class="fwm-kbd">d</kbd></span></div>
                <h4>Act</h4>
                <div class="fwm-kbd-row"><span>Compose</span><span><kbd class="fwm-kbd">c</kbd></span></div>
                <div class="fwm-kbd-row"><span>Reply / reply all / forward</span><span><kbd class="fwm-kbd">r</kbd><kbd class="fwm-kbd">a</kbd><kbd class="fwm-kbd">f</kbd></span></div>
                <div class="fwm-kbd-row"><span>Archive</span><span><kbd class="fwm-kbd">e</kbd></span></div>
                <div class="fwm-kbd-row"><span>Move to Trash</span><span><kbd class="fwm-kbd">#</kbd></span></div>
                <div class="fwm-kbd-row"><span>Report spam</span><span><kbd class="fwm-kbd">!</kbd></span></div>
                <div class="fwm-kbd-row"><span>Star / unstar</span><span><kbd class="fwm-kbd">s</kbd></span></div>
                <div class="fwm-kbd-row"><span>Mark unread / read</span><span><kbd class="fwm-kbd">⇧ U</kbd><kbd class="fwm-kbd">⇧ I</kbd></span></div>
                <div class="fwm-kbd-row"><span>Select conversation</span><span><kbd class="fwm-kbd">x</kbd></span></div>
                <div class="fwm-kbd-row"><span>Snooze</span><span><kbd class="fwm-kbd">b</kbd></span></div>
                <div class="fwm-kbd-row"><span>Label</span><span><kbd class="fwm-kbd">l</kbd></span></div>
                <div class="fwm-kbd-row"><span>Undo last action</span><span><kbd class="fwm-kbd">z</kbd></span></div>
                <h4>Compose</h4>
                <div class="fwm-kbd-row"><span>Send</span><span><kbd class="fwm-kbd">Ctrl</kbd><kbd class="fwm-kbd">Enter</kbd></span></div>
                <div class="fwm-kbd-row"><span>Insert link</span><span><kbd class="fwm-kbd">Ctrl</kbd><kbd class="fwm-kbd">K</kbd></span></div>
                <div class="fwm-kbd-row"><span>Show shortcuts</span><span><kbd class="fwm-kbd">?</kbd></span></div>
            </div>
        </div>
    </div>
</div>

<!-- Link to CRM -->
<div class="fwm-modal" id="crmModal" hidden>
    <div class="fwm-dialog" role="dialog" aria-modal="true" aria-labelledby="crmTitle">
        <div class="fwm-dialog__head"><h2 id="crmTitle">Link to CRM</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <p class="fwm-dialog__lead">Linked conversations appear on the customer's or supplier's timeline in the CRM.</p>
            <input class="input" id="crmSearch" type="search" placeholder="Search customers and suppliers" autocomplete="off">
            <div id="crmResults"></div>
        </div>
    </div>
</div>

<!-- Pick a date & time (snooze / schedule send) -->
<div class="fwm-modal" id="whenModal" hidden>
    <div class="fwm-dialog" role="dialog" aria-modal="true" aria-labelledby="whenTitle" style="width:min(420px,100%)">
        <div class="fwm-dialog__head"><h2 id="whenTitle">Pick date &amp; time</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="row">
                <div class="field"><label class="label" for="whenDate">Date</label><input class="input" type="date" id="whenDate"></div>
                <div class="field"><label class="label" for="whenTime">Time</label><input class="input" type="time" id="whenTime" step="300"></div>
            </div>
        </div>
        <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="whenOk">Confirm</button></div>
    </div>
</div>

<!-- New label -->
<div class="fwm-modal" id="labelModal" hidden>
    <div class="fwm-dialog" role="dialog" aria-modal="true" aria-labelledby="labelTitle" style="width:min(420px,100%)">
        <div class="fwm-dialog__head"><h2 id="labelTitle">New label</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="field"><label class="label" for="labelName">Name</label><input class="input" id="labelName" maxlength="64" placeholder="e.g. Suppliers"></div>
            <div class="field"><span class="label">Colour</span><div class="fwm-swatches" id="labelSwatches"></div></div>
        </div>
        <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="labelSave">Create label</button></div>
    </div>
</div>

<script src="/mail/js/mail.js?v=<?= MAIL_ASSET_VERSION ?>"></script>
</body>
</html>
