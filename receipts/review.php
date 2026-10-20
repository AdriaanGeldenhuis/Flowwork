<?php
// /receipts/review.php?id= — one receipt: the slip on the left (zoom, turn,
// drag), the bill on the right (js/review.js). Bookkeepers check what was
// read, choose the supplier, accounts and project, see the checks with
// their one-click fixes, save the draft or post it to the books — and move
// to the next receipt with j / k. Whoever sent it in sees how far it is.
require_once __DIR__ . '/lib/RcBoot.php';

$id = (int)($_GET['id'] ?? 0);
$row = $id > 0 ? RcBills::row($DB, $RC_COMPANY_ID, $id) : null;
if (!$row || !RcBills::canSee($row, $RC_ROLE, $RC_USER_ID)) {
    http_response_code(404);
    $page = 'inbox';
    $navView = 'todo';
    $pageTitle = 'Receipt not found';
    include __DIR__ . '/partials/head.php';
    ?>
    <div class="fwm-empty" style="min-height:60vh">
        <span class="fwm-empty__art"><svg class="i"><use href="#ir-seal"/></svg></span>
        <p class="fwm-empty__eyebrow">Receipts</p>
        <h3>That receipt is not here</h3>
        <p>It may have been deleted, or it belongs to another company or person.</p>
        <a class="btn btn--primary" href="/receipts/inbox.php"><svg class="i"><use href="#i-inbox"/></svg>Back to the receipts</a>
    </div>
    <?php
    include __DIR__ . '/partials/foot.php';
    exit;
}
$r = RcBills::shapeRow($DB, $row);
$title = (string)($row['vendor_name'] ?: $r['name']);
if ($row['b_supplier']) {
    $st = $DB->prepare("SELECT name FROM crm_accounts WHERE id = ? AND company_id = ?");
    $st->execute([(int)$row['b_supplier'], $RC_COMPANY_ID]);
    $title = (string)($st->fetchColumn() ?: $title);
}

$page = 'inbox';
$navView = in_array($r['state'], ['posted', 'paid'], true) ? 'done' : ($r['state'] === 'aside' ? 'aside' : 'todo');
$pageTitle = $title;
$wide = true;
$pageScripts = ['review'];
$pageBoot = ['id' => $id, 'aside' => RcKit::ASIDE_REASONS];
include __DIR__ . '/partials/head.php';
?>
<div class="fwr-review__bar">
    <a class="icon-btn" href="/receipts/inbox.php" id="backBtn" aria-label="Back to the receipts" data-tip="Back"><svg class="i"><use href="#i-arrow-left"/></svg></a>
    <div class="fwr-review__title">
        <h1 id="rvTitle"><?= $e($title) ?></h1>
        <small id="rvMeta">Receipt #<?= (int)$id ?> · sent in <?= $e(RcKit::date(substr((string)$row['uploaded_at'], 0, 10))) ?><?= $r['uploader'] ? ' by ' . $e($r['uploader']) : '' ?></small>
    </div>
    <span class="fwr-pill" id="rvState" data-tone="<?= $e(RcKit::STATUS_TONE[$r['state']]) ?>"><?= $e($r['state_label']) ?></span>
    <div class="fwr-queue" id="rvQueue" hidden>
        <button class="icon-btn icon-btn--sm" type="button" id="prevBtn" aria-label="Previous receipt (k)" data-tip="Previous · k"><svg class="i i--sm"><use href="#i-chev-left"/></svg></button>
        <span id="queuePos"></span>
        <button class="icon-btn icon-btn--sm" type="button" id="nextBtn" aria-label="Next receipt (j)" data-tip="Next · j"><svg class="i i--sm"><use href="#i-chev-right"/></svg></button>
    </div>
    <button class="icon-btn" type="button" id="moreBtn" aria-label="More" aria-haspopup="menu" aria-expanded="false" data-tip="More" data-tip-pos="left"><svg class="i"><use href="#i-more-h"/></svg></button>
</div>
<div class="fwr-review" id="review">
    <section class="fwr-viewer" id="viewer" aria-label="The slip">
        <div class="fwr-viewer__stage" id="stage">
            <?php if ($r['pdf']): ?>
                <div class="fwr-viewer__doc" id="slipDoc" data-pdf="<?= $e($r['url']) ?>"><div class="fwr-loading"><span class="fwr-spin"></span>Opening the PDF…</div></div>
            <?php else: ?>
                <img src="<?= $e($r['url']) ?>" alt="The slip" id="slipImg" draggable="false">
            <?php endif; ?>
            <div class="fwr-viewer__scan" id="scan"<?= $r['state'] === 'reading' ? '' : ' hidden' ?>></div>
        </div>
        <div class="fwr-viewer__tools" role="toolbar" aria-label="Slip view">
            <button class="icon-btn icon-btn--sm" type="button" data-zoom="-1" aria-label="Zoom out" data-tip="Zoom out · −"><svg class="i i--sm"><use href="#i-zoom-out"/></svg></button>
            <span id="zoomLabel">Fit</span>
            <button class="icon-btn icon-btn--sm" type="button" data-zoom="1" aria-label="Zoom in" data-tip="Zoom in · +"><svg class="i i--sm"><use href="#i-zoom-in"/></svg></button>
            <button class="icon-btn icon-btn--sm" type="button" data-fit aria-label="Fit the slip" data-tip="Fit · 0"><svg class="i i--sm"><use href="#ir-fit"/></svg></button>
            <button class="icon-btn icon-btn--sm" type="button" data-rotate aria-label="Turn the slip" data-tip="Turn · r"><svg class="i i--sm"><use href="#ir-rotate"/></svg></button>
            <span class="fwr-sep" aria-hidden="true"></span>
            <a class="icon-btn icon-btn--sm" href="<?= $e($r['url']) ?>" target="_blank" rel="noopener" aria-label="Open the slip in a new tab" data-tip="Open"><svg class="i i--sm"><use href="#i-external"/></svg></a>
            <a class="icon-btn icon-btn--sm" href="<?= $e($r['url']) ?>&download=1" aria-label="Download the slip" data-tip="Download"><svg class="i i--sm"><use href="#i-download"/></svg></a>
        </div>
    </section>
    <section class="fwr-form" id="form" aria-label="The bill">
        <div class="fwr-loading" style="flex:1"><span class="fwr-spin"></span>Opening the receipt…</div>
    </section>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
