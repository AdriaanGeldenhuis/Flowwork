<?php
// /finances/index.php — Finance: overview, journals, ledger, chart of accounts,
// bank, receivables, payables, VAT, fixed assets, payroll, budgets, reports and
// close (hash routes, see /finances/js/fin-app.js). Older pages redirect here
// with the matching route.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/FinBoot.php';
require_once __DIR__ . '/lib/FinanceBootstrap.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));

try { FinSchema::ensure($DB); } catch (Throwable $e) { error_log('[finances/index] schema: ' . $e->getMessage()); }
if (!Fin::canSee($role, Fin::config($DB, $companyId))) {
    header('Location: /finances/access_denied.php?need=' . urlencode('admin, bookkeeper, viewer'));
    exit;
}
// A company opening Finance for the first time gets its VAT codes, chart and
// account mappings (only people who may write change the books).
if (Fin::canWrite($role)) {
    try { FinanceBootstrap::ensure($DB, $companyId); } catch (Throwable $e) { error_log('[finances/index] bootstrap: ' . $e->getMessage()); }
}
$boot = FinBoot::payload($DB, $companyId, $userId, $role);

$pageTitle = $boot['config']['labels']['finance'];
$sideActive = '';
$onHome = true;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwf-app" id="finApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwf-main" id="mainPane" aria-label="<?= htmlspecialchars($boot['config']['labels']['finance']) ?>">
            <div class="fwf-head" id="head"></div>
            <div class="fwf-tools" id="tools" hidden></div>
            <div class="fwf-view scroll" id="view" tabindex="-1"></div>
        </section>
    </div>
</div>
<?php if ($boot['can_write']): ?>
<button class="fwm-fab" id="fab" type="button" data-new="journal"><svg class="i"><use href="#i-plus"/></svg><span id="fabLabel">New</span></button>
<?php endif; ?>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="finBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/finances/js/fin-core.js?v=<?= $finV('finances/js/fin-core.js') ?>"></script>
<script src="/finances/js/fin-app.js?v=<?= $finV('finances/js/fin-app.js') ?>"></script>
<script src="/finances/js/fin-books.js?v=<?= $finV('finances/js/fin-books.js') ?>"></script>
<script src="/finances/js/fin-money.js?v=<?= $finV('finances/js/fin-money.js') ?>"></script>
<script src="/finances/js/fin-parties.js?v=<?= $finV('finances/js/fin-parties.js') ?>"></script>
<script src="/finances/js/fin-tax.js?v=<?= $finV('finances/js/fin-tax.js') ?>"></script>
<script src="/finances/js/fin-reports.js?v=<?= $finV('finances/js/fin-reports.js') ?>"></script>
<script>FIN.app.start();</script>
</body>
</html>
