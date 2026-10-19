<?php
// /crm/import.php — bring customers and suppliers in from a spreadsheet (CSV:
// upload or paste, match columns, check first, then import) and take any part
// of the CRM out as CSV. Admins only. Rendered by /crm/js/import.js on
// /crm/api/transfer.php.
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

try { CrmSchema::ensure($DB); } catch (Throwable $e) { error_log('[crm/import] schema: ' . $e->getMessage()); }
$boot = CrmBoot::payload($DB, $companyId, $userId, $role);

$pageTitle = 'Import & export';
$sideActive = 'import';
$onHome = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwc-app" id="crmApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwc-main" aria-label="Import and export">
            <header class="fwc-head">
                <div class="fwc-head__title">
                    <div class="fwc-eyebrow"><?= htmlspecialchars($boot['config']['labels']['crm']) ?> · Tools</div>
                    <h1>Import &amp; export</h1>
                </div>
            </header>
            <div class="fwc-page scroll" id="page" tabindex="-1">
                <div class="fwc-page__inner" id="imp"><div class="fwc-loading" role="status"><span class="fwc-spin" aria-hidden="true"></span>Loading…</div></div>
            </div>
        </section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="crmBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/crm/js/crm-core.js?v=<?= $crmV('crm/js/crm-core.js') ?>"></script>
<script src="/crm/js/crm-forms.js?v=<?= $crmV('crm/js/crm-forms.js') ?>"></script>
<script src="/crm/js/import.js?v=<?= $crmV('crm/js/import.js') ?>"></script>
</body>
</html>
