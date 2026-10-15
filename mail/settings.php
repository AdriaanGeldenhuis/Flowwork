<?php
// /mail/settings.php — accounts, signatures, labels, rules and preferences.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
require_once __DIR__ . '/lib/autoload.php';

$companyId = (int)$_SESSION['company_id'];
$isAdmin = fw_active_user_is_admin();
$companyName = 'Company';
try {
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn() ?: 'Company';
    MailSchema::ensure($DB);
} catch (Throwable $e) {
    error_log('[mail/settings] ' . $e->getMessage());
}

$pageTitle = 'Mail settings';
$withSearch = false;
$withSync = false;
include __DIR__ . '/partials/head.php';
?>
<div class="fwm-page" id="settingsApp" data-admin="<?= $isAdmin ? '1' : '0' ?>">
    <?php include __DIR__ . '/partials/topbar.php'; ?>
    <div class="fwm-page__body">
        <aside class="fwm-page__nav fwm-side" id="side" aria-label="Settings sections">
            <a class="fwm-page__back" href="/mail/"><svg class="i"><use href="#i-arrow-left"/></svg>Back to Mail</a>
            <nav class="fwm-nav" id="settingsNav">
                <a class="fwm-nav__item" href="#accounts" data-tab="accounts"><svg class="i"><use href="#i-at"/></svg><span class="fwm-nav__label">Accounts</span></a>
                <a class="fwm-nav__item" href="#signatures" data-tab="signatures"><svg class="i"><use href="#i-signature"/></svg><span class="fwm-nav__label">Signatures</span></a>
                <a class="fwm-nav__item" href="#labels" data-tab="labels"><svg class="i"><use href="#i-tag"/></svg><span class="fwm-nav__label">Labels</span></a>
                <a class="fwm-nav__item" href="#rules" data-tab="rules"><svg class="i"><use href="#i-filter"/></svg><span class="fwm-nav__label">Rules</span></a>
                <a class="fwm-nav__item" href="#preferences" data-tab="preferences"><svg class="i"><use href="#i-settings"/></svg><span class="fwm-nav__label">Preferences</span></a>
                <a class="fwm-nav__item" href="/mail/templates.php"><svg class="i"><use href="#i-template"/></svg><span class="fwm-nav__label">Templates</span><svg class="i i--xs"><use href="#i-external"/></svg></a>
            </nav>
        </aside>
        <main class="fwm-pane fwm-page__main">
            <div class="fwm-page__scroll scroll">
                <div class="fwm-page__content">
                    <section class="fwm-panel" data-panel="accounts">
                        <div class="fwm-page__head"><div><h1>Accounts</h1><p>Connect the mailboxes you want to read and send from in Flowwork.</p></div>
                            <button class="btn btn--primary" id="addAccount" type="button"><svg class="i i--sm"><use href="#i-plus"/></svg>Connect account</button></div>
                        <div id="accountsList"></div>
                    </section>
                    <section class="fwm-panel" data-panel="signatures">
                        <div class="fwm-page__head"><div><h1>Signatures</h1><p>Added to new messages and replies automatically. Choose a default per account under Accounts.</p></div>
                            <button class="btn btn--primary" id="addSignature" type="button"><svg class="i i--sm"><use href="#i-plus"/></svg>New signature</button></div>
                        <div id="signaturesList"></div>
                    </section>
                    <section class="fwm-panel" data-panel="labels">
                        <div class="fwm-page__head"><div><h1>Labels</h1><p>Organise conversations across folders. Labels are shared with your company.</p></div>
                            <button class="btn btn--primary" id="addLabel" type="button"><svg class="i i--sm"><use href="#i-plus"/></svg>New label</button></div>
                        <div id="labelsList"></div>
                    </section>
                    <section class="fwm-panel" data-panel="rules">
                        <div class="fwm-page__head"><div><h1>Rules</h1><p>Automatically label, star, archive or file new mail as it arrives.</p></div>
                            <button class="btn" id="runRules" type="button"><svg class="i i--sm"><use href="#i-play"/></svg>Run on inbox</button>
                            <button class="btn btn--primary" id="addRule" type="button"><svg class="i i--sm"><use href="#i-plus"/></svg>New rule</button></div>
                        <div id="rulesList"></div>
                    </section>
                    <section class="fwm-panel" data-panel="preferences">
                        <div class="fwm-page__head"><div><h1>Preferences</h1><p>Changes are saved as you make them.</p></div></div>
                        <div id="prefsForm"></div>
                    </section>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Account -->
<div class="fwm-modal" id="accountModal" hidden>
    <div class="fwm-dialog fwm-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="accountTitle">
        <div class="fwm-dialog__head"><h2 id="accountTitle">Connect an account</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <form class="fwm-dialog__body" id="accountForm" autocomplete="off">
            <input type="hidden" name="account_id">
            <div class="fwm-presets" id="presets"></div>
            <p class="hint" id="presetHint" style="margin:-8px 0 16px"></p>
            <div class="row">
                <div class="field"><label class="label" for="acDisplay">Your name</label><input class="input" id="acDisplay" name="display_name" placeholder="Shown to recipients"></div>
                <div class="field"><label class="label" for="acEmail">Email address</label><input class="input" id="acEmail" name="email_address" type="email" required placeholder="you@company.co.za" autocomplete="off"></div>
            </div>
            <div class="row">
                <div class="field"><label class="label" for="acPass">Password</label><input class="input" id="acPass" name="password" type="password" autocomplete="new-password" placeholder="Mailbox or app password"></div>
                <div class="field"><label class="label" for="acName">Label in Flowwork</label><input class="input" id="acName" name="account_name" placeholder="e.g. Work"></div>
            </div>
            <details class="fwm-details" id="serverDetails" style="margin-top:14px">
                <summary><svg class="i i--sm"><use href="#i-chev-right"/></svg>Server settings</summary>
                <div class="row">
                    <div class="field" style="grid-column:span 2"><label class="label" for="acImap">Incoming (IMAP) server</label><input class="input" id="acImap" name="imap_server" placeholder="mail.company.co.za"></div>
                    <div class="field"><label class="label" for="acImapPort">Port</label><input class="input" id="acImapPort" name="imap_port" type="number" min="1" max="65535" value="993"></div>
                    <div class="field"><label class="label" for="acImapEnc">Security</label><select class="select" id="acImapEnc" name="imap_encryption"><option value="ssl">SSL/TLS</option><option value="tls">STARTTLS</option><option value="none">None</option></select></div>
                </div>
                <div class="row">
                    <div class="field" style="grid-column:span 2"><label class="label" for="acSmtp">Outgoing (SMTP) server</label><input class="input" id="acSmtp" name="smtp_server" placeholder="mail.company.co.za"></div>
                    <div class="field"><label class="label" for="acSmtpPort">Port</label><input class="input" id="acSmtpPort" name="smtp_port" type="number" min="1" max="65535" value="587"></div>
                    <div class="field"><label class="label" for="acSmtpEnc">Security</label><select class="select" id="acSmtpEnc" name="smtp_encryption"><option value="tls">STARTTLS</option><option value="ssl">SSL/TLS</option><option value="none">None</option></select></div>
                </div>
                <div class="row">
                    <div class="field"><label class="label" for="acUser">Username</label><input class="input" id="acUser" name="username" placeholder="Usually your email address" autocomplete="off"></div>
                    <div class="field"><label class="label" for="acSig">Default signature</label><select class="select" id="acSig" name="default_signature_id"></select></div>
                </div>
                <div style="margin-top:12px">
                    <label class="switch"><input type="checkbox" name="tls_verify" checked><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Verify TLS certificate</span><span class="hint">Recommended. Turn off only if your host uses a self-signed certificate.</span></span></label>
                    <label class="switch"><input type="checkbox" name="save_sent_copy" checked><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Save sent mail on the server</span><span class="hint">Files a copy in your server's Sent folder so it shows on your phone too. Gmail and Microsoft 365 do this themselves.</span></span></label>
                    <label class="switch"><input type="checkbox" name="is_active" checked><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Active</span><span class="hint">Inactive accounts don't sync or send.</span></span></label>
                </div>
            </details>
            <div class="fwm-test-result" id="testResult"></div>
        </form>
        <div class="fwm-dialog__foot">
            <button class="btn" type="button" id="testAccount"><svg class="i i--sm"><use href="#i-play"/></svg>Test connection</button>
            <span class="grow"></span>
            <button class="btn" data-close type="button">Cancel</button>
            <button class="btn btn--primary" type="submit" form="accountForm" id="saveAccount">Save account</button>
        </div>
    </div>
</div>

<!-- Signature -->
<div class="fwm-modal" id="signatureModal" hidden>
    <div class="fwm-dialog fwm-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="signatureTitle">
        <div class="fwm-dialog__head"><h2 id="signatureTitle">New signature</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="field"><label class="label" for="sigName">Name</label><input class="input" id="sigName" maxlength="120" placeholder="e.g. Formal"></div>
            <div class="field"><span class="label">Signature</span><div id="sigEditor"></div><span class="hint">Tip: paste a signature from Word or your old email client — formatting and links are kept.</span></div>
            <div style="margin-top:10px">
                <label class="switch"><input type="checkbox" id="sigDefault"><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Use as my default</span></span></label>
                <label class="switch" id="sigSharedWrap" hidden><input type="checkbox" id="sigShared"><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Share with the whole company</span><span class="hint">Everyone in the company can use it; only admins can change it.</span></span></label>
            </div>
        </div>
        <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="saveSignature">Save signature</button></div>
    </div>
</div>

<!-- Label -->
<div class="fwm-modal" id="labelModal" hidden>
    <div class="fwm-dialog" role="dialog" aria-modal="true" aria-labelledby="labelTitle" style="width:min(420px,100%)">
        <div class="fwm-dialog__head"><h2 id="labelTitle">New label</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="field"><label class="label" for="labelName">Name</label><input class="input" id="labelName" maxlength="64"></div>
            <div class="field"><span class="label">Colour</span><div class="fwm-swatches" id="labelSwatches"></div></div>
        </div>
        <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="labelSave">Save label</button></div>
    </div>
</div>

<!-- Rule -->
<div class="fwm-modal" id="ruleModal" hidden>
    <div class="fwm-dialog fwm-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="ruleTitle">
        <div class="fwm-dialog__head"><h2 id="ruleTitle">New rule</h2>
            <button class="icon-btn" data-close aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button></div>
        <div class="fwm-dialog__body">
            <div class="row">
                <div class="field" style="grid-column:span 2"><label class="label" for="ruleName">Name</label><input class="input" id="ruleName" maxlength="120" placeholder="e.g. Supplier invoices"></div>
                <div class="field"><label class="label" for="rulePriority">Order</label><input class="input" id="rulePriority" type="number" min="1" max="999" value="100"></div>
            </div>
            <div class="fwm-section-title" style="display:flex;align-items:center;gap:10px">When a new message matches
                <span class="seg" id="ruleMatch"><button type="button" data-m="all" class="is-on">all</button><button type="button" data-m="any">any</button></span> of:</div>
            <div id="ruleConditions"></div>
            <button class="btn btn--sm btn--ghost" type="button" id="addCondition" style="margin-top:8px"><svg class="i i--sm"><use href="#i-plus"/></svg>Add condition</button>
            <div class="fwm-section-title">Do this</div>
            <div id="ruleActions"></div>
            <button class="btn btn--sm btn--ghost" type="button" id="addAction" style="margin-top:8px"><svg class="i i--sm"><use href="#i-plus"/></svg>Add action</button>
            <div style="margin-top:14px">
                <label class="switch"><input type="checkbox" id="ruleStop"><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Stop processing more rules</span></span></label>
                <label class="switch"><input type="checkbox" id="ruleActive" checked><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Active</span></span></label>
            </div>
        </div>
        <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="saveRule">Save rule</button></div>
    </div>
</div>

<div class="fwm-toasts" id="toasts" aria-live="polite"></div>
<script src="/mail/js/mail.js?v=<?= MAIL_ASSET_VERSION ?>"></script>
<script src="/mail/js/mail-settings.js?v=<?= MAIL_ASSET_VERSION ?>"></script>
</body>
</html>
