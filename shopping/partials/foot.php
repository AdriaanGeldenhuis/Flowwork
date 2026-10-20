<?php
// /shopping/partials/foot.php — closes the main pane opened by head.php, then
// the toasts host, the boot data and the scripts.
// Inputs: $pageScripts (optional list of /shopping/js/*.js names), $pageBoot (optional array merged into the boot data)
$companies = [];
try {
    foreach (fw_user_companies($SH_USER_ID) as $c) $companies[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'active' => (int)$c['id'] === $SH_COMPANY_ID];
} catch (Throwable $ex) {
}
$shBoot = ($pageBoot ?? []) + [
    'companies' => $companies, 'role' => $SH_ROLE, 'caps' => $SH_CAPS, 'user' => $SH_USER_ID, 'prefs' => $SH_PREFS,
    'purposes' => ShKit::PURPOSES, 'units' => ShParse::UNITS,
    'categories' => array_merge(array_map(fn($c) => ['label' => $c[0], 'icon' => $c[1], 'color' => $c[2]], ShParse::CATEGORIES), ['other' => ['label' => ShParse::OTHER[0], 'icon' => ShParse::OTHER[1], 'color' => ShParse::OTHER[2]]]),
    'pages' => [
        ['name' => 'Overview', 'href' => '/shopping/', 'icon' => 'is-cart', 'hint' => 'Every list, what is needed soon and what was bought'],
        ['name' => 'Templates', 'href' => '/shopping/templates.php', 'icon' => 'i-template', 'hint' => 'Lists you buy again and again'],
        ['name' => 'Prices & stores', 'href' => '/shopping/prices.php', 'icon' => 'is-price', 'hint' => 'What things cost, and where'],
        ['name' => 'Preferences', 'href' => '/shopping/preferences.php', 'icon' => 'i-settings', 'hint' => 'In the shop, directions, new lists'],
    ],
];
?>
                </div>
            </div>
        </main>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script id="shBoot" type="application/json"><?= json_encode($shBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/shopping/js/shopping.js?v=<?= $shV('shopping/js/shopping.js') ?>"></script>
<?php foreach ($pageScripts ?? [] as $js): ?>
<script src="/shopping/js/<?= $e($js) ?>.js?v=<?= $shV('shopping/js/' . $js . '.js') ?>"></script>
<?php endforeach; ?>
<script src="/shared/company_switcher.js?v=<?= $shV('shared/company_switcher.js') ?>"></script>
</body>
</html>
