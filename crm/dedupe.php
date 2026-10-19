<?php
// /crm/dedupe.php — find and merge duplicate customers or suppliers. Admins
// only. ?account=ID focuses on one account (from its "Merge with another
// account" menu). Rendered by /crm/js/dedupe.js on /crm/api/dedupe.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/CrmBoot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));
if (!Crm::isAdmin($role)) {
    header('Location: /crm/');
    exit;
}

try { CrmSchema::ensure($DB); } catch (Throwable $e) { error_log('[crm/dedupe] schema: ' . $e->getMessage()); }
$boot = CrmBoot::payload($DB, $companyId, $userId, $role);
$focusId = (int)($_GET['account'] ?? 0);
if ($focusId > 0 && ($row = CrmAccounts::row($DB, $companyId, $focusId)) && !$row['deleted_at']) {
    $boot['focus'] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'type' => (string)$row['type']];
}

$pageTitle = 'Duplicates';
$sideActive = 'dedupe';
$onHome = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwc-app" id="crmApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwc-main" aria-label="Duplicates">
            <header class="fwc-head">
                <div class="fwc-head__title">
                    <div class="fwc-eyebrow"><?= htmlspecialchars($boot['config']['labels']['crm']) ?> · Tools</div>
                    <h1>Duplicates</h1>
                </div>
                <div class="fwc-head__actions" id="dupActions"></div>
            </header>
            <div class="fwc-page scroll" id="page" tabindex="-1">
                <div class="fwc-page__inner" id="dup"><div class="fwc-loading" role="status"><span class="fwc-spin" aria-hidden="true"></span>Loading…</div></div>
            </div>
        </section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="crmBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/crm/js/crm-core.js?v=<?= $crmV('crm/js/crm-core.js') ?>"></script>
<script src="/crm/js/crm-forms.js?v=<?= $crmV('crm/js/crm-forms.js') ?>"></script>
<script src="/crm/js/dedupe.js?v=<?= $crmV('crm/js/dedupe.js') ?>"></script>
</body>
</html>
