<?php
// /crm/index.php — the CRM: overview, customers, suppliers, pipeline, tasks,
// contacts, activity and compliance (hash routes, see /crm/js/crm-app.js).
// Older links (?tab=suppliers, ?type=supplier) are turned into routes by crm-app.js.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/CrmBoot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));

try { CrmSchema::ensure($DB); } catch (Throwable $e) { error_log('[crm/index] schema: ' . $e->getMessage()); }
$boot = CrmBoot::payload($DB, $companyId, $userId, $role);

$pageTitle = $boot['config']['labels']['crm'];
$sideActive = '';
$onHome = true;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwc-app" id="crmApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwc-main" id="mainPane" aria-label="<?= htmlspecialchars($boot['config']['labels']['crm']) ?>">
            <div class="fwc-head" id="head"></div>
            <div class="fwc-tools" id="tools" hidden></div>
            <div class="fwc-view scroll" id="view" tabindex="-1"></div>
        </section>
    </div>
</div>
<?php if ($boot['can_write']): ?>
<button class="fwm-fab" id="fab" type="button" data-new="customer"><svg class="i"><use href="#i-plus"/></svg><span id="fabLabel">New</span></button>
<?php endif; ?>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="crmBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/crm/js/crm-core.js?v=<?= $crmV('crm/js/crm-core.js') ?>"></script>
<script src="/crm/js/crm-forms.js?v=<?= $crmV('crm/js/crm-forms.js') ?>"></script>
<script src="/crm/js/crm-app.js?v=<?= $crmV('crm/js/crm-app.js') ?>"></script>
</body>
</html>
