<?php
// /crm/settings.php — "Make it yours": everyone sets their own look (density,
// text size, colour, where the CRM opens); admins also shape the CRM for the
// whole company (colour, wording, which parts are on, pipeline stages,
// statuses, activity types, custom fields, layout, tags, lists, rules,
// compliance and email templates). Rendered by /crm/js/settings.js from
// /crm/api/settings.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/CrmBoot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));

try { CrmSchema::ensure($DB); } catch (Throwable $e) { error_log('[crm/settings] schema: ' . $e->getMessage()); }
$boot = CrmBoot::payload($DB, $companyId, $userId, $role);

$pageTitle = 'Make it yours';
$sideActive = 'settings';
$onHome = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwc-app" id="crmApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwc-main" aria-label="Make it yours">
            <div class="fwc-set" id="set">
                <nav class="fwc-set__nav scroll" id="setNav" aria-label="Settings sections"></nav>
                <div class="fwc-set__content scroll" id="setContent" tabindex="-1">
                    <div class="fwc-loading" role="status"><span class="fwc-spin" aria-hidden="true"></span>Loading your settings…</div>
                </div>
            </div>
        </section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="crmBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/crm/js/crm-core.js?v=<?= $crmV('crm/js/crm-core.js') ?>"></script>
<script src="/crm/js/crm-forms.js?v=<?= $crmV('crm/js/crm-forms.js') ?>"></script>
<script src="/crm/js/settings.js?v=<?= $crmV('crm/js/settings.js') ?>"></script>
</body>
</html>
