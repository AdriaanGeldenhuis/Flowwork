<?php
// /qi/view.php — one page for a quote, invoice, credit note or recurring
// invoice: the document as the customer sees it, what is owed and paid, the
// timeline, and every action the document's state allows.
//   ?type=quote|invoice|credit_note|recurring&id=
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/QiSchema.php';
require_once __DIR__ . '/lib/QiView.php';

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));
try { QiSchema::ensure($DB); } catch (Throwable $e) { error_log('[qi/view] schema: ' . $e->getMessage()); }

$type = in_array($_GET['type'] ?? '', ['quote', 'invoice', 'credit_note', 'recurring'], true) ? $_GET['type'] : 'invoice';
$id = max(0, (int)($_GET['id'] ?? 0));
$payload = $id ? QiView::payload($DB, $companyId, $userId, $role, $type, $id) : null;
if ($payload === null) {
    header('Location: /qi/?missing=1#' . ($type === 'credit_note' ? 'credit_notes' : ($type === 'recurring' ? 'recurring' : $type . 's')));
    exit;
}
$payload['today'] = date('Y-m-d');

$stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$companyName = (string)($companyRow['name'] ?? 'Company');
$design = QiTheme::load($DB, $companyId, $companyRow);

$nouns = ['quote' => 'Quote', 'invoice' => 'Invoice', 'credit_note' => 'Credit note', 'recurring' => 'Recurring invoice'];
$pageTitle = $nouns[$type] . ' ' . $payload['number'];
$bodyExtraClass = 'fwq-vw-page';
$headExtra = DocHtml::headTags($design, (string)(@filemtime(__DIR__ . '/css/doc.css') ?: '1'));
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwq-app">
    <?php $withDrawer = false; include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwq-viewer" id="viewer" data-type="<?= htmlspecialchars($type) ?>">
        <section class="fwm-pane fwq-vw-main" aria-label="<?= htmlspecialchars($nouns[$type]) ?>">
            <div class="fwq-head fwq-vw-head" id="vwHead"></div>
            <div class="fwq-vw-banner" id="vwBanner"></div>
            <div class="fwq-stage__scroll scroll" id="vwStage">
                <div class="fwq-vw-mobile" id="vwMobile"></div>
                <div class="fwq-paper-wrap" id="vwPaperWrap"><div class="fwq-paper" id="vwPaper"></div></div>
            </div>
            <div class="fwq-vw-zoom hide-narrow">
                <button class="icon-btn icon-btn--sm" id="vwZoomOut" type="button" aria-label="Zoom out"><svg class="i"><use href="#i-zoom-out"/></svg></button>
                <button class="btn btn--sm btn--ghost" id="vwZoomFit" type="button">Fit</button>
                <button class="icon-btn icon-btn--sm" id="vwZoomIn" type="button" aria-label="Zoom in"><svg class="i"><use href="#i-zoom-in"/></svg></button>
            </div>
        </section>
        <aside class="fwm-pane fwq-vw-rail scroll" id="vwRail" aria-label="Details"></aside>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="qiBoot" type="application/json"><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/qi/js/qi-core.js?v=<?= $qiV('qi/js/qi-core.js') ?>"></script>
<script src="/qi/js/view.js?v=<?= $qiV('qi/js/view.js') ?>"></script>
</body>
</html>
