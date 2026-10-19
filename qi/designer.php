<?php
// /qi/designer.php — the document designer: the look of quotes, invoices and
// credit notes, with a live preview rendered by the same code as the PDF.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/QiSchema.php';
require_once __DIR__ . '/lib/QiTheme.php';

$companyId = (int)$_SESSION['company_id'];
try { QiSchema::ensure($DB); } catch (Throwable $e) { error_log('[qi/designer] ' . $e->getMessage()); }
$stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyName = (string)($stmt->fetchColumn() ?: 'Company');

// Font samples for the pickers: every document font, subset to the characters of the names
$names = array_column(QiTheme::FONTS, 0);
$chars = implode('', array_unique(preg_split('//u', implode('', $names) . 'Aa', -1, PREG_SPLIT_NO_EMPTY)));
$fams = implode('&', array_map(fn($f) => 'family=' . $f[2], QiTheme::FONTS));
$docCss = (string)(@filemtime(__DIR__ . '/css/doc.css') ?: '1');

$pageTitle = 'Document designer';
$bodyExtraClass = 'fwq-ds';
$headExtra = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?' . htmlspecialchars($fams) . '&text=' . rawurlencode($chars) . '&display=swap">'
           . '<link rel="stylesheet" href="/qi/css/doc.css?v=' . $docCss . '">';
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwq-app">
    <?php $withDrawer = false; include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwq-designer" id="designer">
        <aside class="fwm-pane fwq-ds-panel" aria-label="Design settings">
            <div class="fwq-head" style="min-height:0;padding-bottom:10px">
                <div class="fwq-head__title">
                    <div class="fwq-eyebrow">Quotes &amp; Invoices</div>
                    <h1>Document <em>designer</em></h1>
                </div>
            </div>
            <div class="fwq-ds-panel__scroll scroll" id="dsSections">
                <div class="fwq-skel" style="height:54px;margin:10px"></div>
                <div class="fwq-skel" style="height:54px;margin:10px"></div>
                <div class="fwq-skel" style="height:54px;margin:10px"></div>
            </div>
            <div class="fwq-ds-panel__foot">
                <span class="fwq-ds-dirty" id="dsDirty">Loading…</span>
                <span class="grow"></span>
                <button class="icon-btn" id="dsUndo" type="button" aria-label="Go back to the previous saved design" data-tip="Undo last save" disabled><svg class="i"><use href="#i-undo"/></svg></button>
                <button class="btn btn--primary" id="dsSave" type="button" disabled><svg class="i"><use href="#i-check"/></svg>Save design</button>
            </div>
        </aside>

        <section class="fwm-pane fwq-stage" aria-label="Live preview">
            <div class="fwq-stage__bar">
                <div class="seg" id="dsType" role="tablist" aria-label="Document">
                    <button type="button" class="is-on" data-type="quote">Quote</button>
                    <button type="button" data-type="invoice">Invoice</button>
                    <button type="button" data-type="credit_note">Credit note</button>
                </div>
                <div class="seg hide-narrow" id="dsSource" aria-label="Content">
                    <button type="button" class="is-on" data-source="sample">Sample</button>
                    <button type="button" data-source="latest" id="dsLatest">My latest</button>
                </div>
                <span class="fwq-grow"></span>
                <span class="fwq-stage__hint hide-narrow"><svg class="i i--sm"><use href="#i-sparkle"/></svg>Click any part of the document to change it</span>
                <button class="icon-btn hide-narrow" id="dsZoomOut" type="button" aria-label="Zoom out" data-tip="Zoom out"><svg class="i"><use href="#i-zoom-out"/></svg></button>
                <button class="btn btn--sm btn--ghost hide-narrow" id="dsZoomFit" type="button">Fit</button>
                <button class="icon-btn hide-narrow" id="dsZoomIn" type="button" aria-label="Zoom in" data-tip="Zoom in"><svg class="i"><use href="#i-zoom-in"/></svg></button>
                <button class="btn btn--sm" id="dsPdf" type="button"><svg class="i"><use href="#i-download"/></svg>PDF preview</button>
                <button class="btn btn--sm only-narrow" id="dsTogglePreview" type="button"><svg class="i"><use href="#iq-eye-doc"/></svg>Preview</button>
            </div>
            <div class="fwq-stage__scroll scroll" id="stageScroll">
                <div class="fwq-paper-wrap" id="paperWrap"><div class="fwq-paper" id="paper"></div></div>
            </div>
        </section>
    </div>
</div>
<form id="pdfForm" method="post" action="/qi/api/design.php" target="_blank" hidden>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
    <input type="hidden" name="action" value="pdf">
    <input type="hidden" name="design" id="pdfDesign">
    <input type="hidden" name="type" id="pdfType">
    <input type="hidden" name="source" id="pdfSource">
</form>
<input type="file" id="logoFile" accept="image/png,image/jpeg,image/webp" hidden>
<style id="dsCustomCss"></style>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script src="/qi/js/qi-core.js?v=<?= $qiV('qi/js/qi-core.js') ?>"></script>
<script src="/qi/js/designer.js?v=<?= $qiV('qi/js/designer.js') ?>"></script>
</body>
</html>
