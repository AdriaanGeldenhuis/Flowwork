<?php
// /admin/partials/foot.php — closes the main pane opened by head.php, then
// the toasts host, the boot data and the scripts.
// Inputs: $pageScripts (optional list of /admin/js/*.js names), $pageBoot (optional array merged into the boot data)
$adminBoot = ($pageBoot ?? []) + [
    'companies' => AdminKit::companies($ADMIN_USER_ID, $ADMIN_COMPANY_ID),
    'jump' => ($bare ?? false) ? [] : AdminKit::jumpIndex(),
    'me' => $ADMIN_USER_ID,
];
?>
                </div>
            </div>
        </main>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script id="adminBoot" type="application/json"><?= json_encode($adminBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/admin/js/admin.js?v=<?= $adminV('admin/js/admin.js') ?>"></script>
<?php foreach ($pageScripts ?? [] as $js): ?>
<script src="/admin/js/<?= $e($js) ?>.js?v=<?= $adminV('admin/js/' . $js . '.js') ?>"></script>
<?php endforeach; ?>
<script src="/shared/company_switcher.js?v=<?= $adminV('shared/company_switcher.js') ?>"></script>
</body>
</html>
