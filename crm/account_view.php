<?php
// /crm/account_view.php?id=… — one customer or supplier: everything about them
// on one page (activity, contacts, deals, documents, compliance, details) with
// the next steps beside it. Rendered by /crm/js/account.js from the payload
// below. Other modules link here (Quotes & Invoices, Mail, Calendar, Projects,
// Suppliers AI), so the address stays the same. ?edit=1 opens the editor.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/CrmBoot.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));
$accountId = (int)($_GET['id'] ?? 0);

try { CrmSchema::ensure($DB); } catch (Throwable $e) { error_log('[crm/account] schema: ' . $e->getMessage()); }
$account = $accountId > 0 ? CrmAccounts::full($DB, $companyId, $userId, $role, $accountId) : null;
if (!$account) {
    header('Location: /crm/');
    exit;
}
$boot = CrmBoot::payload($DB, $companyId, $userId, $role);
$boot['account'] = $account;

$pageTitle = $account['name'];
$sideActive = $account['type'] === 'supplier' ? 'suppliers' : 'customers';
$onHome = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwc-app" id="crmApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-body">
        <?php include __DIR__ . '/partials/side.php'; ?>
        <section class="fwm-pane fwc-main" aria-label="<?= htmlspecialchars($account['name']) ?>">
            <div class="fwc-view scroll" id="view" tabindex="-1">
                <div class="fwc-acct" id="acct"></div>
            </div>
        </section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="crmBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/crm/js/crm-core.js?v=<?= $crmV('crm/js/crm-core.js') ?>"></script>
<script src="/crm/js/crm-forms.js?v=<?= $crmV('crm/js/crm-forms.js') ?>"></script>
<script src="/crm/js/account.js?v=<?= $crmV('crm/js/account.js') ?>"></script>
</body>
</html>
