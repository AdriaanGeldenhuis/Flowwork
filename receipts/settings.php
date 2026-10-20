<?php
// /receipts/settings.php — how slips are read, what a new bill starts with,
// the checks that hold a bill back, uploads, and what Receipts has learned.
// Admins and bookkeepers; how slips are read is an admin's choice (the AI
// reader sends slips to OpenAI with the key from Suppliers AI).
require_once __DIR__ . '/lib/RcBoot.php';

if (!$RC_CAPS['settings']) {
    header('Location: /receipts/', true, 302);
    exit;
}
$S = $RC_SETTINGS;
$engines = RcReader::engines($DB, $RC_COMPANY_ID);
$isAdmin = $RC_ROLE === 'admin';
$lk = RcBills::lookups($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE);
$expense = array_values(array_filter($lk['accounts'], fn($a) => $a['type'] === 'expense'));
$defaultId = RcBills::defaultAccount($DB, $RC_COMPANY_ID, $S);
$defaultLabel = '';
foreach ($lk['accounts'] as $a) if ($a['id'] === $defaultId) $defaultLabel = $a['code'] . ' · ' . $a['name'];
$num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$driveOn = FlowDriveRepo::available($DB);
$filed = RcDrive::filed($DB, $RC_COMPANY_ID);

$readers = [
    'ai' => ['AI reader', 'Reads photos and PDFs like a person would — supplier, VAT number, lines and the likely account. Uses the OpenAI key under Suppliers AI; the slip and your expense account names are sent to OpenAI.',
             $engines['ai'] ? '' : 'Add an OpenAI key under Suppliers AI → Settings first.'],
    'tesseract' => ['Text recognition on this server', 'Free and private. Good with clear, flat slips and PDFs; you choose the accounts.',
                    $engines['tesseract'] ? '' : ($engines['pdftotext'] ? 'Only PDFs with text can be read here: photos need tesseract on the server.' : 'This server has no text recognition installed — PDFs and photos are typed in.')],
    'manual' => ['Type it in', 'Nothing is read. Slips are stored and you type the details while looking at them.', ''],
];

$page = 'settings';
$pageTitle = 'Settings';
$pageScripts = ['settings'];
include __DIR__ . '/partials/head.php';
?>
<header class="fwr-head">
    <div class="fwr-head__text">
        <p class="fwr-eyebrow">Receipts <i>◆</i> Settings</p>
        <h1>Settings</h1>
        <p>How slips are read, what a new bill starts with, and the checks that hold a bill back before it reaches the books. Changes are saved for everyone in <?= $e($RC_COMPANY['name']) ?>.</p>
    </div>
</header>

<form data-settings="receipts" novalidate>
<div class="fwr-section" id="reading"><h2>Reading</h2><span class="fwr-section__rule"></span></div>
<section class="fwr-panel">
    <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#ir-scan"/></svg></span>
        <div class="fwr-panel__title"><h3>How slips are read</h3><p><?= $isAdmin ? 'Nothing read is ever made up: what cannot be read is left for you to type.' : 'Only an admin can change how slips are read.' ?></p></div></div>
    <div class="fwr-readers fwr-set fwr-set--wide"><div class="fwr-choices">
        <?php foreach ($readers as $k => [$label, $hint, $warn]): ?>
            <label class="fwr-choice<?= $warn && $k === 'ai' ? ' is-off' : '' ?>">
                <input type="radio" name="ocr_provider" value="<?= $e($k) ?>"<?= $S['ocr_provider'] === $k ? ' checked' : '' ?><?= !$isAdmin || ($k === 'ai' && $warn && $S['ocr_provider'] !== 'ai') ? ' disabled' : '' ?>>
                <span><b><?= $k === 'ai' ? '<svg class="i i--sm"><use href="#ir-ai"/></svg>' : '' ?><?= $e($label) ?><?php if ($S['ocr_provider'] === $k): ?> <span class="fwr-pill" data-tone="success">In use</span><?php endif; ?></b>
                    <small><?= $e($hint) ?></small><?php if ($warn): ?><small class="fwr-choice__warn"><?= $e($warn) ?></small><?php endif; ?></span>
            </label>
        <?php endforeach; ?>
    </div></div>
    <div class="fwr-set" data-requires="ocr_provider=ai">
        <div class="fwr-set__text"><label class="fwr-set__label" for="ai_model">AI model</label><span class="fwr-set__hint">The smaller model is quick and costs little; the larger one reads difficult slips better.</span></div>
        <div class="fwr-set__control"><select class="select" id="ai_model" name="ai_model"<?= !$isAdmin ? ' disabled' : '' ?>>
            <?php foreach (RcKit::AI_MODELS as $k => $v): ?><option value="<?= $e($k) ?>"<?= $S['ai_model'] === $k ? ' selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <label class="fwr-set fwr-set--toggle">
        <span class="fwr-set__text"><span class="fwr-set__label">Split slips into their lines</span><span class="fwr-set__hint">When the items read add up to the total, each becomes a line of the bill. Off: one line for the whole slip.</span></span>
        <span class="switch"><input type="checkbox" name="auto_split_lines" value="1"<?= $S['auto_split_lines'] ? ' checked' : '' ?>><span class="switch__track"></span></span>
    </label>
</section>

<div class="fwr-section"><h2>New bills</h2><span class="fwr-section__rule"></span></div>
<section class="fwr-panel">
    <div class="fwr-set">
        <div class="fwr-set__text"><label class="fwr-set__label" for="default_expense_account">Account when nothing better is known</label>
            <span class="fwr-set__hint">Receipts first uses what it learned from earlier bills (the supplier, the words on the line). <?= $defaultLabel ? 'Now: <b>' . $e($defaultLabel) . '</b>.' : '' ?></span></div>
        <div class="fwr-set__control"><select class="select" id="default_expense_account" name="default_expense_account">
            <option value="">General expenses (Finance chooses)</option>
            <?php foreach ($expense as $a): ?><option value="<?= $e($a['code']) ?>"<?= $S['default_expense_account'] === $a['code'] ? ' selected' : '' ?>><?= $e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="fwr-set">
        <div class="fwr-set__text"><label class="fwr-set__label" for="default_due_days">Payment terms</label><span class="fwr-set__hint">Days after the slip date a bill is due when the slip shows no due date and the supplier has no earlier bill. 0 leaves it empty.</span></div>
        <div class="fwr-set__control"><div class="fwr-affix"><input class="input" id="default_due_days" name="default_due_days" type="number" min="0" max="180" step="1" value="<?= (int)$S['default_due_days'] ?>"><span>days</span></div></div>
    </div>
</section>

<div class="fwr-section" id="checks"><h2>Checks</h2><span class="fwr-section__rule"></span></div>
<section class="fwr-panel">
    <label class="fwr-set fwr-set--toggle">
        <span class="fwr-set__text"><span class="fwr-set__label">No VAT claim without a VAT number</span><span class="fwr-set__hint">SARS only allows input VAT on a tax invoice showing the supplier’s VAT number. On: such a bill is held until the number is added or the VAT is not claimed. Off: a warning.</span></span>
        <span class="switch"><input type="checkbox" name="require_vat_number" value="1"<?= $S['require_vat_number'] ? ' checked' : '' ?>><span class="switch__track"></span></span>
    </label>
    <label class="fwr-set fwr-set--toggle">
        <span class="fwr-set__text"><span class="fwr-set__label">Hold bills from suppliers missing compliance documents</span><span class="fwr-set__hint">The document types marked required in the CRM (tax clearance, COIDA, insurance…). An admin can still post one with a reason. Off: a warning.</span></span>
        <span class="switch"><input type="checkbox" name="block_expired_compliance" value="1"<?= $S['block_expired_compliance'] ? ' checked' : '' ?>><span class="switch__track"></span></span>
    </label>
    <div class="fwr-set">
        <div class="fwr-set__text"><label class="fwr-set__label" for="require_po_over_amount">Ask for a purchase order from</label><span class="fwr-set__hint">Bills of this amount or more need the order they are for (once you use purchase orders). 0 switches it off.</span></div>
        <div class="fwr-set__control"><div class="fwr-affix"><span>R</span><input class="input" id="require_po_over_amount" name="require_po_over_amount" inputmode="decimal" value="<?= $e($num($S['require_po_over_amount'])) ?>" pattern="[0-9 ,.]*"></div></div>
    </div>
    <div class="fwr-set">
        <div class="fwr-set__text"><label class="fwr-set__label" for="variance_tolerance_pct">Bill against its purchase order</label><span class="fwr-set__hint">Warn when a bill differs from its order by more than this.</span></div>
        <div class="fwr-set__control"><div class="fwr-affix"><input class="input" id="variance_tolerance_pct" name="variance_tolerance_pct" type="number" min="0" max="100" step="0.5" value="<?= $e($num($S['variance_tolerance_pct'])) ?>"><span>%</span></div></div>
    </div>
    <div class="fwr-set">
        <div class="fwr-set__text"><label class="fwr-set__label" for="line_variance_tolerance_pct">Price jumps</label><span class="fwr-set__hint">Point out an item that costs this much more than the supplier’s average over the last six months.</span></div>
        <div class="fwr-set__control"><div class="fwr-affix"><input class="input" id="line_variance_tolerance_pct" name="line_variance_tolerance_pct" type="number" min="5" max="500" step="1" value="<?= $e($num($S['line_variance_tolerance_pct'])) ?>"><span>%</span></div></div>
    </div>
</section>

<div class="fwr-section"><h2>Sending in</h2><span class="fwr-section__rule"></span></div>
<section class="fwr-panel">
    <div class="fwr-set">
        <div class="fwr-set__text"><label class="fwr-set__label" for="max_file_size_mb">Largest file</label><span class="fwr-set__hint">Photos are made smaller on the phone before they are sent, so 15 MB is plenty.</span></div>
        <div class="fwr-set__control"><div class="fwr-affix"><input class="input" id="max_file_size_mb" name="max_file_size_mb" type="number" min="1" max="25" step="1" value="<?= (int)$S['max_file_size_mb'] ?>"><span>MB</span></div></div>
    </div>
    <div class="fwr-set fwr-set--wide">
        <div class="fwr-set__text"><span class="fwr-set__label">Where slips are kept</span>
            <span class="fwr-set__hint">In a folder the web cannot reach — a slip is only shown to people in <?= $e($RC_COMPANY['name']) ?> who may see it. When a bill is made, a copy goes on the bill in Finance as its supporting document. A slip behind a bill in the books cannot be deleted (SARS asks for five years).</span></div>
    </div>
    <label class="fwr-set fwr-set--toggle">
        <span class="fwr-set__text"><span class="fwr-set__label">File posted receipts in FlowWork Drive</span>
            <span class="fwr-set__hint">When a bill is posted, its slip goes to <b>Suppliers › <i>supplier</i> › Receipts</b> in FlowWork Drive, beside their invoices and compliance documents — dated and named by their number. Everyone in <?= $e($RC_COMPANY['name']) ?> can open the drive, so drafts and slips set aside are never filed.
            <?= $driveOn ? ($filed ? $e(number_format($filed, 0, '.', ' ')) . ' filed so far.' : 'Older posted receipts are filed in the background once this is on.') : 'FlowWork Drive is not set up on this server.' ?>
            <?= $isAdmin ? '' : 'Only an admin can change this.' ?></span></span>
        <span class="switch"><input type="checkbox" name="publish_to_drive" value="1"<?= $S['publish_to_drive'] ? ' checked' : '' ?><?= !$isAdmin || !$driveOn ? ' disabled' : '' ?>><span class="switch__track"></span></span>
    </label>
</section>
</form>

<div class="fwr-section" id="learning"><h2>Learning</h2><span class="fwr-section__rule"></span></div>
<section class="fwr-panel">
    <div class="fwr-panel__head"><span class="fwr-panel__icon"><svg class="i"><use href="#ir-ai"/></svg></span>
        <div class="fwr-panel__title"><h3>What Receipts has learned</h3><p>From every bill saved: which supplier a printed name is, the account a supplier usually goes to, and the account words point to. Forget anything that is wrong.</p></div>
        <button class="fwr-mini fwr-mini--danger" type="button" id="forgetAll"><svg class="i"><use href="#i-trash"/></svg>Forget everything</button></div>
    <div id="learnBody"><div class="fwr-loading"><span class="fwr-spin"></span>Loading…</div></div>
</section>
<?php include __DIR__ . '/partials/foot.php'; ?>
