<?php
// /qi/edit.php — one editor for quotes, invoices, credit notes and recurring
// invoices, with the document drawn live beside the form exactly as it will
// be sent (same renderer as the PDF).
//   ?type=quote|invoice|credit_note|recurring  [&id=]  — edit, or create when no id
//   new documents: &customer_id= &project_id= &invoice_id= (credit note) &from=quote:12 (copy)
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/QiSchema.php';
require_once __DIR__ . '/lib/QiTheme.php';
require_once __DIR__ . '/lib/QiEditor.php';
require_once __DIR__ . '/lib/DocHtml.php';

$companyId = (int)$_SESSION['company_id'];
$role = strtolower((string)($_SESSION['role'] ?? 'viewer'));
try { QiSchema::ensure($DB); } catch (Throwable $e) { error_log('[qi/edit] schema: ' . $e->getMessage()); }

$type = in_array($_GET['type'] ?? '', QiWriter::TYPES, true) ? $_GET['type'] : 'invoice';
$id = max(0, (int)($_GET['id'] ?? 0));

// Viewers read documents; they never get the editor
if ($role === 'viewer') {
    header('Location: ' . ($id ? '/qi/view.php?type=' . $type . '&id=' . $id : '/qi/'));
    exit;
}

$payload = QiEditor::payload($DB, $companyId, $role, $type, $id, [
    'customer_id' => (int)($_GET['customer_id'] ?? 0),
    'project_id'  => (int)($_GET['project_id'] ?? 0),
    'invoice_id'  => (int)($_GET['invoice_id'] ?? 0),
    'from'        => (string)($_GET['from'] ?? ''),
]);
if ($payload === null) {
    header('Location: /qi/?missing=1');
    exit;
}

$stmt = $DB->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$companyName = (string)($companyRow['name'] ?? 'Company');
$design = QiTheme::load($DB, $companyId, $companyRow);

$nouns = ['quote' => 'quote', 'invoice' => 'invoice', 'credit_note' => 'credit note', 'recurring' => 'recurring invoice'];
$pageTitle = ($id ? 'Edit ' : 'New ') . $nouns[$type] . ($id && $payload['number'] !== '' ? ' ' . $payload['number'] : '');
$bodyExtraClass = 'fwq-ed-page';
$headExtra = DocHtml::headTags($design, (string)(@filemtime(__DIR__ . '/css/doc.css') ?: '1'));
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-app fwq-app">
    <?php $withDrawer = false; include __DIR__ . '/partials/topbar.php'; ?>

    <div class="fwq-editor" id="editor" data-type="<?= htmlspecialchars($type) ?>">
        <section class="fwm-pane fwq-ed-form" aria-label="Document details">
            <div class="fwq-head fwq-ed-head" id="edHead"></div>
            <div class="fwq-ed-scroll scroll" id="edScroll">
                <div class="fwq-ed-sections" id="edSections">
                    <div class="fwq-skel" style="height:120px;margin:14px"></div>
                    <div class="fwq-skel" style="height:220px;margin:14px"></div>
                </div>
            </div>
            <div class="fwq-ed-foot" id="edFoot"></div>
        </section>

        <section class="fwm-pane fwq-stage fwq-ed-stage" aria-label="Live preview">
            <div class="fwq-stage__bar">
                <span class="fwq-ed-stage__title"><svg class="i i--sm"><use href="#iq-eye-doc"/></svg>Live preview</span>
                <span class="fwq-grow"></span>
                <a class="btn btn--sm btn--ghost hide-narrow" href="/qi/designer.php" id="edDesign"><svg class="i"><use href="#iq-designer"/></svg>Change the look</a>
                <button class="icon-btn hide-narrow" id="edZoomOut" type="button" aria-label="Zoom out" data-tip="Zoom out"><svg class="i"><use href="#i-zoom-out"/></svg></button>
                <button class="btn btn--sm btn--ghost hide-narrow" id="edZoomFit" type="button">Fit</button>
                <button class="icon-btn hide-narrow" id="edZoomIn" type="button" aria-label="Zoom in" data-tip="Zoom in"><svg class="i"><use href="#i-zoom-in"/></svg></button>
                <button class="btn btn--sm fwq-ed-close" id="edClosePreview" type="button"><svg class="i"><use href="#i-x"/></svg>Close</button>
            </div>
            <div class="fwq-stage__scroll scroll" id="edStageScroll">
                <div class="fwq-paper-wrap" id="edPaperWrap"><div class="fwq-paper" id="edPaper"></div></div>
            </div>
        </section>
    </div>
</div>
<button class="fwm-fab fwq-ed-fab" id="edPreviewFab" type="button"><svg class="i"><use href="#iq-eye-doc"/></svg><span>Preview</span></button>
<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script id="qiBoot" type="application/json"><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/qi/js/qi-core.js?v=<?= $qiV('qi/js/qi-core.js') ?>"></script>
<script src="/qi/js/editor.js?v=<?= $qiV('qi/js/editor.js') ?>"></script>
</body>
</html>
