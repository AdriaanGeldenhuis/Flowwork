<?php
// /suppliers_ai/partials/foot.php — closes the main pane opened by head.php, then
// the toasts host, the boot data and the scripts.
// Inputs: $pageScripts (optional list of /suppliers_ai/js/*.js names), $pageBoot (optional array merged into the boot data)
$companies = [];
try {
    foreach (fw_user_companies($SA_USER_ID) as $c) $companies[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'active' => (int)$c['id'] === $SA_COMPANY_ID];
} catch (Throwable $ex) {
}
$trades = [];
foreach (SaParse::TRADES as $k => $t) $trades[$k] = ['label' => $t[0], 'icon' => $t[2], 'color' => $t[3]];
// Only what the pages need: not the company's word lists, and not even the masked keys.
$bootSettings = array_intersect_key($SA_SETTINGS, array_flip(['radius_km', 'min_score', 'max_recipients', 'compliant_only', 'ai_ready', 'google_ready']));
$saBoot = ($pageBoot ?? []) + [
    'companies' => $companies, 'company' => $SA_COMPANY_ID, 'role' => $SA_ROLE, 'caps' => $SA_CAPS, 'user' => $SA_USER_ID, 'settings' => $bootSettings, 'counts' => $SA_COUNTS,
    'town' => (string)($SA_COMPANY['town'] ?? ''), 'trades' => $trades,
    'pages' => array_values(array_filter([
        ['name' => 'Find suppliers', 'href' => '/suppliers_ai/', 'icon' => 'isa-seal', 'hint' => 'Say what you need — your suppliers, Google and AI'],
        ['name' => 'Requests for quotes', 'href' => '/suppliers_ai/requests.php', 'icon' => 'isa-quote', 'hint' => 'Asked, replied, quoted and awarded'],
        ['name' => 'Searches', 'href' => '/suppliers_ai/history.php', 'icon' => 'i-clock', 'hint' => 'Every search, to open again'],
        $SA_CAPS['configure'] ? ['name' => 'Settings', 'href' => '/suppliers_ai/settings.php', 'icon' => 'i-settings', 'hint' => 'Keys, limits, words and the request email'] : null,
        ['name' => 'How it works', 'href' => '/suppliers_ai/help.php', 'icon' => 'i-info', 'hint' => 'Where results come from and what is sent where'],
    ])),
];
?>
                </div>
            </div>
        </main>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script id="saBoot" type="application/json"><?= json_encode($saBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/suppliers_ai/js/sai.js?v=<?= $saV('suppliers_ai/js/sai.js') ?>"></script>
<?php foreach ($pageScripts ?? [] as $js): ?>
<script src="/suppliers_ai/js/<?= $e($js) ?>.js?v=<?= $saV('suppliers_ai/js/' . $js . '.js') ?>"></script>
<?php endforeach; ?>
<script src="/shared/company_switcher.js?v=<?= $saV('shared/company_switcher.js') ?>"></script>
</body>
</html>
