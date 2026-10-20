<?php
/**
 * Shopping lists end to end, against the test database: who sees and changes
 * a list (private = the owner only, team = everyone, viewers only look — the
 * old pages let anyone in the company change or close any list), adding
 * (the same thing again adds up; "5 × cement" + "10 bags cement" = 15 bags;
 * a missing price comes from the last one paid; the aisle a person chose is
 * remembered), ticking off (safe to repeat; the price book gets one row an
 * item, each = paid ÷ quantity; untick takes it out), finishing (keep, move or
 * remove what is left; the owner hears when someone else finishes), duplicate,
 * delete and restore, templates, purchase orders (numbered PO-YYYY-0001 …,
 * VAT taken out for a VAT-registered supplier, bought items refused), share
 * links (no money shown, ticks carry the guest's name, stopped or run-out
 * links do not open), stores and "Where to buy".
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/shopping/lib/Sh.php';

$C = TEST_COMPANY_ID;
$A = TEST_USER_ID;                       // admin
$M = (int)$DB->query("SELECT user_id FROM user_companies WHERE company_id = $C AND user_id <> $A ORDER BY user_id LIMIT 1")->fetchColumn();
assert_true($M > 0, 'a second person in the company');
ShSchema::ensure($DB);
$throws = function (callable $fn, string $class, string $msg, ?int $code = null) {
    try { $fn(); } catch (Throwable $e) {
        assert_true($e instanceof $class && ($code === null || $e->getCode() === $code), "$msg (got " . get_class($e) . ' ' . $e->getCode() . ': ' . $e->getMessage() . ')');
        return;
    }
    fail("ASSERT FAILED: $msg (nothing was thrown)");
};
$item = fn(int $list, string $name) => $DB->query("SELECT * FROM shopping_items WHERE list_id = $list AND name_raw = " . $DB->quote($name) . " AND status <> 'removed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$poIds = [];
register_shutdown_function(function () use ($DB, &$poIds) {
    if ($poIds) {
        $DB->exec('DELETE FROM purchase_order_lines WHERE po_id IN (' . implode(',', $poIds) . ')');
        $DB->exec('DELETE FROM purchase_orders WHERE id IN (' . implode(',', $poIds) . ')');
    }
});

// ---------------------------------------------------------------- who sees what
$private = ShLists::create($DB, $C, $M, 'member', ['name' => 'Catherine private', 'shared' => 'private'], ShParse::items('bread'));
$team = ShLists::create($DB, $C, $A, 'admin', ['name' => 'Site run', 'purpose' => 'procurement', 'shared' => 'team', 'budget' => '1000'], ShParse::items("10 bags cement @ R95\n2x PVC elbow 20mm\nmilk 2L"));
$throws(fn() => ShLists::access($DB, $C, $A, 'admin', $private), ShProblem::class, 'a private list is not there for anyone else — not even an admin', 404);
$throws(fn() => ShItems::toggle($DB, $C, $A, ShLists::access($DB, $C, $A, 'admin', $private, 'edit'), (int)$item($private, 'Bread')['id'], true), ShProblem::class, 'nor can its items be ticked by someone else', 404);
assert_eq(['Site run'], array_column(ShLists::cards($DB, $C, $A, 'admin'), 'name'), 'the admin sees only their own and team lists');
assert_true(in_array('Catherine private', array_column(ShLists::cards($DB, $C, $M, 'member'), 'name'), true) && in_array('Site run', array_column(ShLists::cards($DB, $C, $M, 'member'), 'name'), true), 'the member sees their own and the team list');
$throws(fn() => ShLists::access($DB, $C, $M, 'viewer', $team, 'edit'), ShForbidden::class, 'a viewer can look at a team list but not change it');
$throws(fn() => ShLists::create($DB, $C, $M, 'viewer', ['name' => 'x']), ShForbidden::class, 'a viewer cannot make a list');
$throws(fn() => ShLists::delete($DB, $C, $M, 'member', $team), ShForbidden::class, 'only the owner deletes a team list');
$throws(fn() => ShLists::update($DB, $C, $M, 'member', $team, ['shared' => 'private']), ShForbidden::class, 'only the owner changes who sees it');
ShLists::update($DB, $C, $M, 'member', $team, ['notes' => 'Gate 2']);
assert_eq('Gate 2', $DB->query("SELECT notes FROM shopping_lists WHERE id = $team")->fetchColumn(), 'a teammate can change the note');
$throws(fn() => ShLists::access($DB, 2, $A, 'admin', $team), ShProblem::class, 'another company never sees it', 404);

// ---------------------------------------------------------------- adding
$L = fn() => ShLists::load($DB, $C, $team);
$cement = (int)$item($team, 'Cement')['id'];
$ci = $item($team, 'Cement');
assert_eq(['bag', '10.000', 9500, 'hardware'], [$ci['unit'], $ci['qty'], (int)$ci['est_price_cents'], $ci['category']], 'cement: 10 bags at R95, building & hardware');
$r = ShItems::addMany($DB, $C, $A, $L(), ShParse::items('cement x 5'));
assert_true(!$r['added'] && $r['merged'][0]['id'] === $cement && $r['merged'][0]['qty'] === '15', 'the same thing again adds up (15 bags)');
$r = ShItems::addMany($DB, $C, $A, $L(), ShParse::items("gloves x 2\n4 pairs gloves"));
$g = $item($team, 'Gloves');
assert_true(count($r['added']) === 1 && count($r['merged']) === 1 && $g['unit'] === 'pair' && (float)$g['qty'] === 6.0, '"2 × gloves" then "4 pairs gloves" is 6 pairs');
assert_eq(4, (int)$DB->query("SELECT COUNT(*) FROM shopping_items WHERE list_id = $team AND status <> 'removed'")->fetchColumn(), 'four lines on the list');
$milk = (int)$item($team, 'Milk 2L')['id'];
ShItems::update($DB, $C, $A, 'admin', $milk, ['category' => 'office']);
$other = ShLists::create($DB, $C, $A, 'admin', ['name' => 'Office'], ShParse::items('milk 2L'));
assert_eq('office', $item($other, 'Milk 2L')['category'], 'the aisle a person chose is remembered for next time');
$throws(fn() => ShItems::update($DB, $C, $A, 'admin', $milk, ['qty' => '0']), ShProblem::class, 'a quantity of nought is refused', 422);
$throws(fn() => ShItems::update($DB, $C, $A, 'admin', $milk, ['name' => '  ']), ShProblem::class, 'an item needs a name', 422);

// ---------------------------------------------------------------- shopping
$v0 = ShLists::version($DB, $team);
ShLists::startShopping($DB, $C, $M, 'member', $team);
ShLists::startShopping($DB, $C, $A, 'admin', $team);
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM shopping_sessions WHERE list_id = $team AND ended_at IS NULL")->fetchColumn(), 'a second shopper joins the trip instead of starting another');
assert_eq('buying', $L()['status'], 'the list is being shopped');
$storeId = ShStores::save($DB, $C, $A, 'admin', ['name' => 'Builders Test', 'categories' => ['hardware', 'nonsense']]);
ShLists::setShoppingStore($DB, $C, $A, 'admin', $team, $storeId);
$r = ShItems::toggle($DB, $C, $M, $L(), $cement, true, 142500);
$first = $item($team, 'Cement');
assert_true($first['status'] === 'bought' && (int)$first['bought_by'] === $M && (int)$first['store_id'] === $storeId && (int)$first['paid_cents'] === 142500, 'ticked: by whom, where (the store being shopped) and what it cost');
$h = $DB->query("SELECT * FROM shopping_price_history WHERE item_id = $cement")->fetchAll(PDO::FETCH_ASSOC);
assert_true(count($h) === 1 && (int)$h[0]['price_cents'] === 9500 && $h[0]['store_name'] === 'Builders Test' && $h[0]['unit'] === 'bag', 'the price book: R95 a bag at Builders Test');
assert_true(ShLists::version($DB, $team) !== $v0, 'the change stamp moved');
sleep(1);
ShItems::toggle($DB, $C, $A, $L(), $cement, true);
$again = $item($team, 'Cement');
assert_true($again['bought_at'] === $first['bought_at'] && (int)$again['bought_by'] === $M && (int)$again['paid_cents'] === 142500, 'the same tick again (a phone resending) changes nothing');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM shopping_price_history WHERE item_id = $cement")->fetchColumn(), 'still one price book row');
ShItems::toggle($DB, $C, $A, $L(), $cement, true, 150000, false);
assert_eq(10000, (int)$DB->query("SELECT price_cents FROM shopping_price_history WHERE item_id = $cement")->fetchColumn(), 'a price noted later replaces it (R100 a bag)');
assert_eq($storeId, (int)$item($team, 'Cement')['store_id'], 'and keeps the store it was bought at');
ShItems::toggle($DB, $C, $A, $L(), $cement, false);
assert_true($item($team, 'Cement')['status'] === 'pending' && $item($team, 'Cement')['paid_cents'] === null, 'out of the trolley');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM shopping_price_history WHERE item_id = $cement")->fetchColumn(), 'and out of the price book');
ShItems::toggle($DB, $C, $A, $L(), $cement, true, 142500);
$soon = ShLists::create($DB, $C, $A, 'admin', ['name' => 'Next week'], ShParse::items('cement 2 bags'));
assert_eq(9500, (int)$item($soon, 'Cement')['est_price_cents'], 'the next list knows what cement costs (R95, the last price paid)');

// ---------------------------------------------------------------- finishing
$elbow = (int)$item($team, 'PVC elbow 20mm')['id'];
ShItems::remove($DB, $C, $A, 'admin', $elbow);
assert_eq('removed', $DB->query("SELECT status FROM shopping_items WHERE id = $elbow")->fetchColumn(), 'removed');
ShItems::restore($DB, $C, $A, 'admin', $elbow);
assert_eq('pending', $DB->query("SELECT status FROM shopping_items WHERE id = $elbow")->fetchColumn(), 'and brought back');
$before = (int)$DB->query("SELECT COALESCE(MAX(id), 0) FROM notifications")->fetchColumn();
$f = ShLists::finish($DB, $C, $M, 'member', $team, 'move');
assert_true($f['done'] && $f['bought'] === 1 && $f['paid'] === 142500 && $f['moved_to'] > 0, 'finished: one bought, the rest moved');
assert_eq('done', $L()['status'], 'the list is done');
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM shopping_sessions WHERE list_id = $team AND ended_at IS NULL")->fetchColumn(), 'the trip is over');
$moved = ShLists::load($DB, $C, (int)$f['moved_to']);
assert_true($moved['shared_mode'] === 'team' && (int)$DB->query("SELECT COUNT(*) FROM shopping_items WHERE list_id = {$f['moved_to']} AND status = 'pending'")->fetchColumn() === 3, 'the rest is on a new team list');
assert_eq(1, (int)$DB->query("SELECT COUNT(*) FROM notifications WHERE id > $before AND user_id = $A AND type = 'shopping'")->fetchColumn(), 'the owner hears that someone else finished');
$k = ShLists::create($DB, $C, $A, 'admin', ['name' => 'Keep'], ShParse::items("a\nb"));
ShLists::startShopping($DB, $C, $A, 'admin', $k);
ShItems::toggle($DB, $C, $A, ShLists::load($DB, $C, $k), (int)$item($k, 'A')['id'], true);
$f = ShLists::finish($DB, $C, $A, 'admin', $k, 'keep');
assert_true(!$f['done'] && $f['left'] === 1 && ShLists::load($DB, $C, $k)['status'] === 'open', 'kept: the list stays open with what is left');
$f = ShLists::finish($DB, $C, $A, 'admin', $k, 'remove');
assert_true($f['done'] && ShLists::load($DB, $C, $k)['status'] === 'done', 'removed the rest: done');

// ---------------------------------------------------------------- duplicate, delete
$dup = ShLists::duplicate($DB, $C, $A, 'admin', $team, 'Site run again');
$d = $DB->query("SELECT status, paid_cents, est_price_cents, po_id FROM shopping_items WHERE list_id = $dup AND name_raw = 'Cement'")->fetch(PDO::FETCH_ASSOC);
assert_true($d['status'] === 'pending' && $d['paid_cents'] === null && (int)$d['est_price_cents'] === 9500, 'a duplicate has everything to buy again, at the price known');
ShShare::create($DB, $C, $A, 'admin', $dup, ['days' => 7]);
ShLists::delete($DB, $C, $A, 'admin', $dup);
assert_true(!in_array($dup, array_column(ShLists::cards($DB, $C, $A, 'admin'), 'id'), true), 'a deleted list is gone from the cards');
assert_eq(0, count(ShShare::forList($DB, $dup)), 'and its links stop');
ShLists::restore($DB, $C, $A, 'admin', $dup);
assert_true(ShLists::load($DB, $C, $dup) !== null, 'it can be brought back');

// ---------------------------------------------------------------- templates
$tpl = ShTemplates::fromList($DB, $C, $A, 'admin', $team, 'Site start-up');
$t = ShTemplates::all($DB, $C, $A, 'admin')[0];
assert_true($t['name'] === 'Site start-up' && $t['count'] === 1 && $t['text'] === '15 bag Cement @ R95.00', 'a template from the list (what is left on it: the cement), with its price (' . $t['text'] . ')');
$throws(fn() => ShTemplates::save($DB, $C, $M, 'member', ['id' => $tpl, 'name' => 'Mine now', 'text' => 'x']), ShForbidden::class, 'only whoever made it (or an admin) changes a template');
$fromTpl = ShTemplates::use($DB, $C, $M, 'member', $tpl, ['name' => 'Smith job']);
assert_eq(['Cement', '15.000', 'bag', '9500', 'pending'], array_values($DB->query("SELECT name_raw, qty, unit, est_price_cents, status FROM shopping_items WHERE list_id = $fromTpl")->fetch(PDO::FETCH_ASSOC)), 'a list from the template has its item, still to buy');
assert_eq($tpl, (int)ShLists::load($DB, $C, $fromTpl)['template_id'], 'and remembers where it came from');

// ---------------------------------------------------------------- purchase orders
$vatSup = make_supplier($DB, 'PO VAT');
$DB->prepare("UPDATE crm_accounts SET vat_no = '4123456781' WHERE id = ?")->execute([$vatSup]);
$noVat = make_supplier($DB, 'PO no VAT');
$poList = ShLists::create($DB, $C, $A, 'admin', ['name' => 'Order me'], ShParse::items("10 bags cement\n2x PVC elbow 20mm\nbread"));
$ids = fn($names) => array_map(fn($n) => (int)$item($poList, $n)['id'], $names);
$throws(fn() => ShPo::create($DB, $C, $M, 'member', $poList, $vatSup, [['id' => 1]]), ShForbidden::class, 'members cannot make purchase orders');
[$cid, $eid, $bid] = $ids(['Cement', 'PVC elbow 20mm', 'Bread']);
ShItems::toggle($DB, $C, $A, ShLists::load($DB, $C, $poList), $bid, true);
$throws(fn() => ShPo::create($DB, $C, $A, 'admin', $poList, $vatSup, [['id' => $bid, 'price' => 10]]), ShProblem::class, 'a bought item cannot go on an order', 409);
$po = ShPo::create($DB, $C, $A, 'admin', $poList, $vatSup, [['id' => $cid, 'price' => '115'], ['id' => $eid, 'price' => '']]);
$poIds[] = $po['po_id'];
assert_true((bool)preg_match('/^PO-' . date('Y') . '-\d{4}$/', $po['po_number']), 'numbered PO-' . date('Y') . '-0001 style (' . $po['po_number'] . ')');
$lines = $DB->query("SELECT description, qty, unit, unit_price, tax_rate FROM purchase_order_lines WHERE po_id = {$po['po_id']} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
assert_eq([['Cement', '10.000', 'bag', '100.00', '15.00'], ['PVC elbow 20mm', '2.000', 'ea', '0.00', '15.00']], array_map('array_values', $lines), 'lines without VAT (R115 incl. = R100 excl.), an unknown price is nought');
assert_cents(1150, $po['total'], 'the total includes VAT');
assert_eq($po['po_id'], (int)$item($poList, 'Cement')['po_id'], 'the item knows its order');
$throws(fn() => ShPo::create($DB, $C, $A, 'admin', $poList, $vatSup, [['id' => $cid, 'price' => 1]]), ShProblem::class, 'an item goes on one order only', 409);
$fresh = ShLists::create($DB, $C, $A, 'admin', ['name' => 'Second order'], ShParse::items('sand 2 bags'));
$next = 'PO-' . date('Y') . '-' . str_pad((string)((int)substr($po['po_number'], -4) + 1), 4, '0', STR_PAD_LEFT);
$DB->prepare("INSERT INTO purchase_orders (company_id, po_number, supplier_id, total, status, created_at) VALUES (?, ?, ?, 0, 'draft', NOW())")->execute([$C, $next, $noVat]);
$poIds[] = (int)$DB->lastInsertId();
$po2 = ShPo::create($DB, $C, $A, 'admin', $fresh, $noVat, [['id' => (int)$item($fresh, 'Sand')['id'], 'price' => '50']]);
$poIds[] = $po2['po_id'];
assert_true($po2['po_number'] !== $next && $po2['po_number'] > $next, 'a number someone typed by hand is skipped (' . $po2['po_number'] . ')');
assert_eq(['50.00', '0.00'], array_values($DB->query("SELECT unit_price, tax_rate FROM purchase_order_lines WHERE po_id = {$po2['po_id']}")->fetch(PDO::FETCH_ASSOC)), 'no VAT number: the price as it is, no VAT');

// ---------------------------------------------------------------- share links
$share = ShShare::create($DB, $C, $A, 'admin', $poList, ['days' => 1, 'can_edit' => 0]);
$token = substr($share['url'], strpos($share['url'], 't=') + 2);
assert_true((bool)preg_match('/^[a-f0-9]{48}$/', $token), 'a long random token');
$throws(fn() => ShShare::create($DB, $C, $M, 'member', $team, []), ShForbidden::class, 'only the owner makes links');
$s = ShShare::resolve($DB, $token);
$view = ShShare::view($DB, $s);
$json = json_encode($view);
assert_true(!str_contains($json, '"paid"') && !str_contains($json, '"est') && !str_contains($json, 'budget'), 'someone with the link sees no money');
$r = ShShare::toggle($DB, $s, $cid, true, 'Sipho');
$c2 = $item($poList, 'Cement');
assert_true($c2['status'] === 'bought' && $c2['bought_by'] === null && $c2['bought_name'] === 'Sipho', 'a tick through the link carries the guest\'s name');
assert_true(str_contains(json_encode(ShLists::activity($DB, $C, $poList)), 'Sipho (link)'), 'and shows in what happened');
$throws(fn() => ShShare::add($DB, $s, 'eggs', 'Sipho'), ShForbidden::class, 'a tick-only link cannot add');
$throws(fn() => ShShare::toggle($DB, $s, (int)$item($private, 'Bread')['id'], true, 'Sipho'), ShProblem::class, 'nor tick an item on another list', 404);
ShShare::revoke($DB, $C, $A, 'admin', $poList, (int)$share['shares'][0]['id']);
assert_true(ShShare::resolve($DB, $token) === null, 'a stopped link does not open');
$s2 = ShShare::create($DB, $C, $A, 'admin', $poList, ['days' => 7, 'can_edit' => 1]);
$t2 = substr($s2['url'], strpos($s2['url'], 't=') + 2);
ShShare::add($DB, ShShare::resolve($DB, $t2), "eggs\nbutter", 'Thandi');
assert_true($item($poList, 'Eggs') !== false && $item($poList, 'Butter') !== false, 'an add-and-tick link can add');
$DB->exec("UPDATE shopping_share_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token = " . $DB->quote($t2));
assert_true(ShShare::resolve($DB, $t2) === null, 'a link that ran out does not open');
assert_true(ShShare::resolve($DB, 'nope') === null && ShShare::resolve($DB, str_repeat('g', 48)) === null, 'nonsense tokens do not open anything');

// ---------------------------------------------------------------- stores and where to buy
$throws(fn() => ShStores::save($DB, $C, $A, 'admin', ['name' => 'X', 'phone' => 'call me']), ShProblem::class, 'a phone number with letters is refused', 422);
$throws(fn() => ShStores::save($DB, $C, $A, 'admin', ['name' => '']), ShProblem::class, 'a store needs a name', 422);
$throws(fn() => ShStores::save($DB, $C, $M, 'viewer', ['name' => 'X']), ShForbidden::class, 'viewers cannot add stores');
$st = ShStores::all($DB, $C, $A);
$b = array_values(array_filter($st, fn($s) => $s['id'] === $storeId))[0];
assert_true($b['categories'] === ['hardware'] && $b['bought'] >= 1, 'the store: aisles it is good for (only real ones) and what was bought there');
$sug = ShStores::suggest($DB, $C, $A, 'admin', (int)$item($soon, 'Cement')['id']);
assert_eq('history', $sug['sections'][0]['key'], '"Where to buy" starts with where you bought it before');
assert_true(str_contains($sug['sections'][0]['rows'][0]['why'], 'R 95.00'), 'with the last price');
ShStores::delete($DB, $C, 'admin', $storeId);
assert_eq('Builders Test', $DB->query("SELECT store_name FROM shopping_price_history WHERE item_id = $cement")->fetchColumn(), 'a deleted store\'s name stays in the price book');
$book = ShStores::priceBook($DB, $C, $A);
assert_true(count($book) >= 1 && $book[0]['last'] > 0, 'the price book');
$secret = ShLists::create($DB, $C, $A, 'admin', ['name' => 'Private bits', 'shared' => 'private'], ShParse::items('Birthday present'));
ShItems::toggle($DB, $C, $A, ShLists::load($DB, $C, $secret), (int)$item($secret, 'Birthday present')['id'], true, 49900);
assert_true(in_array('Birthday present', array_column(ShStores::priceBook($DB, $C, $A), 'name'), true), 'my private buys are in my price book');
assert_true(!in_array('Birthday present', array_column(ShStores::priceBook($DB, $C, $M), 'name'), true), '…but not in anyone else\'s');
$planned = ShStores::plan($DB, $C, $A, 'admin', (int)$item($soon, 'Cement')['id'], null, $vatSup);
assert_true($planned['store_id'] > 0 && $planned['store'] !== null, 'planning to buy at a CRM supplier makes it a store');
$overview = ShLists::overview($DB, $C, $A, 'admin');
assert_true(count($overview['recent']) >= 1 && $overview['aisles'], 'the overview: recently bought and this month by aisle');
$nav = ShLists::navCounts($DB, $C, $A);
assert_true($nav['open'] >= 1 && $nav['month'] >= 142500, 'the sidebar and Home tile counts');

test_done('shopping_lists');
