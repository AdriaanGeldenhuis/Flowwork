<?php
// /finances/settings.php — "Make it yours". Everyone sets their own look
// (density, text size, colour, money format, default period, where Finance
// opens, which overview panels they see, saved views). Administrators also
// shape Finance for the company: colour, wording, which parts are on, the
// overview layout, thresholds, rules, the financial year, VAT basis,
// segregation of duties, account mappings and exchange rates.
// Rendered by /finances/js/fin-settings.js from /finances/api/settings.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/FinBoot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));

try { FinSchema::ensure($DB); } catch (Throwable $e) { error_log('[finances/settings] schema: ' . $e->getMessage()); }
if (!Fin::canSee($role, Fin::config($DB, $companyId))) {
    header('Location: /finances/access_denied.php?need=' . urlencode('admin, bookkeeper, viewer'));
    exit;
}
$boot = FinBoot::payload($DB, $companyId, $userId, $role);

$pageTitle = 'Make it yours';
$sideActive = 'settings';
$onHome = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwf-app" id="finApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwf-main" aria-label="Make it yours">
            <div class="fwf-set" id="set">
                <nav class="fwf-set__nav scroll" id="setNav" aria-label="Settings sections"></nav>
                <div class="fwf-set__content scroll" id="setContent" tabindex="-1">
                    <div class="fwf-loading" role="status"><span class="fwf-spin" aria-hidden="true"></span>Loading your settings…</div>
                </div>
            </div>
        </section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="finBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/finances/js/fin-core.js?v=<?= $finV('finances/js/fin-core.js') ?>"></script>
<script src="/finances/js/fin-settings.js?v=<?= $finV('finances/js/fin-settings.js') ?>"></script>
</body>
</html>
