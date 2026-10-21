<?php
// /suppliers_ai/settings.php — the company's Suppliers AI settings (admins and
// bookkeepers; the keys are for admins):
//   where suppliers come from (the CRM, Google with the company's key, OpenAI
//   — the same key Receipts and Shopping use), searching (radius, minimum
//   match, documents, the company's hourly and daily ceilings, suppliers per
//   request), the company's own words (synonyms, trades, never-suggest and
//   always-ask lists) and the request-for-quote email. Keys are never sent
//   back to the browser: only "sk-…AbCd".
require_once __DIR__ . '/lib/SaBoot.php';

$page = 'settings';
$pageTitle = 'Settings';
if (!$SA_CAPS['configure']) {
    http_response_code(403);
    include __DIR__ . '/partials/head.php';
    echo '<div class="fsa-blank"><svg class="i"><use href="#i-lock"/></svg><h3>Settings are for admins and bookkeepers</h3><p>Ask one of them to change how Suppliers AI searches. <a href="/suppliers_ai/">Find suppliers</a></p></div>';
    include __DIR__ . '/partials/foot.php';
    exit;
}
$full = SaKit::settings($DB, $SA_COMPANY_ID);
$s = SaKit::publicSettings($full);
$tpl = SaKit::template($DB, $SA_COMPANY_ID);
$quota = SaKit::quota($DB, $SA_COMPANY_ID, $full);
require_once __DIR__ . '/../crm/lib/Crm.php';
$policy = CrmCompliance::policy($DB, $SA_COMPANY_ID);
$pageScripts = ['settings'];
$pageBoot = ['rules' => $full['rules'], 'template' => $tpl, 'defaults' => SaKit::RFQ_TEMPLATE, 'placeholders' => SaKit::PLACEHOLDERS,
             'builtin' => array_map(fn($k, $t) => ['key' => $k, 'label' => $t[0], 'icon' => $t[2], 'color' => $t[3], 'words' => array_map(fn($w) => rtrim($w, '$'), $t[4])], array_keys(SaParse::TRADES), SaParse::TRADES)];
include __DIR__ . '/partials/head.php';
$dis = $SA_CAPS['keys'] ? '' : ' disabled';
?>
<header class="fsa-head">
    <div class="fsa-head__text">
        <p class="fsa-eyebrow">Suppliers AI <i>◆</i> Settings</p>
        <h1>Make it <em>yours</em></h1>
        <p>Where suppliers come from, how they are searched and ranked, the words your team uses and the email suppliers get. The same search limits are in <a href="/admin/suppliers.php">Admin › Suppliers</a>.</p>
    </div>
</header>

<nav class="fsa-tabs" aria-label="Settings">
    <a class="fwm-chip is-on" href="#sources">Sources</a>
    <a class="fwm-chip" href="#search">Searching</a>
    <a class="fwm-chip" href="#words">Words</a>
    <a class="fwm-chip" href="#lists">Never &amp; always</a>
    <a class="fwm-chip" href="#email">The request email</a>
</nav>

<section class="fsa-section" id="sources">
    <h2>Where suppliers come from</h2><span class="fsa-section__rule"></span>
</section>
<div class="fsa-sourcecards">
    <article class="fsa-panel fsa-sourcecard is-on">
        <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-building"/></svg></span>
            <div class="fsa-panel__title"><h3>Your suppliers</h3><p>Always on. Suppliers in the CRM, what you bought in Finance and Procurement, and past quotes.</p></div>
            <span class="fsa-flag fsa-flag--ok">On</span></div>
        <div class="fsa-panel__body"><p class="fsa-copy">Give suppliers an industry, tags (“Plumbing”, “Aggregates”) and notes in the CRM — that is what they are found by. Found on Google and added here, they arrive as <b>prospects</b> tagged with their trade.</p></div>
    </article>

    <article class="fsa-panel fsa-sourcecard<?= $s['google_ready'] ? ' is-on' : '' ?>" id="googleCard">
        <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-globe"/></svg></span>
            <div class="fsa-panel__title"><h3>Google</h3><p>Real businesses near the site: phone, website, rating and distance — Places API (New), with your own Google key.</p></div>
            <span class="fsa-flag <?= $s['google_ready'] ? 'fsa-flag--ok' : '' ?>" id="googleState"><?= $s['google_ready'] ? 'Connected' : 'Off' ?></span></div>
        <div class="fsa-panel__body">
            <div class="field"><label class="label" for="googleKey">Google API key</label>
                <div class="fsa-keyrow"><input class="input" id="googleKey" type="password" autocomplete="off" spellcheck="false" placeholder="<?= $s['google_key_hint'] !== '' ? $e('Saved: ' . $s['google_key_hint'] . ' — type to replace') : 'AIza…' ?>"<?= $dis ?>>
                    <button class="btn" type="button" data-key-save="google"<?= $dis ?>>Save</button>
                    <button class="btn btn--ghost" type="button" data-key-test="google"<?= $s['google_ready'] && $SA_CAPS['keys'] ? '' : ' disabled' ?>>Test</button>
                    <?php if ($s['google_key_hint'] !== '' && $SA_CAPS['keys']): ?><button class="btn btn--ghost" type="button" data-key-forget="google">Remove</button><?php endif; ?></div>
                <small class="hint">In Google Cloud: create a key, switch on <b>Places API (New)</b> and billing, and restrict the key to that API. Google charges per search (about one look-up per search; the town is remembered for 30 days).</small>
            </div>
            <div class="fsa-test" id="googleTest" hidden></div>
        </div>
    </article>

    <article class="fsa-panel fsa-sourcecard<?= $s['ai_ready'] ? ' is-on' : '' ?>" id="aiCard">
        <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-sparkle"/></svg></span>
            <div class="fsa-panel__title"><h3>AI (OpenAI)</h3><p>Reads what was typed, finds better search words, suggests kinds of business to look at, and writes the request email. It never makes up phone numbers or businesses.</p></div>
            <span class="fsa-flag <?= $s['ai_ready'] ? 'fsa-flag--ok' : '' ?>" id="aiState"><?= $s['ai_ready'] ? 'On' : ($s['openai_key_hint'] !== '' ? 'Switched off' : 'Off') ?></span></div>
        <div class="fsa-panel__body">
            <label class="switch"><input type="checkbox" id="aiOn"<?= $s['openai_on'] ? ' checked' : '' ?><?= $dis ?>><span class="switch__track"></span>
                <span class="switch__text"><span class="switch__title">Use AI in Suppliers AI</span><span class="hint">Only the request’s words, today’s date and your town are sent — never supplier details or the budget.</span></span></label>
            <div class="row" style="margin-top:12px">
                <div class="field"><label class="label" for="aiKey">OpenAI key</label>
                    <input class="input" id="aiKey" type="password" autocomplete="off" spellcheck="false" placeholder="<?= $s['openai_key_hint'] !== '' ? $e('Saved: ' . $s['openai_key_hint'] . ' — type to replace') : 'sk-…' ?>"<?= $dis ?>></div>
                <div class="field"><label class="label" for="aiModel">Model</label>
                    <select class="select" id="aiModel"<?= $dis ?>><?php foreach (SaKit::AI_MODELS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $s['openai_model'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="fsa-keyrow" style="margin-top:10px">
                <button class="btn" type="button" data-key-save="openai"<?= $dis ?>>Save</button>
                <button class="btn btn--ghost" type="button" data-key-test="openai"<?= $s['openai_key_hint'] !== '' && $SA_CAPS['keys'] ? '' : ' disabled' ?>>Test</button>
                <?php if ($s['openai_key_hint'] !== '' && $SA_CAPS['keys']): ?><button class="btn btn--ghost" type="button" data-key-forget="openai">Remove</button><?php endif; ?>
            </div>
            <small class="hint">The same key reads slips in Receipts and suggests stores in Shopping. Each search uses about 1 000 tokens.</small>
            <div class="fsa-test" id="aiTest" hidden></div>
        </div>
    </article>
</div>
<?php if (!$SA_CAPS['keys']): ?><p class="fsa-hint fsa-hint--center"><svg class="i i--sm"><use href="#i-lock"/></svg> Only admins can change the keys.</p><?php endif; ?>

<section class="fsa-section" id="search"><h2>Searching</h2><span class="fsa-section__rule"></span></section>
<form class="fsa-panel fsa-sets" id="setForm" novalidate>
    <div class="fsa-set"><div class="fsa-set__text"><b>Search radius</b><small>How far from the site to look when nobody chooses.</small></div>
        <div class="fsa-set__control"><span class="fsa-unit"><input class="input" type="number" name="radius_km" min="1" max="500" step="1" value="<?= $e($s['radius_km']) ?>"><span>km</span></span></div></div>
    <div class="fsa-set"><div class="fsa-set__text"><b>Minimum match</b><small>Results that fit less well are folded away under “Show more” (never lost).</small></div>
        <div class="fsa-set__control fsa-range"><input type="range" name="min_score" min="0" max="100" step="5" value="<?= (int)round($s['min_score'] * 100) ?>"><output><?= (int)round($s['min_score'] * 100) ?></output></div></div>
    <label class="fsa-set fsa-set--toggle"><span class="fsa-set__text"><b>Only suppliers whose documents are in order</b><small>The starting point for each search (it can be changed per search). Google listings have no documents yet, so they are left out too.</small></span>
        <span class="switch"><input type="checkbox" name="compliant_only"<?= $s['compliant_only'] ? ' checked' : '' ?>><span class="switch__track"></span></span></label>
    <div class="fsa-set"><div class="fsa-set__text"><b>Suppliers per request</b><small>The most suppliers one request for quotes goes to.</small></div>
        <div class="fsa-set__control"><span class="fsa-unit"><input class="input" type="number" name="max_recipients" min="1" max="50" value="<?= (int)$s['max_recipients'] ?>"><span>suppliers</span></span></div></div>
    <div class="fsa-set"><div class="fsa-set__text"><b>Google and AI look-ups</b><small>Ceilings for the whole company, so a mistake cannot run up costs. Searching your own suppliers is never limited. Used: <b><?= (int)$quota['hour'] ?></b> this hour, <b><?= (int)$quota['day'] ?></b> today.</small></div>
        <div class="fsa-set__control fsa-pair"><span class="fsa-unit"><input class="input" type="number" name="per_hour" min="1" max="500" value="<?= (int)$s['per_hour'] ?>"><span>an hour</span></span>
            <span class="fsa-unit"><input class="input" type="number" name="per_day" min="1" max="5000" value="<?= (int)$s['per_day'] ?>"><span>a day</span></span></div></div>
    <div class="fsa-set"><div class="fsa-set__text"><b>Suppliers with expired documents</b><small>Your company <?= $policy['block'] ? '<b>blocks</b> them: they are not asked for quotes, cannot be chosen and get no orders until renewed' : 'warns about them but does not block them' ?>. Change it in Admin.</small></div>
        <div class="fsa-set__control"><a class="btn btn--ghost" href="/admin/suppliers.php"><svg class="i"><use href="#i-shield"/></svg>Admin › Suppliers</a></div></div>
</form>

<section class="fsa-section" id="words"><h2>Your words</h2><span class="fsa-section__rule"></span></section>
<div class="fsa-cols fsa-cols--two">
    <section class="fsa-panel">
        <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-link"/></svg></span>
            <div class="fsa-panel__title"><h3>When someone types…</h3><p>…also look for these words. “Geyser” → “water heater, plumbing”.</p></div></div>
        <div class="fsa-panel__body"><div class="fsa-pairs" id="synonyms"></div><button class="fsa-mini" type="button" data-add="synonyms"><svg class="i"><use href="#i-plus"/></svg>Add</button></div>
    </section>
    <section class="fsa-panel">
        <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-tag"/></svg></span>
            <div class="fsa-panel__title"><h3>Your own trades</h3><p>A trade and the words that mean it. Add words to a built-in trade by using its name (“Plumbing”).</p></div></div>
        <div class="fsa-panel__body"><div class="fsa-pairs" id="categories"></div><button class="fsa-mini" type="button" data-add="categories"><svg class="i"><use href="#i-plus"/></svg>Add</button>
            <details class="fsa-builtin"><summary>The trades Suppliers AI knows (<?= count(SaParse::TRADES) ?>)</summary><div id="builtin"></div></details></div>
    </section>
</div>

<section class="fsa-section" id="lists"><h2>Never suggest, always ask</h2><span class="fsa-section__rule"></span></section>
<div class="fsa-cols fsa-cols--two">
    <section class="fsa-panel">
        <div class="fsa-panel__head"><span class="fsa-panel__icon fsa-panel__icon--danger"><svg class="i"><use href="#i-ban"/></svg></span>
            <div class="fsa-panel__title"><h3>Never suggest</h3><p>Left out of every search. One a line.</p></div></div>
        <div class="fsa-panel__body fsa-lists" data-list="deny_list"></div>
    </section>
    <section class="fsa-panel">
        <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-bookmark"/></svg></span>
            <div class="fsa-panel__title"><h3>Always ask</h3><p>Ranked higher when they match. One a line.</p></div></div>
        <div class="fsa-panel__body fsa-lists" data-list="allow_list"></div>
    </section>
</div>

<section class="fsa-section" id="email"><h2>The request email</h2><span class="fsa-section__rule"></span></section>
<div class="fsa-cols fsa-cols--two fsa-cols--email">
    <section class="fsa-panel">
        <div class="fsa-panel__body fsa-form">
            <div class="field"><label class="label" for="tplSubject">Subject</label><input class="input" id="tplSubject" maxlength="200" value="<?= $e($tpl['subject']) ?>"></div>
            <div class="field" style="margin-top:12px"><label class="label" for="tplBody">Message</label><textarea class="textarea" id="tplBody" rows="14"><?= $e($tpl['body']) ?></textarea></div>
            <div class="fsa-placeholders" id="placeholders" aria-label="Insert"></div>
            <p class="fsa-hint"><svg class="i i--sm"><use href="#i-lock"/></svg> The budget is never put in the email. Each supplier gets their own email — they never see each other.</p>
        </div>
    </section>
    <section class="fsa-panel fsa-preview">
        <div class="fsa-panel__head"><span class="fsa-panel__icon"><svg class="i"><use href="#i-eye"/></svg></span>
            <div class="fsa-panel__title"><h3>How it reads</h3><p>With an example request.</p></div></div>
        <div class="fsa-paper" id="tplPreview"></div>
    </section>
</div>

<div class="fsa-savebar" id="savebar" role="region" aria-label="Unsaved changes">
    <span id="savebarText">Unsaved changes</span>
    <button class="btn btn--ghost" type="button" id="saveUndo">Undo</button>
    <button class="btn btn--primary" type="button" id="saveGo">Save</button>
</div>
<?php include __DIR__ . '/partials/foot.php'; ?>
