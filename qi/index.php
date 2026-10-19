<?php
// /qi/index.php — Quotes & Invoices: overview, quotes, invoices, credit notes
// and recurring invoices. Routes (hash): #overview · #quotes · #invoices ·
// #credit_notes · #recurring, each with ?status=…&q=…&sort=…&dir=…&page=…
// Older links (?tab=invoices&status=overdue) are turned into routes by qi-app.js.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/QiSchema.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));

try { QiSchema::ensure($DB); } catch (Throwable $e) { error_log('[qi/index] schema: ' . $e->getMessage()); }

// One-time self-heal: publish the company's existing documents into FlowWork
// Drive the first time Quotes & Invoices opens after deployment (bounded; see
// FlowDriveBackfill). Best-effort — never blocks the page.
try {
    require_once __DIR__ . '/../includes/flowdrive/FlowDriveBackfill.php';
    FlowDriveBackfill::autoRunOnce($DB, $companyId);
} catch (Throwable $e) {
    error_log('QI index FlowDrive autoRun: ' . $e->getMessage());
}

$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = (string)($stmt->fetchColumn() ?: 'Company');
$stmt = $DB->prepare("SELECT first_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$firstName = (string)($stmt->fetchColumn() ?: '');

// Legacy ?tab= links: only the known tab names survive (the route is built in JS)
$legacyTab = in_array($_GET['tab'] ?? '', ['overview', 'quotes', 'invoices', 'recurring', 'credit_notes'], true) ? $_GET['tab'] : '';
$legacyStatus = preg_match('/^[a-z_-]{1,20}$/', $_GET['status'] ?? '') ? $_GET['status'] : '';

$boot = [
    'company' => $companyName,
    'first_name' => $firstName,
    'can_write' => $role !== 'viewer',
    'is_admin' => in_array($role, ['admin', 'bookkeeper'], true),
    'today' => date('Y-m-d'),
    'legacy' => ['tab' => $legacyTab, 'status' => $legacyStatus],
];

$pageTitle = 'Quotes & Invoices';
$withSearch = true;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwq-app" id="qiApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwm-body">
        <aside class="fwm-side fwq-side" id="side" aria-label="Quotes & Invoices">
            <?php if ($role !== 'viewer'): ?>
            <div class="fwq-new">
                <a class="fwm-compose-btn" id="newBtn" href="/qi/edit.php?type=invoice"><svg class="i"><use href="#i-plus"/></svg><span id="newLabel">New invoice</span></a>
                <button class="fwq-new__more" id="newMore" type="button" aria-label="Create something else" aria-haspopup="menu"><svg class="i"><use href="#i-chev-down"/></svg></button>
            </div>
            <?php endif; ?>
            <div class="fwm-side__scroll scroll">
                <nav class="fwm-nav" id="nav" aria-label="Views">
                    <a class="fwm-nav__item" href="#overview" data-route="overview"><svg class="i"><use href="#iq-overview"/></svg><span class="fwm-nav__label">Overview</span></a>
                    <a class="fwm-nav__item" href="#quotes" data-route="quotes"><svg class="i"><use href="#iq-quote"/></svg><span class="fwm-nav__label">Quotes</span><span class="fwm-nav__count" data-count="quotes"></span></a>
                    <a class="fwm-nav__item" href="#invoices" data-route="invoices"><svg class="i"><use href="#iq-invoice"/></svg><span class="fwm-nav__label">Invoices</span><span class="fwm-nav__count" data-count="invoices"></span></a>
                    <a class="fwm-nav__item" href="#credit_notes" data-route="credit_notes"><svg class="i"><use href="#iq-credit"/></svg><span class="fwm-nav__label">Credit notes</span><span class="fwm-nav__count" data-count="credit_notes"></span></a>
                    <a class="fwm-nav__item" href="#recurring" data-route="recurring"><svg class="i"><use href="#iq-recurring"/></svg><span class="fwm-nav__label">Recurring</span><span class="fwm-nav__count" data-count="recurring"></span></a>
                </nav>
                <div class="fwm-nav__head"><h3>Shortcuts</h3></div>
                <nav class="fwm-nav" aria-label="Shortcuts">
                    <a class="fwm-nav__item" href="#invoices?status=overdue"><svg class="i"><use href="#i-alert"/></svg><span class="fwm-nav__label">Overdue invoices</span></a>
                    <a class="fwm-nav__item" href="#quotes?status=open"><svg class="i"><use href="#i-clock"/></svg><span class="fwm-nav__label">Open quotes</span></a>
                    <a class="fwm-nav__item" href="#invoices?status=draft"><svg class="i"><use href="#i-draft"/></svg><span class="fwm-nav__label">Draft invoices</span></a>
                </nav>
                <div class="fwm-nav__head"><h3>Make it yours</h3></div>
                <nav class="fwm-nav" aria-label="Tools">
                    <a class="fwm-nav__item" href="/qi/designer.php"><svg class="i"><use href="#iq-designer"/></svg><span class="fwm-nav__label">Document designer</span></a>
                    <a class="fwm-nav__item" href="/qi/settings.php"><svg class="i"><use href="#i-settings"/></svg><span class="fwm-nav__label">Settings</span></a>
                </nav>
            </div>
        </aside>

        <section class="fwm-pane fwq-main" id="mainPane" aria-label="Quotes & Invoices">
            <div class="fwq-head" id="head"></div>
            <div class="fwq-tools" id="tools" hidden></div>
            <div class="fwq-view scroll" id="view" tabindex="-1"></div>
        </section>
    </div>
</div>
<?php if ($role !== 'viewer'): ?>
<a class="fwm-fab" id="fab" href="/qi/edit.php?type=invoice"><svg class="i"><use href="#i-plus"/></svg><span id="fabLabel">New invoice</span></a>
<?php endif; ?>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="qiBoot" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/qi/js/qi-core.js?v=<?= $qiV('qi/js/qi-core.js') ?>"></script>
<script src="/qi/js/qi-app.js?v=<?= $qiV('qi/js/qi-app.js') ?>"></script>
</body>
</html>
