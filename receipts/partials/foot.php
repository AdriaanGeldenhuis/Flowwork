<?php
// /receipts/partials/foot.php — closes the main pane opened by head.php, then
// the upload layer (drop anywhere, paste a screenshot, the progress tray),
// the toasts host, the boot data and the scripts.
// Inputs: $pageScripts (optional list of /receipts/js/*.js names), $pageBoot (optional array merged into the boot data)
$companies = [];
try {
    foreach (fw_user_companies($RC_USER_ID) as $c) $companies[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'active' => (int)$c['id'] === $RC_COMPANY_ID];
} catch (Throwable $ex) {
}
$rcBoot = ($pageBoot ?? []) + [
    'companies' => $companies, 'role' => $RC_ROLE, 'caps' => $RC_CAPS, 'user' => $RC_USER_ID,
    'maxBytes' => (int)$RC_SETTINGS['max_file_size_mb'] * 1048576, 'reader' => $RC_SETTINGS['ocr_provider'],
    'pages' => array_values(array_filter([
        ['name' => 'Overview', 'href' => '/receipts/', 'icon' => 'if-overview', 'hint' => 'What is waiting, and where the money went'],
        ['name' => RcKit::canSeeAll($RC_ROLE) ? 'To do' : 'My receipts', 'href' => '/receipts/inbox.php', 'icon' => 'i-inbox', 'hint' => 'Receipts to review and draft bills'],
        ['name' => 'In the books', 'href' => '/receipts/inbox.php?view=done', 'icon' => 'if-ledger', 'hint' => 'Posted and paid'],
        ['name' => 'Set aside', 'href' => '/receipts/inbox.php?view=aside', 'icon' => 'i-archive', 'hint' => 'Duplicates, personal slips and other non-bills'],
        $RC_CAPS['upload'] ? ['name' => 'Snap a receipt', 'href' => '/receipts/upload.php', 'icon' => 'ir-camera', 'hint' => 'Take a photo or choose files'] : null,
        $RC_CAPS['settings'] ? ['name' => 'Settings', 'href' => '/receipts/settings.php', 'icon' => 'i-settings', 'hint' => 'Reading, checks, defaults and learning'] : null,
    ])),
];
?>
                </div>
            </div>
        </main>
    </div>
</div>
<?php if ($RC_CAPS['upload']): ?>
<input type="file" id="rcPick" accept="application/pdf,image/jpeg,image/png,image/webp,.pdf,.jpg,.jpeg,.png,.webp,.zip,application/zip" multiple hidden>
<input type="file" id="rcCamera" accept="image/*" capture="environment" hidden>
<div class="fwr-drop" id="rcDrop" hidden aria-hidden="true">
    <div class="fwr-drop__card">
        <span class="fwr-drop__art"><svg class="i"><use href="#ir-drop"/></svg></span>
        <b>Drop to send in</b>
        <small>PDFs, photos and ZIPs of slips · read straight away</small>
    </div>
</div>
<section class="fwr-tray" id="rcTray" hidden aria-label="Sending in">
    <header class="fwr-tray__head">
        <span class="fwr-tray__title" id="rcTrayTitle">Sending in</span>
        <button class="icon-btn icon-btn--sm" type="button" id="rcTrayMin" aria-label="Make smaller"><svg class="i i--sm"><use href="#i-chev-down"/></svg></button>
        <button class="icon-btn icon-btn--sm" type="button" id="rcTrayClose" aria-label="Close"><svg class="i i--sm"><use href="#i-x"/></svg></button>
    </header>
    <ul class="fwr-tray__list" id="rcTrayList"></ul>
    <footer class="fwr-tray__foot" id="rcTrayFoot" hidden></footer>
</section>
<?php endif; ?>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script id="rcBoot" type="application/json"><?= json_encode($rcBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/receipts/js/receipts.js?v=<?= $rcV('receipts/js/receipts.js') ?>"></script>
<?php foreach ($pageScripts ?? [] as $js): ?>
<script src="/receipts/js/<?= $e($js) ?>.js?v=<?= $rcV('receipts/js/' . $js . '.js') ?>"></script>
<?php endforeach; ?>
<script src="/shared/company_switcher.js?v=<?= $rcV('shared/company_switcher.js') ?>"></script>
</body>
</html>
