<?php
// /crm/opp_view.php?id=… (or ?opp_id=…, as Calendar links it) — one deal as a
// page: stage, value, dates, people, notes, custom fields, activity, tasks and
// history, with won / lost, quote and convert-to-project. The pipeline opens the
// same deal in a side sheet (see CRM.forms.sheet in /crm/js/crm-forms.js).
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/CrmBoot.php';
require_once __DIR__ . '/lib/CrmDeals.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));
$dealId = (int)($_GET['id'] ?? $_GET['opp_id'] ?? 0);

try { CrmSchema::ensure($DB); } catch (Throwable $e) { error_log('[crm/deal] schema: ' . $e->getMessage()); }
$deal = $dealId > 0 ? CrmDeals::get($DB, $companyId, $dealId) : null;
if (!$deal) {
    header('Location: /crm/#pipeline');
    exit;
}
$boot = CrmBoot::payload($DB, $companyId, $userId, $role);
$boot['deal_id'] = $dealId;

$pageTitle = $deal['title'];
$sideActive = 'pipeline';
$onHome = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwc-app" id="crmApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwc-main" aria-label="<?= htmlspecialchars($deal['title']) ?>">
            <div class="fwc-view scroll" id="view">
                <div style="padding:18px 22px 60px;max-width:980px">
                    <a class="fwc-back" href="/crm/#pipeline"><svg class="i i--xs"><use href="#i-chev-left"/></svg> <?= htmlspecialchars($boot['config']['labels']['pipeline']) ?></a>
                    <div id="deal" style="margin-top:10px"></div>
                </div>
            </div>
        </section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="crmBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/crm/js/crm-core.js?v=<?= $crmV('crm/js/crm-core.js') ?>"></script>
<script src="/crm/js/crm-forms.js?v=<?= $crmV('crm/js/crm-forms.js') ?>"></script>
<script>
CRM.initChrome();
CRM.forms.sheet(CRM.BOOT.deal_id, { host: document.getElementById('deal') });
</script>
</body>
</html>
