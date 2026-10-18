<?php
// /qi/settings.php — Quotes & Invoices settings: business details, banking,
// numbering, defaults, email wording and card payments. Administrators and
// bookkeepers can change them; everyone else can look.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/QiSchema.php';

$companyId = (int)$_SESSION['company_id'];
try { QiSchema::ensure($DB); } catch (Throwable $e) { error_log('[qi/settings] schema: ' . $e->getMessage()); }
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = (string)($stmt->fetchColumn() ?: 'Company');

$pageTitle = 'Settings';
$withSearch = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwq-app">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwm-body">
        <aside class="fwm-side fwq-side" id="side" aria-label="Settings sections">
            <div class="fwm-side__scroll scroll">
                <nav class="fwm-nav" id="setNav" aria-label="Sections">
                    <a class="fwm-nav__item" href="#business" data-sec="business"><svg class="i"><use href="#i-building"/></svg><span class="fwm-nav__label">Business details</span></a>
                    <a class="fwm-nav__item" href="#banking" data-sec="banking"><svg class="i"><use href="#i-wallet"/></svg><span class="fwm-nav__label">Banking</span></a>
                    <a class="fwm-nav__item" href="#numbering" data-sec="numbering"><svg class="i"><use href="#i-hash"/></svg><span class="fwm-nav__label">Numbering</span></a>
                    <a class="fwm-nav__item" href="#defaults" data-sec="defaults"><svg class="i"><use href="#i-settings"/></svg><span class="fwm-nav__label">Defaults</span></a>
                    <a class="fwm-nav__item" href="#emails" data-sec="emails"><svg class="i"><use href="#i-mail"/></svg><span class="fwm-nav__label">Email wording</span></a>
                    <a class="fwm-nav__item" href="#payments" data-sec="payments"><svg class="i"><use href="#iq-pay"/></svg><span class="fwm-nav__label">Card payments</span></a>
                </nav>
                <div class="fwm-nav__head"><h3>Also</h3></div>
                <nav class="fwm-nav" aria-label="More">
                    <a class="fwm-nav__item" href="/qi/designer.php"><svg class="i"><use href="#iq-designer"/></svg><span class="fwm-nav__label">Document designer</span></a>
                    <a class="fwm-nav__item" href="/qi/"><svg class="i"><use href="#iq-overview"/></svg><span class="fwm-nav__label">Back to Quotes &amp; Invoices</span></a>
                </nav>
            </div>
        </aside>

        <section class="fwm-pane fwq-main" aria-label="Settings">
            <div class="fwq-head">
                <div class="fwq-head__title"><div class="fwq-eyebrow"><?= htmlspecialchars($companyName) ?></div><h1>Quotes &amp; Invoices <em>settings</em></h1></div>
            </div>
            <div class="fwq-view scroll" id="setView"><div class="fwq-set" id="setBody"><div class="fwq-skel" style="height:220px"></div></div></div>
        </section>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script src="/qi/js/qi-core.js?v=<?= $qiV('qi/js/qi-core.js') ?>"></script>
<script src="/qi/js/settings.js?v=<?= $qiV('qi/js/settings.js') ?>"></script>
</body>
</html>
