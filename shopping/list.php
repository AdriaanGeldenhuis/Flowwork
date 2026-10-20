<?php
// /shopping/list.php?id= — one list. Add things by typing or pasting (read as
// you type), sorted by aisle; tick them off; "Start shopping" turns the page
// into the in-the-shop view: big rows, the trolley total against the budget,
// the price asked as you tick, the screen kept awake, ticks kept on the phone
// when the signal drops and sent when it is back, and other people's ticks
// appearing as they happen. Share a link with someone without Flowwork, make
// a purchase order, save it as a template, export or print it.
require_once __DIR__ . '/lib/ShBoot.php';

$listId = (int)($_GET['id'] ?? 0);
try {
    $detail = ShLists::detail($DB, $SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE, $listId);
} catch (ShProblem $ex) {
    $detail = null;
}

if (!$detail) {
    http_response_code(404);
    $page = 'list';
    $pageTitle = 'Not found';
    include __DIR__ . '/partials/head.php';
    ?>
    <div class="fwm-empty">
        <span class="fwm-empty__art" aria-hidden="true"><svg class="i"><use href="#is-list"/></svg></span>
        <p class="fwm-empty__eyebrow">Shopping</p>
        <h3>That list is not here</h3>
        <p>It may have been deleted, or it is someone’s private list.</p>
        <a class="btn btn--primary" href="/shopping/"><svg class="i"><use href="#i-arrow-left"/></svg>All lists</a>
    </div>
    <?php
    include __DIR__ . '/partials/foot.php';
    exit;
}

$L = $detail['list'];
$stores = array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name']], ShStores::all($DB, $SH_COMPANY_ID, $SH_USER_ID));
$page = 'list';
$pageTitle = $L['name'];
$activeListId = $L['id'];
$bodyClass = $detail['session'] ? 'is-shopping' : '';
$pageScripts = ['list'];
$pageBoot = ['detail' => $detail, 'stores' => $stores, 'aiReady' => ShKit::aiReady($DB, $SH_COMPANY_ID)];
include __DIR__ . '/partials/head.php';
?>
<div id="listApp" class="fws-list" data-id="<?= (int)$L['id'] ?>">
    <header class="fws-listhead" id="listHead">
        <a class="fws-back" href="/shopping/"><svg class="i i--sm"><use href="#i-arrow-left"/></svg>All lists</a>
        <div class="fws-listhead__row">
            <div class="fws-listhead__text">
                <p class="fws-eyebrow" id="listEyebrow"><?= $e($L['purpose_label']) ?></p>
                <h1 id="listName"><?= $e($L['name']) ?></h1>
                <div class="fws-meta" id="listMeta"></div>
            </div>
            <div class="fws-listhead__actions" id="listActions"></div>
        </div>
    </header>

    <div class="fws-trolley" id="trolley" hidden></div>
    <div class="fws-offline" id="offline" hidden role="status"></div>

    <div class="fws-listgrid">
        <div class="fws-listmain">
            <?php if ($L['edit']): ?>
            <form class="fws-add" id="addForm" autocomplete="off">
                <span class="fws-add__icon" aria-hidden="true"><svg class="i"><use href="#i-plus"/></svg></span>
                <label class="sr-only" for="addText">Add items</label>
                <textarea class="fws-add__text" id="addText" rows="1" enterkeyhint="done" placeholder="Add — e.g. 10 bags cement @ R95 · paste a whole list"></textarea>
                <button class="btn btn--primary btn--sm" type="submit" id="addGo" disabled>Add</button>
                <div class="fws-add__preview" id="addPreview" hidden aria-live="polite"></div>
            </form>
            <?php endif; ?>
            <div class="fws-toolbar" id="toolbar">
                <div class="seg" role="tablist" aria-label="Group">
                    <button type="button" data-group="aisle" role="tab">By aisle</button>
                    <button type="button" data-group="order" role="tab">As added</button>
                </div>
                <span class="fws-grow"></span>
                <span class="fws-toolbar__count" id="toolbarCount"></span>
            </div>
            <div class="fws-groups" id="groups" aria-live="polite"></div>
        </div>
        <aside class="fws-listside" id="listSide" aria-label="About this list"></aside>
    </div>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
