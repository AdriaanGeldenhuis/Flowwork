<?php
// /suppliers_ai/api/settings.php — the company's Suppliers AI settings.
//   GET  op=get
//   POST op=save {radius_km, min_score (0–100), max_recipients, per_hour, per_day, compliant_only}   admins and bookkeepers
//        op=rules {synonyms{}, categories{}, allow_list{phones,domains,names}, deny_list{…}}       admins and bookkeepers
//        op=template {subject, body} | {reset: true}                                              admins and bookkeepers
//        op=keys {openai_key, openai_on, openai_model, google_key, forget: openai|google}          admins (the keys are never sent back)
//        op=test_ai   op=test_google                                                               admins
require_once __DIR__ . '/_bootstrap.php';

$caps = SaKit::caps($SA_ROLE);
$out = function () use ($DB, $SA_COMPANY_ID, $caps) {
    $s = SaKit::settings($DB, $SA_COMPANY_ID);
    return ['settings' => SaKit::publicSettings($s), 'template' => SaKit::template($DB, $SA_COMPANY_ID), 'defaults' => SaKit::RFQ_TEMPLATE, 'placeholders' => SaKit::PLACEHOLDERS,
            'caps' => $caps, 'quota' => SaKit::quota($DB, $SA_COMPANY_ID, $s), 'town' => (string)SaKit::company($DB, $SA_COMPANY_ID)['town'],
            'trades' => array_map(fn($k, $t) => ['key' => $k, 'label' => $t[0], 'words' => array_map(fn($w) => rtrim($w, '$'), $t[4])], array_keys(SaParse::TRADES), SaParse::TRADES)];
};

if (sa_op() === 'get') sa_json($out());
sa_post_only();
$in = sa_input();
$num = function (string $k, float $min, float $max, string $label) use ($in): ?float {
    if (!array_key_exists($k, $in) || $in[$k] === '' || $in[$k] === null) return null;
    if (!is_numeric($in[$k])) throw new SaProblem($label . ' must be a number.', 422);
    $v = (float)$in[$k];
    if ($v < $min || $v > $max) throw new SaProblem($label . ' must be between ' . $min . ' and ' . $max . '.', 422);
    return $v;
};

switch (sa_op()) {
    case 'save':
        if (!$caps['configure']) throw new SaForbidden('Only admins and bookkeepers can change these settings.');
        $cols = [];
        if (($v = $num('radius_km', 1, 500, 'The search radius')) !== null) $cols['default_radius_km'] = round($v, 1);
        if (($v = $num('min_score', 0, 100, 'The minimum match')) !== null) $cols['min_score_threshold'] = round($v / 100, 2);
        if (($v = $num('max_recipients', 1, 50, 'Suppliers per request')) !== null) $cols['rfq_max_recipients'] = (int)$v;
        if (($v = $num('per_hour', 1, 500, 'Searches an hour')) !== null) $cols['per_hour_cap'] = (int)$v;
        if (($v = $num('per_day', 1, 5000, 'Searches a day')) !== null) $cols['daily_search_cap'] = (int)$v;
        SaKit::saveRow($DB, $SA_COMPANY_ID, $cols);
        if (array_key_exists('compliant_only', $in)) SaKit::saveCompanySetting($DB, $SA_COMPANY_ID, SaKit::COMPLIANT_KEY, !empty($in['compliant_only']) ? '1' : '0');
        SaKit::audit($DB, $SA_COMPANY_ID, $SA_USER_ID, 'sai_settings', 'ai_settings', null, ['changed' => array_keys($cols + (array_key_exists('compliant_only', $in) ? ['compliant_only' => 1] : []))]);
        sa_json($out());
    case 'rules':
        if (!$caps['configure']) throw new SaForbidden('Only admins and bookkeepers can change the search words.');
        $rules = SaKit::rules(['synonyms' => $in['synonyms'] ?? [], 'categories' => $in['categories'] ?? [], 'allow_list' => $in['allow_list'] ?? [], 'deny_list' => $in['deny_list'] ?? []]);
        SaKit::saveRow($DB, $SA_COMPANY_ID, ['rules_json' => json_encode($rules, JSON_UNESCAPED_UNICODE)]);
        SaKit::audit($DB, $SA_COMPANY_ID, $SA_USER_ID, 'sai_rules', 'ai_settings', null, ['synonyms' => count($rules['synonyms']), 'categories' => count($rules['categories']),
                     'deny' => count($rules['deny_list']['phones']) + count($rules['deny_list']['domains']) + count($rules['deny_list']['names'])]);
        sa_json($out());
    case 'template':
        if (!$caps['configure']) throw new SaForbidden('Only admins and bookkeepers can change the request email.');
        if (!empty($in['reset'])) {
            $DB->prepare("DELETE FROM company_settings WHERE company_id = ? AND setting_key = ?")->execute([$SA_COMPANY_ID, SaKit::TEMPLATE_KEY]);
        } else {
            $subject = SaKit::text($in['subject'] ?? '', 200);
            $body = SaKit::lines($in['body'] ?? '', 6000);
            if ($subject === '' || $body === '') throw new SaProblem('The email needs a subject and a message.', 422);
            if (preg_match('/\{budget\}/i', $subject . $body)) throw new SaProblem('The budget is never sent to suppliers — take {budget} out.', 422);
            SaKit::saveCompanySetting($DB, $SA_COMPANY_ID, SaKit::TEMPLATE_KEY, json_encode(['subject' => $subject, 'body' => $body], JSON_UNESCAPED_UNICODE));
        }
        SaKit::audit($DB, $SA_COMPANY_ID, $SA_USER_ID, 'sai_template', 'company_settings', null, ['reset' => !empty($in['reset'])]);
        sa_json($out());
    case 'keys':
        if (!$caps['keys']) throw new SaForbidden('Only admins can change the keys.');
        sa_rate_limit('sa-keys', 20, 600);
        $changes = [];
        $openai = trim((string)($in['openai_key'] ?? ''));
        if ($openai !== '') {
            if (!SaKit::validOpenAiKey($openai)) throw new SaProblem('That does not look like an OpenAI key (they start with “sk-”).', 422);
            $changes['openai_api_key'] = $openai;
        }
        $google = trim((string)($in['google_key'] ?? ''));
        if ($google !== '') {
            if (!SaKit::validGoogleKey($google)) throw new SaProblem('That does not look like a Google key (they start with “AIza”).', 422);
            $changes['google_places_key'] = $google;
        }
        if (($in['forget'] ?? '') === 'openai') $changes['openai_api_key'] = null;
        if (($in['forget'] ?? '') === 'google') $changes['google_places_key'] = null;
        if (array_key_exists('openai_on', $in)) $changes['openai_enabled'] = !empty($in['openai_on']) ? 1 : 0;
        if (array_key_exists('openai_model', $in)) {
            if (!isset(SaKit::AI_MODELS[(string)$in['openai_model']])) throw new SaProblem('Choose a model from the list.', 422);
            $changes['openai_model'] = (string)$in['openai_model'];
        }
        SaKit::saveKeys($DB, $SA_COMPANY_ID, $changes);
        SaKit::audit($DB, $SA_COMPANY_ID, $SA_USER_ID, 'sai_keys', 'ai_settings', null, ['changed' => array_keys($changes)]);
        sa_json($out());
    case 'test_ai':
        if (!$caps['keys']) throw new SaForbidden('Only admins can test the keys.');
        sa_rate_limit('sa-test', 10, 600);
        $s = SaKit::settings($DB, $SA_COMPANY_ID);
        sa_json(['test' => SaAi::test($s)]);
    case 'test_google':
        if (!$caps['keys']) throw new SaForbidden('Only admins can test the keys.');
        sa_rate_limit('sa-test', 10, 600);
        $s = SaKit::settings($DB, $SA_COMPANY_ID);
        if ($s['google_key'] === '') throw new SaProblem('Add the Google key first.', 409);
        sa_json(['test' => SaPlaces::test($s['google_key'], (string)SaKit::company($DB, $SA_COMPANY_ID)['town'])]);
}
sa_error('Unknown request.', 404);
