<?php
// /shopping/api/settings.php — preferences.
//   GET  op=get
//   POST op=save {route_mode, price_prompt, keep_awake, group_by, default_purpose, home_store_id, default_radius_km, ai?}
//   Everyone saves their own; only an admin can switch AI store ideas on or off for the company.
require_once __DIR__ . '/_bootstrap.php';

$op = sh_op();
$in = sh_is_post() ? sh_input() : $_GET;
[$C, $U, $R] = [$SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE];

$state = fn() => [
    'prefs' => ShKit::prefs($DB, $C, $U),
    'company' => ['ai' => ShKit::companySetting($DB, $C, ShKit::AI_KEY, '0') === '1', 'ai_key' => ShKit::aiKey($DB, $C) !== null, 'town' => ShKit::companyTown($DB, $C)],
];

switch ($op) {
    case 'get':
        sh_json($state());

    case 'save':
        sh_post_only();
        $v = [];
        if (isset($in['route_mode'])) {
            if (!isset(ShKit::ROUTE_MODES[$in['route_mode']])) throw new ShProblem('Choose driving, walking or cycling.', 422);
            $v['route_mode'] = $in['route_mode'];
        }
        if (isset($in['group_by'])) {
            if (!isset(ShKit::GROUP_BY[$in['group_by']])) throw new ShProblem('Choose how lists are grouped.', 422);
            $v['group_by'] = $in['group_by'];
        }
        if (isset($in['default_purpose'])) {
            if (!isset(ShKit::PURPOSES[$in['default_purpose']])) throw new ShProblem('Choose what new lists are for.', 422);
            $v['default_purpose'] = $in['default_purpose'];
        }
        foreach (['price_prompt', 'keep_awake'] as $k) if (array_key_exists($k, $in)) $v[$k] = !empty($in[$k]) ? 1 : 0;
        if (array_key_exists('home_store_id', $in)) $v['home_store_id'] = ShStores::storeId($DB, $C, $in['home_store_id']);
        if (array_key_exists('default_radius_km', $in)) {
            $km = ShKit::number($in['default_radius_km']);
            if ($km === null || $km < 1 || $km > 100) throw new ShProblem('How far to look is 1 to 100 km.', 422);
            $v['default_radius_km'] = round($km, 1);
        }
        if ($v) ShKit::savePrefs($DB, $C, $U, $v);
        if (array_key_exists('ai', $in)) {
            if (!ShKit::canConfigure($R)) throw new ShForbidden('Only an admin can switch AI store ideas on or off.');
            ShKit::saveCompanySetting($DB, $C, ShKit::AI_KEY, !empty($in['ai']) ? '1' : '0');
            ShKit::audit($DB, $C, $U, 'shopping_ai_setting', 'company', $C, ['on' => !empty($in['ai'])]);
        }
        sh_json($state());
}
sh_error('Unknown request.', 400);
