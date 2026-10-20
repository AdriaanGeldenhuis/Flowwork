<?php
// /receipts/upload.php — sending slips in, phone first: one big button opens
// the camera, others choose files or a ZIP. A note says what it was for. The
// slips are read as they arrive; the list below shows the latest ones sent
// in by this person and where each one is. (Home's "Scan receipt" opens this.)
require_once __DIR__ . '/lib/RcBoot.php';

if (!$RC_CAPS['upload']) {
    header('Location: /receipts/', true, 302);
    exit;
}
$reader = ['ai' => 'Read by AI the moment it arrives', 'tesseract' => 'Read on the server the moment it arrives', 'manual' => 'Your bookkeeper types in the details'][$RC_SETTINGS['ocr_provider']] ?? '';

$page = 'upload';
$pageTitle = 'Snap a receipt';
$pageScripts = ['capture'];
include __DIR__ . '/partials/head.php';
?>
<header class="fwr-head">
    <div class="fwr-head__text">
        <p class="fwr-eyebrow">Receipts <i>◆</i> Send in</p>
        <h1>Snap a <em>receipt</em></h1>
        <p>Lay the slip flat in good light and fill the frame. PDFs from email work too, and so do ZIPs of many slips. <?= $e($reader) ?>.</p>
    </div>
</header>

<div class="fwr-snap">
    <button class="fwr-snap__btn fwr-snap__btn--hero" type="button" data-capture="camera">
        <span class="fwr-snap__icon"><svg class="i"><use href="#ir-camera"/></svg></span>
        <b>Take a photo</b>
        <small>Opens your camera. One slip per photo.</small>
    </button>
    <button class="fwr-snap__btn" type="button" data-capture="pick">
        <span class="fwr-snap__icon"><svg class="i"><use href="#i-upload"/></svg></span>
        <b>Choose files</b>
        <small>PDFs and photos — as many as you like.</small>
    </button>
    <button class="fwr-snap__btn" type="button" data-capture="pick" data-zip>
        <span class="fwr-snap__icon"><svg class="i"><use href="#ir-zip"/></svg></span>
        <b>A ZIP of slips</b>
        <small>Up to 200 slips at once, from a scanner or an export.</small>
    </button>
</div>

<section class="fwr-panel" style="margin-top:16px">
    <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#i-message"/></svg></span>
        <div class="fwr-panel__title"><h3>What was it for?</h3><p>Optional — goes with the next slips you send in, so your bookkeeper knows the job or reason.</p></div></div>
    <div class="fwr-panel__body">
        <textarea class="textarea" id="snapNote" rows="2" maxlength="500" placeholder="Cement and sand for the Smith job"></textarea>
    </div>
</section>

<section class="fwr-panel fwr-panel--flush" style="margin-top:16px">
    <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#i-clock"/></svg></span>
        <div class="fwr-panel__title"><h3>Sent in by you</h3><p>Where each slip is: read, made into a bill, in the books.</p></div>
        <a class="fwr-mini" href="/receipts/inbox.php?view=all&amp;q=">All receipts</a></div>
    <div class="fwr-panel__body"><ul class="fwr-rows" id="mine"><li><div class="fwr-loading"><span class="fwr-spin"></span>Loading…</div></li></ul></div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
