<?php
// /mail/templates.php — reusable messages with {placeholders}.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';

$companyId = (int)$_SESSION['company_id'];
$isAdmin = fw_active_user_is_admin();
$companyName = 'Company';
$templates = [];
try {
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn() ?: 'Company';
    MailSchema::ensure($DB);
    $stmt = $DB->prepare("SELECT template_id, name, category, subject, body_html, updated_at FROM email_templates WHERE company_id = ? ORDER BY category, name");
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $templates[] = [
            'template_id' => (int)$t['template_id'], 'name' => $t['name'], 'category' => (string)$t['category'],
            'subject' => (string)$t['subject'], 'preview' => HtmlSanitizer::snippet(HtmlSanitizer::toText(HtmlSanitizer::ensureHtml((string)$t['body_html'])), 220),
        ];
    }
} catch (Throwable $e) {
    error_log('[mail/templates] ' . $e->getMessage());
}

$pageTitle = 'Templates';
$withSearch = false;
$withSync = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-page" id="templatesApp">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-page__body">
        <aside class="fwm-page__nav fwm-side" id="side" aria-label="Mail">
            <a class="fwm-page__back" href="/mail/"><svg class="i"><use href="#i-arrow-left"/></svg>Back to Mail</a>
            <nav class="fwm-nav" id="tplCategories"></nav>
        </aside>
        <main class="fwm-pane fwm-page__main">
            <div class="fwm-page__scroll scroll">
                <div class="fwm-page__content" style="max-width:1100px">
                    <div class="fwm-page__head"><div><h1>Templates</h1><p>Write once, send often. Placeholders such as <code>{first_name}</code> fill in from the recipient's CRM contact.</p></div>
                        <button class="btn btn--primary" id="addTemplate" type="button"><svg class="i i--sm"><use href="#i-plus"/></svg>New template</button></div>
                    <div class="fwm-grid" id="tplGrid"></div>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="fwm-modal" id="templateModal" hidden>
    <div class="fwm-dialog fwm-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="tplTitle">
        <div class="fwm-dialog__head"><h2 id="tplTitle">New template</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="row">
                <div class="field" style="grid-column:span 2"><label class="label" for="tplName">Name</label><input class="input" id="tplName" maxlength="120" placeholder="e.g. Quote follow-up"></div>
                <div class="field"><label class="label" for="tplCategory">Category</label><input class="input" id="tplCategory" maxlength="64" list="tplCategoryList" placeholder="e.g. Sales"><datalist id="tplCategoryList"></datalist></div>
            </div>
            <div class="field"><label class="label" for="tplSubject">Subject</label><input class="input" id="tplSubject" maxlength="500" placeholder="Following up on your quote"></div>
            <div class="field"><span class="label">Message</span><div id="tplEditor"></div>
                <div class="fwm-placeholders" id="tplPlaceholders" aria-label="Insert placeholder"></div>
                <span class="hint">Click a placeholder to insert it. Unknown placeholders are left for you to fill in when composing.</span></div>
        </div>
        <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="saveTemplate">Save template</button></div>
    </div>
</div>

<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script type="application/json" id="tplData"><?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/mail/js/mail.js?v=<?= MAIL_ASSET_VERSION ?>"></script>
<script src="/mail/js/mail-settings.js?v=<?= MAIL_ASSET_VERSION ?>"></script>
</body>
</html>
