<?php
/**
 * Suppliers AI end to end, against the test database, with Google and OpenAI
 * answered by a fake transport (no network): the search (your suppliers by
 * tags and notes, what was billed, the never-suggest list, documents, Google
 * places merged with suppliers you have, AI ideas that never carry contact
 * details, the company's hourly ceiling), a saved search opened again, a
 * Google find added to the CRM as a prospect (and once only), and a request
 * for quotes from start to order: blocked suppliers left out, the email never
 * carries the budget, asking by WhatsApp, quotes compared with VAT, only the
 * owner (or the books) may change it, the award, the purchase order (numbered,
 * VAT taken out), replies noticed in Mail (in the conversation, from the
 * supplier's address, or sent later from the Outbox), and the tick's notices.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/suppliers_ai/lib/Sa.php';

$C = TEST_COMPANY_ID;
$A = TEST_USER_ID;
$M = (int)$DB->query("SELECT user_id FROM user_companies WHERE company_id = $C AND user_id <> $A AND role = 'member' ORDER BY user_id LIMIT 1")->fetchColumn();
if (!$M) $M = (int)$DB->query("SELECT user_id FROM user_companies WHERE company_id = $C AND user_id <> $A ORDER BY user_id LIMIT 1")->fetchColumn();
assert_true($M > 0, 'a second person in the company');
SaSchema::ensure($DB);
CrmSchema::ensure($DB);
$throws = function (callable $fn, string $class, string $msg, ?int $code = null) {
    try { $fn(); } catch (Throwable $e) {
        assert_true($e instanceof $class && ($code === null || $e->getCode() === $code), "$msg (got " . get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage() . ')');
        return;
    }
    fail("ASSERT FAILED: $msg (nothing was thrown)");
};
foreach (array_merge(glob(FLOWWORK_ROOT . '/storage/suppliers_ai/rl-*.json') ?: [], glob(FLOWWORK_ROOT . '/storage/suppliers_ai/geo-*.json') ?: []) as $f) @unlink($f);

// ---------------------------------------------------------------- fixtures (cleaned up at the end, and before: a run that died leaves them)
$fixtureNames = "(name LIKE 'Vaal Glass Works %' OR name LIKE 'Pipe Masters %' OR name LIKE 'Shady Plumbing %' OR name LIKE 'Bill Supplies %'
                  OR name LIKE 'Late Docs Plumbing %' OR name LIKE 'Geyser Pros %' OR notes LIKE 'Found with Suppliers AI%')";
$cleanAccounts = function (array $ids) use ($DB) {
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    foreach (['crm_interactions', 'crm_compliance_docs', 'crm_account_tags', 'crm_addresses', 'crm_contacts'] as $t) $DB->exec("DELETE FROM $t WHERE account_id IN ($in)");
    $DB->exec("DELETE FROM purchase_order_lines WHERE po_id IN (SELECT id FROM purchase_orders WHERE supplier_id IN ($in))");
    $DB->exec("DELETE FROM purchase_orders WHERE supplier_id IN ($in)");
    $DB->exec("DELETE FROM crm_accounts WHERE id IN ($in)");
};
$cleanAccounts($DB->query("SELECT id FROM crm_accounts WHERE company_id = $C AND $fixtureNames")->fetchAll(PDO::FETCH_COLUMN));
$sfx = substr(bin2hex(random_bytes(3)), 0, 6);
$made = ['accounts' => [], 'po' => [], 'docs' => [], 'emails' => []];
$settingsBefore = $DB->query("SELECT * FROM ai_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC) ?: null;
$blockBefore = $DB->query("SELECT setting_value FROM company_settings WHERE company_id = $C AND setting_key = 'crm_block_expired_suppliers'")->fetchColumn();
register_shutdown_function(function () use ($DB, $C, &$made, $settingsBefore, $blockBefore, $cleanAccounts, $fixtureNames) {
    // Settings first: they matter to every test that follows, and nothing below may stop them.
    if ($settingsBefore) {
        $DB->prepare("UPDATE ai_settings SET api_keys_json = ?, rules_json = ?, per_hour_cap = ?, daily_search_cap = ?, rfq_max_recipients = ? WHERE company_id = ?")
           ->execute([$settingsBefore['api_keys_json'], $settingsBefore['rules_json'], $settingsBefore['per_hour_cap'], $settingsBefore['daily_search_cap'], $settingsBefore['rfq_max_recipients'], $C]);
    }
    if ($blockBefore === false) $DB->exec("DELETE FROM company_settings WHERE company_id = $C AND setting_key = 'crm_block_expired_suppliers'");
    else $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, 'crm_block_expired_suppliers', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$C, $blockBefore]);
    foreach (glob(FLOWWORK_ROOT . '/storage/suppliers_ai/geo-*.json') ?: [] as $f) @unlink($f);
    if ($made['po']) { $DB->exec('DELETE FROM purchase_order_lines WHERE po_id IN (' . implode(',', $made['po']) . ')'); $DB->exec('DELETE FROM purchase_orders WHERE id IN (' . implode(',', $made['po']) . ')'); }
    if ($made['emails']) { $DB->exec('DELETE FROM email_links WHERE email_id IN (' . implode(',', $made['emails']) . ')'); $DB->exec('DELETE FROM emails WHERE email_id IN (' . implode(',', $made['emails']) . ')'); }
    if ($made['docs']) $DB->exec('DELETE FROM crm_compliance_docs WHERE id IN (' . implode(',', $made['docs']) . ')');
    $cleanAccounts(array_merge($made['accounts'], $DB->query("SELECT id FROM crm_accounts WHERE company_id = $C AND $fixtureNames")->fetchAll(PDO::FETCH_COLUMN)));
});
// The policy is switched on further down; until then documents only inform.
$DB->exec("DELETE FROM company_settings WHERE company_id = $C AND setting_key = 'crm_block_expired_suppliers'");
Crm::flush();
$sup = function (string $name, array $extra = []) use ($DB, $C, &$made): int {
    $DB->prepare("INSERT INTO crm_accounts (company_id, type, name, phone, email, vat_no, notes, status, preferred, created_by, created_at) VALUES (?, 'supplier', ?, ?, ?, ?, ?, ?, ?, 1, NOW())")
       ->execute([$C, $name, $extra['phone'] ?? null, $extra['email'] ?? null, $extra['vat'] ?? null, $extra['notes'] ?? null, $extra['status'] ?? 'active', (int)($extra['preferred'] ?? 0)]);
    $id = (int)$DB->lastInsertId();
    $made['accounts'][] = $id;
    foreach ($extra['tags'] ?? [] as $t) CrmAccounts::addTag($DB, $C, $id, $t, null);
    return $id;
};
$glass = $sup("Vaal Glass Works $sfx", ['phone' => '016 555 0101', 'email' => "info@vaalglass-$sfx.co.za", 'preferred' => 1, 'tags' => ['Glass & aluminium']]);
$pipes = $sup("Pipe Masters $sfx", ['phone' => '082 999 0001', 'email' => "quotes@pipemasters-$sfx.co.za", 'vat' => '4123456789', 'notes' => 'Geysers, blocked drains and burst pipes. Fast on call-outs.']);
$shady = $sup("Shady Plumbing $sfx", ['phone' => '083 000 1111', 'notes' => 'geyser work']);
$billed = $sup("Bill Supplies $sfx", ['phone' => '011 222 3333', 'email' => "sales@billsupplies-$sfx.co.za"]);
$late = $sup("Late Docs Plumbing $sfx", ['phone' => '084 444 5555', 'email' => "office@latedocs-$sfx.co.za", 'notes' => 'geyser installations']);
$DB->prepare("INSERT INTO ap_bills (company_id, supplier_id, vendor_invoice_number, issue_date, subtotal, tax, total, status, created_by, created_at) VALUES (?, ?, ?, '2026-09-10', 4200, 630, 4830, 'posted', 1, NOW())")
   ->execute([$C, $billed, "INV-$sfx"]);
$bill = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO ap_bill_lines (bill_id, item_description, quantity, unit, unit_price, tax_rate, line_total) VALUES (?, 'Geyser 150L supply and fit', 1, 'ea', 4200, 15, 4200)")->execute([$bill]);

// ---------------------------------------------------------------- your suppliers
$s = SaSearch::run($DB, $C, $A, 'admin', 'glass suppliers in vanderbijlpark', ['sources' => ['crm' => true, 'google' => false, 'ai' => false]]);
$row = fn(array $res, string $name) => current(array_filter($res['rows'], fn($r) => str_starts_with($r['name'], $name))) ?: null;
$g = $row($s, 'Vaal Glass Works');
assert_true($g !== null, 'a supplier tagged "Glass & aluminium" is found for "glass suppliers" (the old search found nobody without a known category)');
assert_true(in_array('Tagged Glass & aluminium', array_column($g['reasons'], 't'), true) && in_array('Preferred supplier', array_column($g['reasons'], 't'), true), 'with the reasons in words');
assert_eq('Vanderbijlpark', $s['place'], 'the place as read');
assert_eq('27165550101', $g['dial'], 'a number WhatsApp can dial');
assert_true($s['query_id'] > 0 && (int)$DB->query("SELECT external FROM ai_queries WHERE id = {$s['query_id']}")->fetchColumn() === 0, 'saved, and not counted as a paid look-up');

$rules = SaKit::rules(['deny_list' => ['names' => ["shady plumbing $sfx"]]]);
SaKit::saveRow($DB, $C, ['rules_json' => json_encode($rules)]);
$s = SaSearch::run($DB, $C, $A, 'admin', 'geyser replacement', ['sources' => ['crm' => true, 'google' => false, 'ai' => false]]);
assert_true($row($s, 'Pipe Masters') !== null, 'found by your notes ("Geysers, blocked drains…")');
assert_true(in_array('Your notes mention “Geysers”', array_column($row($s, 'Pipe Masters')['reasons'], 't'), true), 'and says so');
$b = $row($s, 'Bill Supplies');
assert_true($b !== null, 'a supplier who billed you for a geyser is found without the word in their name');
assert_true(str_contains(implode(' ', array_column($b['reasons'], 't')), 'Billed you for “Geyser 150L supply and fit”') && str_contains(implode(' ', array_column($b['reasons'], 't')), "R 4\u{00A0}830.00"), 'the books see the line and its price with VAT');
assert_true($row($s, 'Shady Plumbing') === null, 'the never-suggest list leaves a supplier out');
assert_true((bool)array_filter($s['notes'], fn($n) => str_contains($n['text'], 'never-suggest')), 'and says how many it left out');
$bg = $row(SaSearch::get($DB, $C, $M, 'member', $s['query_id']), 'Bill Supplies');
assert_true($bg !== null && !str_contains(json_encode($bg['reasons'], JSON_UNESCAPED_UNICODE), '830') && str_contains(json_encode($bg['reasons'], JSON_UNESCAPED_UNICODE), 'bought from them before'), 'a member opening a bookkeeper\'s search does not see what was paid either');
$sm = SaSearch::run($DB, $C, $M, 'member', 'geyser replacement', ['sources' => ['crm' => true, 'google' => false, 'ai' => false]]);
$bm = $row($sm, 'Bill Supplies');
assert_true($bm !== null && !str_contains(implode(' ', array_column($bm['reasons'], 't')), 'R 4') && str_contains(implode(' ', array_column($bm['reasons'], 't')), 'bought from them before'), 'a member sees "bought from them before", not what was paid');
$throws(fn() => SaSearch::run($DB, $C, $M, 'viewer', 'geyser', []), SaForbidden::class, 'viewers do not search');
$throws(fn() => SaSearch::run($DB, $C, $A, 'admin', ' ', []), SaProblem::class, 'an empty search is refused', 422);

// ---------------------------------------------------------------- Google and AI (a fake transport: nothing leaves the machine)
$sent = [];
SaHttp::$transport = function (string $url, array $headers, string $body) use (&$sent, $sfx) {
    $sent[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
    $b = json_decode($body, true);
    if (str_contains($url, 'places.googleapis.com')) {
        if (str_ends_with((string)$b['textQuery'], ', South Africa')) return [200, json_encode(['places' => [['location' => ['latitude' => -26.7114, 'longitude' => 27.8379], 'formattedAddress' => 'Vanderbijlpark, South Africa']]])];
        return [200, json_encode(['places' => [
            ['id' => "gp-pipes-$sfx", 'displayName' => ['text' => 'Pipe Masters Vaal'], 'nationalPhoneNumber' => '082 999 0001', 'rating' => 4.4, 'userRatingCount' => 31, 'location' => ['latitude' => -26.70, 'longitude' => 27.84], 'googleMapsUri' => 'https://maps.google.com/?cid=1', 'businessStatus' => 'OPERATIONAL'],
            ['id' => "gp-pros-$sfx", 'displayName' => ['text' => "Geyser Pros $sfx"], 'formattedAddress' => '12 Main Rd, Vanderbijlpark', 'nationalPhoneNumber' => '016 933 1234', 'internationalPhoneNumber' => '+27 16 933 1234', 'websiteUri' => 'https://geyserpros.example',
             'rating' => 4.7, 'userRatingCount' => 85, 'location' => ['latitude' => -26.72, 'longitude' => 27.85], 'googleMapsUri' => 'https://maps.google.com/?cid=2', 'businessStatus' => 'OPERATIONAL', 'primaryTypeDisplayName' => ['text' => 'Plumber']],
            ['id' => "gp-gone-$sfx", 'displayName' => ['text' => 'Closed Plumbing'], 'businessStatus' => 'CLOSED_PERMANENTLY'],
            ['id' => "gp-far-$sfx", 'displayName' => ['text' => 'Far Away Plumbing'], 'nationalPhoneNumber' => '012 111 2222', 'rating' => 4.9, 'userRatingCount' => 12, 'location' => ['latitude' => -25.75, 'longitude' => 28.19], 'businessStatus' => 'OPERATIONAL'],
        ]])];
    }
    if (str_contains($url, 'api.openai.com')) {
        return [200, json_encode(['choices' => [['message' => ['content' => json_encode([
            'summary' => 'A plumber to replace geysers in Vanderbijlpark', 'category' => 'plumbing', 'search_terms' => ['geyser installation'], 'location' => null, 'quantity' => null,
            'needed_by' => null, 'budget_rand' => null, 'requirements' => ['COC', 'Insured'],
            'ideas' => [['name' => 'PlumbLink', 'kind' => 'Plumbing supply chain', 'why' => 'Stocks geysers and knows local plumbers.'],
                        ['name' => 'Call 0123456789 Plumbers', 'kind' => 'Plumber', 'why' => 'Made up.'],
                        ['name' => "Pipe Masters $sfx", 'kind' => 'Plumber', 'why' => 'Already known.']],
        ])]]]])];
    }
    return [404, '{}'];
};
SaKit::saveKeys($DB, $C, ['openai_api_key' => 'sk-test' . str_repeat('x', 30), 'openai_enabled' => 1, 'openai_model' => 'gpt-4o-mini', 'google_places_key' => 'AIzaTEST' . str_repeat('y', 31)]);
SaKit::saveRow($DB, $C, ['per_hour_cap' => 20, 'daily_search_cap' => 100]);
$s = SaSearch::run($DB, $C, $A, 'admin', 'need a plumber for geysers in Vanderbijlpark', ['radius' => 25]);
$google = array_values(array_filter($s['rows'], fn($r) => $r['source'] === 'google'));
assert_true($s['google'] && $s['read']['ai'], 'Google and AI were both asked');
assert_true((bool)array_filter($google, fn($r) => $r['name'] === "Geyser Pros $sfx"), 'a business near the site from Google');
$gp = current(array_filter($google, fn($r) => $r['name'] === "Geyser Pros $sfx"));
assert_true($gp['rating'] == 4.7 && $gp['distance_km'] !== null && $gp['distance_km'] < 5 && $gp['phone'] === '016 933 1234' && $gp['website'] === 'https://geyserpros.example', 'with its rating, distance, phone and website — as Google lists them');
assert_true(in_array('4.7★ from 85 Google reviews', array_column($gp['reasons'], 't'), true), 'the rating is a reason');
assert_true(!array_filter($s['rows'], fn($r) => $r['name'] === 'Closed Plumbing'), 'a business Google says closed for good is left out');
$far = current(array_filter($google, fn($r) => $r['name'] === 'Far Away Plumbing'));
assert_true($far && str_contains(implode(' ', array_column($far['reasons'], 't')), 'outside 25 km'), 'one outside the radius says so');
$pm = $row($s, 'Pipe Masters');
assert_true($pm['source'] === 'crm' && $pm['rating'] == 4.4 && !array_filter($google, fn($r) => $r['name'] === 'Pipe Masters Vaal'), 'a Google listing with your supplier\'s phone number adds its rating to your supplier instead of showing twice');
$ideas = array_column($s['ideas'], 'name');
assert_eq(['PlumbLink'], $ideas, 'AI ideas: a name with a phone number is dropped, one you already have is not repeated');
assert_true(str_starts_with($s['ideas'][0]['maps'], 'https://www.google.com/maps/search/'), 'an idea links to Google Maps, with no contact details');
assert_true(in_array('Insured', $s['read']['requirements'], true), 'requirements AI read are added');
$ga = current(array_filter($sent, fn($x) => str_contains($x['url'], 'places.googleapis.com') && !str_ends_with((string)$x['body']['textQuery'], ', South Africa')));
assert_true(str_contains($ga['body']['textQuery'], 'in Vanderbijlpark') && isset($ga['body']['locationBias']['circle']) && (bool)array_filter($ga['headers'], fn($h) => str_starts_with($h, 'X-Goog-FieldMask: ')), 'Google is asked for the words, the place and a circle around it');
$oa = current(array_filter($sent, fn($x) => str_contains($x['url'], 'openai.com')));
$oaText = json_encode($oa['body']);
assert_true(!str_contains($oaText, "Pipe Masters $sfx") && !str_contains($oaText, '082 999') && str_contains($oaText, 'need a plumber for geysers'), 'OpenAI gets the request\'s words — never your suppliers or their numbers');
assert_true($oa['body']['response_format']['json_schema']['strict'] === true, 'and answers in strict JSON');
assert_eq(1, (int)$DB->query("SELECT external FROM ai_queries WHERE id = {$s['query_id']}")->fetchColumn(), 'the search counts against the ceiling');
SaKit::saveRow($DB, $C, ['per_hour_cap' => 1]);
$n = count($sent);
$s2 = SaSearch::run($DB, $C, $A, 'admin', 'plumber for geysers', []);
assert_true(count($sent) === $n && (bool)array_filter($s2['notes'], fn($x) => str_contains($x['text'], 'used up')), 'once the company\'s hourly ceiling is reached, Google and AI are not asked (and it says so)');
assert_true($row($s2, 'Pipe Masters') !== null, 'your own suppliers are still searched');
SaKit::saveRow($DB, $C, ['per_hour_cap' => 20]);

// ---------------------------------------------------------------- a saved search, opened again
$again = SaSearch::get($DB, $C, $M, 'member', $s['query_id']);
assert_eq(count($s['rows']), count($again['rows']), 'a search opens again with what it found');
assert_eq(['PlumbLink'], array_column($again['ideas'], 'name'), 'and its ideas');
assert_true(!$again['legacy'], 'it is not an old search');
$old = (int)$DB->query("SELECT id FROM ai_queries WHERE company_id = $C AND sources_json IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if ($old) assert_true(SaSearch::get($DB, $C, $A, 'admin', $old)['legacy'], 'an old search is marked as old');
$throws(fn() => SaSearch::get($DB, $C + 1000, $A, 'admin', $s['query_id']), SaProblem::class, 'another company\'s search is not here', 404);
$h = SaSearch::history($DB, $C, $A, ['q' => 'geysers']);
assert_true($h['total'] >= 2 && $h['rows'][0]['id'] === $s2['query_id'], 'searches, newest first, filtered by words');

// ---------------------------------------------------------------- a Google find becomes a supplier
$gpId = $gp['id'];
SaSearch::log($DB, $C, $A, $gpId, 'whatsapp');
assert_eq('whatsapp', $DB->query("SELECT action FROM ai_actions WHERE candidate_id = $gpId ORDER BY id DESC LIMIT 1")->fetchColumn(), 'a WhatsApp is noted (the action is no longer an enum)');
$throws(fn() => SaSearch::log($DB, $C, $A, $gpId, 'hack'), SaProblem::class, 'an unknown action is refused', 422);
$add = SaSearch::addToCrm($DB, $C, $A, 'admin', $gpId);
assert_true(!empty($add['account_id']), 'added to the CRM (' . json_encode($add) . ')');
$acc = $DB->query("SELECT * FROM crm_accounts WHERE id = " . (int)$add['account_id'])->fetch(PDO::FETCH_ASSOC);
assert_true($acc['status'] === 'prospect' && $acc['type'] === 'supplier' && str_contains((string)$acc['notes'], 'Found with Suppliers AI') && $acc['website'] === 'https://geyserpros.example', 'as a prospect supplier, with a note of where it came from');
assert_eq('Plumbing', $DB->query("SELECT t.name FROM crm_account_tags at JOIN crm_tags t ON t.id = at.tag_id WHERE at.account_id = " . (int)$add['account_id'])->fetchColumn(), 'tagged with its trade, so it is found next time');
$made['accounts'][] = (int)$add['account_id'];
assert_true(!empty(SaSearch::addToCrm($DB, $C, $A, 'admin', $gpId)['existing']), 'adding it twice is harmless');
$throws(fn() => SaSearch::addToCrm($DB, $C, $M, 'viewer', $gpId), SaForbidden::class, 'viewers do not add suppliers');

// ---------------------------------------------------------------- a request for quotes
$pipesCand = $pm['id'];
$req = SaRequests::create($DB, $C, $A, 'admin', ['title' => 'Replace 2 geysers', 'details' => "2 × 150 L geysers\nGround floor", 'quantity' => '2 geysers', 'location' => 'Vanderbijlpark',
    'category' => 'plumbing', 'requirements' => ['COC'], 'needed_by' => date('Y-m-d', strtotime('+10 days')), 'budget' => '9 500', 'query_id' => $s['query_id'],
    'suppliers' => [['account_id' => $pipes, 'candidate_id' => $pipesCand], ['candidate_id' => $gpId], ['account_id' => $billed]]]);
$rid = $req['id'];
assert_eq(3, $req['added'], 'three suppliers on the request');
$d = SaRequests::get($DB, $C, $A, 'admin', $rid);
assert_true($d['request']['status'] === 'draft' && $d['request']['budget'] === 950000 && $d['request']['quote_by'] === date('Y-m-d', strtotime('+5 days')), 'a draft with its budget and the quotes due in five days');
assert_true($d['can']['manage'] && !SaRequests::get($DB, $C, $M, 'member', $rid)['can']['manage'], 'the owner may change it; another member may only look');
$comp = SaRequests::compose($DB, $C, $A, 'admin', $rid);
assert_true(!str_contains($comp['body'] . $comp['subject'] . $comp['whatsapp'], '9 500') && !str_contains($comp['body'] . $comp['whatsapp'], 'R 9') && !str_contains(strtolower($comp['body']), 'budget'), 'the email and the WhatsApp never carry the budget');
assert_true(str_contains($comp['body'], '{first_name}') && str_contains($comp['body'], '2 × 150 L geysers') && str_contains($comp['body'], 'Please include: COC'), 'the email has what is needed, the requirements, and a name for each person');
$rows = fn() => array_column(SaRequests::get($DB, $C, $A, 'admin', $rid)['suppliers'], null, 'account_id');
$r = $rows();
$throws(fn() => SaRequests::quote($DB, $C, $M, 'member', $r[$pipes]['id'], ['amount' => 100]), SaForbidden::class, 'another member cannot write down quotes');
SaRequests::mark($DB, $C, $A, 'admin', $r[$pipes]['id'], 'whatsapp');
assert_eq('open', SaRequests::get($DB, $C, $A, 'admin', $rid)['request']['status'], 'asking one supplier opens the request');
assert_eq('whatsapp', $DB->query("SELECT type FROM crm_interactions WHERE account_id = $pipes ORDER BY id DESC LIMIT 1")->fetchColumn(), 'and the supplier\'s activity in the CRM shows the WhatsApp');
$throws(fn() => SaRequests::mark($DB, $C, $A, 'admin', $r[$pipes]['id'], 'nonsense'), SaProblem::class, 'an unknown change is refused', 422);
SaRequests::quote($DB, $C, $A, 'admin', $r[$pipes]['id'], ['amount' => '8 000,00', 'vat' => 'excl', 'lead_days' => '3']);
SaRequests::quote($DB, $C, $A, 'admin', $r[(int)$add['account_id']]['id'], ['amount' => '9 000', 'vat' => 'incl']);
$throws(fn() => SaRequests::quote($DB, $C, $A, 'admin', $r[$billed]['id'], ['amount' => 'lots']), SaProblem::class, 'a quote that is not an amount is refused', 422);
$d = SaRequests::get($DB, $C, $A, 'admin', $rid);
$by = array_column($d['suppliers'], null, 'account_id');
assert_eq(920000, $by[$pipes]['quote_incl'], 'R 8 000 plus VAT is R 9 200 with VAT');
assert_true($by[(int)$add['account_id']]['best'] && !$by[$pipes]['best'], 'R 9 000 with VAT is the best — not the R 8 000 that is before VAT');
assert_eq((int)$add['account_id'], $d['suppliers'][0]['account_id'], 'quotes are listed cheapest first, with VAT');
assert_true(!$by[$pipes]['over_budget'] && $d['summary']['quoted'] === 2 && $d['summary']['best'] === 900000, 'the summary compares them with the budget');
SaRequests::quote($DB, $C, $A, 'admin', $r[$billed]['id'], ['amount' => '1 000', 'vat' => 'incl']);
SaRequests::mark($DB, $C, $A, 'admin', $r[$billed]['id'], 'declined');
$d = SaRequests::get($DB, $C, $A, 'admin', $rid);
$by = array_column($d['suppliers'], null, 'account_id');
assert_true($d['summary']['best'] === 900000 && $d['summary']['quoted'] === 2 && !$by[$billed]['best'], 'a quote from someone who then declined is not compared (' . json_encode($d['summary']) . ')');
$throws(fn() => SaRequests::award($DB, $C, $A, 'admin', $r[$billed]['id']), SaProblem::class, 'someone who declined cannot be chosen', 409);
$throws(fn() => SaRequests::draft($DB, $C, $M, 'viewer', $rid), SaForbidden::class, 'a viewer cannot have AI write the email (it costs a look-up)');
$d = SaRequests::award($DB, $C, $A, 'admin', $r[$pipes]['id']);
$by = array_column($d['suppliers'], null, 'account_id');
assert_true($d['request']['status'] === 'awarded' && $by[$pipes]['status'] === 'awarded' && $by[(int)$add['account_id']]['status'] === 'not_chosen' && $by[$billed]['status'] === 'declined', 'chosen; the other quote is "not chosen"; the one who declined stays declined');
$throws(fn() => SaPo::create($DB, $C, $M, 'member', $rid), SaForbidden::class, 'members do not make purchase orders');
$po = SaPo::create($DB, $C, $A, 'admin', $rid);
$made['po'][] = $po['po_id'];
assert_true((bool)preg_match('/^PO-' . date('Y') . '-\d{4}$/', $po['number']), 'the order is numbered from the sequence (' . $po['number'] . ')');
$line = $DB->query("SELECT * FROM purchase_order_lines WHERE po_id = {$po['po_id']}")->fetch(PDO::FETCH_ASSOC);
assert_true((float)$line['unit_price'] === 8000.0 && (float)$line['tax_rate'] === 15.0, 'a VAT-registered supplier\'s "plus VAT" quote is the line\'s price, with 15% added by Procurement');
assert_eq(920000, $po['total'], 'and the order is R 9 200 with VAT');
assert_true(str_contains((string)$line['description'], 'Replace 2 geysers') && str_contains((string)$line['description'], 'Pipe Masters'), 'the line says what and from whom');
$throws(fn() => SaPo::create($DB, $C, $A, 'admin', $rid), SaProblem::class, 'one order per request', 409);
$throws(fn() => SaRequests::mark($DB, $C, $A, 'admin', $r[$pipes]['id'], 'declined'), SaProblem::class, 'the chosen supplier cannot be marked declined while chosen', 409);
$DB->exec("UPDATE purchase_orders SET status = 'approved' WHERE id = {$po['po_id']}");
$throws(fn() => SaRequests::unaward($DB, $C, $A, 'admin', $rid), SaProblem::class, 'the choice stays once its order is approved in Procurement', 409);
$DB->exec("UPDATE purchase_orders SET status = 'draft' WHERE id = {$po['po_id']}");
$d = SaRequests::unaward($DB, $C, $A, 'admin', $rid);
assert_true($d['request']['status'] === 'open' && $d['request']['po'] === null && $DB->query("SELECT status FROM purchase_orders WHERE id = {$po['po_id']}")->fetchColumn() === 'cancelled', 'undoing the choice cancels the draft order with it');
SaRequests::award($DB, $C, $A, 'admin', $r[$pipes]['id']);
$po = SaPo::create($DB, $C, $A, 'admin', $rid);
$made['po'][] = $po['po_id'];
assert_eq(920000, $po['total'], 'chosen again, a new order can be made');
assert_true(in_array('po', array_column(SaRequests::get($DB, $C, $A, 'admin', $rid)['events'], 'action'), true), 'the timeline has it all');

// ---------------------------------------------------------------- replies, noticed in Mail
$acct = (int)$DB->query("SELECT account_id FROM email_accounts WHERE company_id = $C LIMIT 1")->fetchColumn();
assert_true($acct > 0, 'a mailbox to play the conversation in');
$r2 = SaRequests::create($DB, $C, $A, 'admin', ['title' => 'Blocked drain', 'quote_by' => date('Y-m-d'), 'suppliers' => [['account_id' => $pipes], ['account_id' => $glass], ['account_id' => $billed]]])['id'];
$rr = array_column(SaRequests::get($DB, $C, $A, 'admin', $r2)['suppliers'], null, 'account_id');
$mail = function (array $v) use ($DB, $C, $acct, &$made): int {
    $DB->prepare("INSERT INTO emails (company_id, account_id, thread_id, folder, direction, sender, subject, sent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
       ->execute([$C, $acct, $v['thread'] ?? null, $v['folder'] ?? 'INBOX', $v['direction'] ?? 'incoming', $v['from'] ?? '', $v['subject'] ?? 'Re: quote', $v['at'] ?? date('Y-m-d H:i:s')]);
    return $made['emails'][] = (int)$DB->lastInsertId();
};
$thread = 900000 + random_int(1, 99999);
$sentAt = date('Y-m-d H:i:s', strtotime('-2 hours'));
$DB->prepare("UPDATE sai_request_suppliers SET sent_at = ?, channel = 'email', sent_by = ?, thread_id = ?, sent_to = ? WHERE id = ?")->execute([$sentAt, $A, $thread, "quotes@pipemasters-$sfx.co.za", $rr[$pipes]['id']]);
$DB->prepare("UPDATE sai_request_suppliers SET sent_at = ?, channel = 'email', sent_by = ?, sent_to = ? WHERE id = ?")->execute([$sentAt, $A, "info@vaalglass-$sfx.co.za", $rr[$glass]['id']]);
$DB->prepare("UPDATE sai_requests SET status = 'open' WHERE id = ?")->execute([$r2]);
$mail(['thread' => $thread, 'from' => 'someone@elsewhere.example', 'at' => date('Y-m-d H:i:s', strtotime('-3 hours'))]); // before the ask: not a reply
assert_eq(0, count(SaRequests::refresh($DB, $C, $r2)), 'an email from before the ask is not a reply');
$inThread = $mail(['thread' => $thread, 'from' => 'office@pipemasters.example', 'at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
$fromAddr = $mail(['from' => "info@vaalglass-$sfx.co.za", 'subject' => 'Our quote', 'at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))]);
$outbox = $mail(['folder' => 'Sent', 'direction' => 'outgoing', 'from' => 'me@concreto.example', 'thread' => $thread + 1]);
$DB->prepare("UPDATE emails SET to_recipients = ? WHERE email_id = ?")->execute(["sales@billsupplies-$sfx.co.za", $outbox]);
foreach ([['rfq', $r2], ['supplier', $billed]] as [$t, $i]) $DB->prepare("INSERT INTO email_links (company_id, email_id, linked_type, linked_id, created_by, created_at) VALUES (?, ?, ?, ?, 1, NOW())")->execute([$C, $outbox, $t, $i]);
$found = SaRequests::refresh($DB, $C, $r2);
assert_eq(2, count($found), 'two replies noticed: one in the conversation, one a new email from the supplier\'s address');
$rr = array_column(SaRequests::get($DB, $C, $A, 'admin', $r2)['suppliers'], null, 'account_id');
assert_true($rr[$pipes]['status'] === 'replied' && $rr[$glass]['status'] === 'replied', 'both are "Replied"');
assert_true($rr[$pipes]['reply_mail'] === '/mail/?open=e' . $inThread && $rr[$pipes]['mail'] === '/mail/?open=t' . $thread, 'with links to the conversation and the reply in Mail');
assert_true($rr[$billed]['status'] === 'asked' && $rr[$billed]['channel'] === 'email', 'an email that went later from the Outbox counts as asking');
assert_eq(0, count(SaRequests::refresh($DB, $C, $r2)), 'a reply is noticed once');
$r4 = SaRequests::create($DB, $C, $A, 'admin', ['title' => 'Shopfront glass', 'suppliers' => [['account_id' => $glass]]])['id'];
$g4 = SaRequests::get($DB, $C, $A, 'admin', $r4)['suppliers'][0]['id'];
$DB->prepare("UPDATE sai_request_suppliers SET sent_at = ?, channel = 'phone', sent_by = ? WHERE id = ?")->execute([$sentAt, $A, $g4]);
$DB->prepare("UPDATE sai_requests SET status = 'open' WHERE id = ?")->execute([$r4]);
assert_eq(0, count(SaRequests::refresh($DB, $C, $r4)), 'the email that answered one request is not also a reply on another to the same supplier');

// ---------------------------------------------------------------- the tick: replies and due quotes, told once
$DB->prepare("UPDATE sai_request_suppliers SET replied_at = NULL, reply_email_id = NULL WHERE id = ?")->execute([$rr[$pipes]['id']]);
$before = (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE company_id = $C AND user_id = $A AND type = 'suppliers_ai'")->fetchColumn();
$t1 = SaRequests::tick($DB, microtime(true) + 10);
assert_true($t1['replies'] >= 1 && $t1['due'] >= 1, 'the tick notices the reply and that quotes are due (' . json_encode($t1) . ')');
$titles = $DB->query("SELECT title FROM notifications WHERE company_id = $C AND user_id = $A AND type = 'suppliers_ai' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
assert_true((bool)array_filter($titles, fn($t) => str_contains($t, 'Pipe Masters') && str_contains($t, 'replied')) && (bool)array_filter($titles, fn($t) => str_contains($t, 'Blocked drain') && str_contains($t, 'due')), 'the person who asked is told (' . implode(' | ', $titles) . ')');
$t2 = SaRequests::tick($DB, microtime(true) + 10);
assert_eq(0, $t2['due'], 'and not told twice');
assert_true((int)$DB->query("SELECT COUNT(*) FROM notifications WHERE company_id = $C AND user_id = $A AND type = 'suppliers_ai'")->fetchColumn() - $before >= 2, 'the bell has both');

// ---------------------------------------------------------------- the list, counts, delete and restore
$c = SaRequests::counts($DB, $C, $A);
assert_true($c['open'] >= 1 && $c['mine'] >= 1, 'counts for the side bar and Home');
assert_true((bool)array_filter(SaRequests::list($DB, $C, $A, ['status' => 'awarded']), fn($x) => $x['id'] === $rid && $x['winner'] === "Pipe Masters $sfx" && $x['winning'] === 920000 && $x['po_id']), 'the awarded request shows its winner, the price with VAT and its order');
$throws(fn() => SaRequests::setState($DB, $C, $M, 'member', $r2, 'delete'), SaForbidden::class, 'another member cannot delete it');
SaRequests::setState($DB, $C, $A, 'admin', $r2, 'delete');
$throws(fn() => SaRequests::get($DB, $C, $A, 'admin', $r2)['request']['deleted'] ? throw new SaProblem('deleted', 404) : null, SaProblem::class, 'a deleted request is marked deleted', 404);
assert_true(!array_filter(SaRequests::list($DB, $C, $A, ['status' => 'all']), fn($x) => $x['id'] === $r2), 'and leaves the list');
SaRequests::setState($DB, $C, $A, 'admin', $r2, 'restore');
assert_true((bool)array_filter(SaRequests::list($DB, $C, $A, ['status' => 'all']), fn($x) => $x['id'] === $r2), 'restored');

// ---------------------------------------------------------------- the company's policy: expired documents block
$type = (int)$DB->query("SELECT id FROM crm_compliance_types WHERE company_id = $C ORDER BY id LIMIT 1")->fetchColumn();
assert_true($type > 0, 'a compliance document type to expire');
$DB->prepare("INSERT INTO crm_compliance_docs (company_id, account_id, type_id, expiry_date, status, created_at) VALUES (?, ?, ?, ?, 'expired', NOW())")->execute([$C, $late, $type, date('Y-m-d', strtotime('-20 days'))]);
$made['docs'][] = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, 'crm_block_expired_suppliers', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = '1'")->execute([$C]);
Crm::flush();
SaKit::saveKeys($DB, $C, ['google_places_key' => null, 'openai_enabled' => 0]);
$s = SaSearch::run($DB, $C, $A, 'admin', 'geyser installations', ['sources' => ['crm' => true, 'google' => false, 'ai' => false]]);
$lr = $row($s, 'Late Docs Plumbing');
$typeName = (string)$DB->query("SELECT name FROM crm_compliance_types WHERE id = $type")->fetchColumn();
assert_true($lr && $lr['blocked'] && in_array($lr['compliance']['status'], ['expired', 'missing'], true) && in_array("Expired: $typeName", $lr['compliance']['detail'], true), 'a supplier with an expired document is marked blocked (' . json_encode($lr['compliance'] ?? null) . ')');
assert_true(str_contains(implode(' ', array_column($lr['reasons'], 't')), 'Blocked by your company’s policy'), 'and says why');
$s = SaSearch::run($DB, $C, $A, 'admin', 'geyser installations', ['sources' => ['crm' => true], 'compliant_only' => true]);
assert_true($row($s, 'Late Docs Plumbing') === null, '"Documents in order" leaves them out');
$r3 = SaRequests::create($DB, $C, $A, 'admin', ['title' => 'Geyser install', 'suppliers' => [['account_id' => $late]]]);
assert_true($r3['added'] === 0 && str_contains($r3['skipped'][0]['why'] ?? '', 'policy blocks'), 'a blocked supplier is not put on a request (' . json_encode($r3['skipped']) . ')');
$a3 = SaRequests::addSuppliers($DB, $C, $A, 'admin', $r3['id'], array_fill(0, 60, ['account_id' => $late]));
assert_true($a3['added'] === 0 && count($a3['skipped']) >= 1, 'adding a blocked supplier later is refused too, however many times it is offered (' . json_encode($a3) . ')');

test_done('sai_engine');
