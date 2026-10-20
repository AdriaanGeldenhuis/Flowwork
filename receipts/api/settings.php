<?php
// /receipts/api/settings.php — Receipts settings (admins and bookkeepers;
// how slips are read is an admin's choice, as the AI reader sends them out).
//   GET  op=get               settings, what the server can read with, the learning memory
//   POST op=save  {key: value…}   only the keys sent are checked and saved
//   POST op=forget what       all | name:<printed name> | supplier:<id> | word:<word>
require_once __DIR__ . '/_bootstrap.php';

if (!RcKit::canConfigure($RC_ROLE)) rc_error('Receipts settings are for admins and bookkeepers.', 403);
$op = rc_op();

if (!rc_is_post()) {
    rc_json(['settings' => RcKit::settings($DB, $RC_COMPANY_ID), 'engines' => RcReader::engines($DB, $RC_COMPANY_ID),
             'learning' => RcLearn::summary($DB, $RC_COMPANY_ID)]);
}

$in = rc_input();
if ($op === 'forget') {
    RcLearn::forget($DB, $RC_COMPANY_ID, (string)($in['what'] ?? ''));
    RcKit::audit($DB, $RC_COMPANY_ID, $RC_USER_ID, 'receipts_learning_forgotten', null, ['what' => (string)($in['what'] ?? '')]);
    rc_json(['learning' => RcLearn::summary($DB, $RC_COMPANY_ID)]);
}
if ($op !== 'save') rc_error('Unknown request.', 400);

$errors = [];
$out = [];
$num = function (string $k, float $min, float $max, string $msg, bool $money = false) use ($in, &$errors, &$out) {
    if (!array_key_exists($k, $in)) return;
    // "10 000", "10,000", "R10 000.50" and "2,5" read as people mean them
    $v = $money ? RcKit::cents($in[$k]) : RcKit::number($in[$k]);
    if ($v !== null && $money) $v /= 100;
    if ($v === null || $v < $min || $v > $max) { $errors[$k] = $msg; return; }
    $out[$k] = (float)$v;
};
$num('require_po_over_amount', 0, 100000000, 'Enter an amount (0 switches it off).', true);
$num('variance_tolerance_pct', 0, 100, 'Between 0 and 100 percent.');
$num('line_variance_tolerance_pct', 5, 500, 'Between 5 and 500 percent.');
$num('max_file_size_mb', 1, 25, 'Between 1 and 25 MB.');
$num('default_due_days', 0, 180, 'Between 0 and 180 days.');
foreach (['max_file_size_mb', 'default_due_days'] as $k) if (isset($out[$k])) $out[$k] = (int)$out[$k];
foreach (['require_vat_number', 'block_expired_compliance', 'auto_split_lines'] as $k) {
    if (array_key_exists($k, $in)) $out[$k] = !empty($in[$k]) && $in[$k] !== '0' ? 1 : 0;
}
if (array_key_exists('default_expense_account', $in)) {
    $code = trim((string)$in['default_expense_account']);
    if ($code !== '') {
        $st = $DB->prepare("SELECT 1 FROM gl_accounts WHERE company_id = ? AND account_code = ? AND is_active = 1 AND COALESCE(is_control, 0) = 0");
        $st->execute([$RC_COMPANY_ID, $code]);
        if (!$st->fetchColumn()) $errors['default_expense_account'] = 'Choose an active account that is not a control account.';
    }
    $out['default_expense_account'] = $code;
}
if (array_key_exists('ocr_provider', $in)) {
    $p = (string)$in['ocr_provider'];
    if (!isset(RcKit::READERS[$p])) $errors['ocr_provider'] = 'Choose how slips are read.';
    elseif ($RC_ROLE !== 'admin') $errors['ocr_provider'] = 'Only an admin can choose how slips are read.';
    elseif ($p === 'ai' && !RcReader::aiKey($DB, $RC_COMPANY_ID)) $errors['ocr_provider'] = 'Add an OpenAI key under Suppliers AI → Settings first.';
    else $out['ocr_provider'] = $p;
}
if (array_key_exists('ai_model', $in)) {
    if (!isset(RcKit::AI_MODELS[(string)$in['ai_model']])) $errors['ai_model'] = 'Choose a model.';
    elseif ($RC_ROLE !== 'admin') $errors['ai_model'] = 'Only an admin can change the AI reader.';
    else $out['ai_model'] = (string)$in['ai_model'];
}
if ($errors) rc_error('Some values need a second look.', 422, ['errors' => $errors]);
RcKit::saveSettings($DB, $RC_COMPANY_ID, $out);
RcKit::audit($DB, $RC_COMPANY_ID, $RC_USER_ID, 'receipts_settings_saved', null, ['changed' => $out]);
rc_json(['settings' => RcKit::settings($DB, $RC_COMPANY_ID)]);
