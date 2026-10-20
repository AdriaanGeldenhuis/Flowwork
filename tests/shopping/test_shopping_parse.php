<?php
/**
 * Shopping: ShParse turns a typed or pasted list into items. A count goes
 * first or last; sizes stay in the name ("PVC elbow 20mm" is one elbow, not
 * 20 mm of elbow — the old parser's mistake); "@ R95" is the price of one;
 * "!" makes it urgent; bullets and numbering are dropped; commas split a line
 * only when every part is an item; each item lands in its aisle.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/shopping/lib/Sh.php';

$one = function (string $line): array {
    $r = ShParse::items($line);
    assert_eq(1, count($r), "one item from '$line'");
    return $r[0];
};
$is = function (string $line, string $name, float $qty, string $unit, ?int $price = null, string $priority = 'med') use ($one) {
    $i = $one($line);
    assert_eq([$name, $qty, $unit, $price, $priority], [$i['name'], $i['qty'], $i['unit'], $i['price_cents'], $i['priority']], "'$line'");
};

// ---------------------------------------------------------------- counts
$is('2x PVC elbow 20mm', 'PVC elbow 20mm', 2, 'ea');
$is('2 × bread', 'Bread', 2, 'ea');
$is('10 bags of cement', 'Cement', 10, 'bag');
$is('10 bags cement @ R95', 'Cement', 10, 'bag', 9500);
$is('6m conduit 16mm', 'Conduit 16mm', 6, 'm');
$is('cement x 10', 'Cement', 10, 'ea');
$is('M10 bolts x 50', 'M10 bolts', 50, 'ea');
$is('Cable ties (100)', 'Cable ties', 100, 'ea');
$is('cement 10 bags', 'Cement', 10, 'bag');
$is('Gloves - 5 pairs', 'Gloves', 5, 'pair');
$is('screws: 200', 'Screws', 200, 'ea');
$is('½ dozen eggs', 'Eggs', 0.5, 'dozen');
$is('1/2 kg mince', 'Mince', 0.5, 'kg');
$is('2,5 kg sugar', 'Sugar', 2.5, 'kg');
$is('3 bread', 'Bread', 3, 'ea');
$is('2 sakke sement', 'Sement', 2, 'bag');

// ---------------------------------------------------------------- sizes stay in the name
$is('PVC elbow 20mm', 'PVC elbow 20mm', 1, 'ea');
$is('milk 2L', 'Milk 2L', 1, 'ea');
$is('Coke 2L x 6', 'Coke 2L', 6, 'ea');
$is('sugar 2,5kg', 'Sugar 2,5kg', 1, 'ea');
$is('2x4 timber', '2x4 timber', 1, 'ea');
$is('Mix 2', 'Mix 2', 1, 'ea');
$is('2 large eggs', 'Large eggs', 2, 'ea');

// ---------------------------------------------------------------- prices and urgency
$is('Bread R18.99', 'Bread', 1, 'ea', 1899);
$is('Drill R1,299.99', 'Drill', 1, 'ea', 129999);
$is('Drill R 1 299,99', 'Drill', 1, 'ea', 129999);
$is('Paint 20L @ 850', 'Paint 20L', 1, 'ea', 85000);
$is('RCD 30mA', 'RCD 30mA', 1, 'ea');
$is('eggs!', 'Eggs', 1, 'ea', null, 'high');
$is('Paint 20L urgent', 'Paint 20L', 1, 'ea', null, 'high');
$is('! gas bottle', 'Gas bottle', 1, 'ea', null, 'high');

// ---------------------------------------------------------------- lines, bullets, commas
$r = ShParse::items("- [ ] milk\n• bread\n3. eggs\n☐ butter\n\n\n");
assert_eq(['Milk', 'Bread', 'Eggs', 'Butter'], array_column($r, 'name'), 'bullets, check boxes and numbering are dropped');
assert_eq(['Milk 2L', 'Bread', 'Eggs'], array_column(ShParse::items('milk 2L, bread, eggs'), 'name'), 'a comma splits a line of items');
assert_eq(['Cement, 50kg bag'], array_column(ShParse::items('Cement, 50kg bag'), 'name'), '…but not a size after a comma');
assert_eq(['Sugar 2,5kg'], array_column(ShParse::items('Sugar 2,5kg'), 'name'), '…nor a decimal comma');
assert_eq(['Cement', 'Sand'], array_column(ShParse::items("cement;sand"), 'name'), 'semicolons split');
assert_eq([], ShParse::items("  \n - \n *** \n"), 'nothing from blank or punctuation-only lines');
assert_eq(3, count(ShParse::items(str_repeat("bread\n", 10), 3)), 'at most $max items');
assert_eq(200, mb_strlen(ShParse::items(str_repeat('a', 500))[0]['name']), 'names are kept to 200 characters');

// ---------------------------------------------------------------- aisles
foreach ([
    'PVC elbow 20mm' => 'plumbing', '6m conduit 16mm' => 'electrical', 'Cement' => 'hardware', 'Cable ties' => 'hardware',
    'Toilet paper' => 'cleaning', 'Milk 2L' => 'groceries', 'Safety boots' => 'ppe', 'A4 paper' => 'office', 'Diesel' => 'vehicle',
    'Something odd' => 'other', 'LED downlight' => 'electrical',
] as $name => $cat) {
    assert_eq($cat, ShParse::category($name), "'$name' is in $cat");
}

// ---------------------------------------------------------------- units, names, prices
assert_eq('L', ShParse::unit('litres'), 'litres → L');
assert_eq('bag', ShParse::unit('sakke'), 'sakke → bag');
assert_eq('ea', ShParse::unit('wheelbarrows'), 'an unknown unit is each');
assert_eq('m²', ShParse::unit('m2'), 'm2 → m²');
assert_eq('pvc elbow 20mm', ShParse::norm('  PVC-elbow,  20mm!'), 'names are matched on letters and digits');
assert_eq(ShParse::fingerprint('PVC elbow 20mm', 'ea'), ShParse::fingerprint('pvc  ELBOW 20mm', 'each'), 'the same item has the same fingerprint');
assert_true(ShParse::fingerprint('Cement', 'bag') !== ShParse::fingerprint('Cement', 'kg'), 'a bag is not a kilogram');
assert_eq(['pvc', 'elbow'], ShParse::keywords('PVC elbow 20mm'), 'keywords for searching bills');
foreach (['95' => 9500, '1 299,99' => 129999, '1,299.99' => 129999, '1.299,99' => 129999, '0' => null, '12.345' => 1234500, '1.5.5' => null] as $in => $want) {
    assert_eq($want, ShParse::money((string)$in), "money '$in'");
}

// ---------------------------------------------------------------- money and quantities as typed
assert_eq(123450, ShKit::cents('R 1 234,50'), 'cents from R 1 234,50');
assert_eq(null, ShKit::cents('abc'), 'not a number');
assert_eq('2.5', ShKit::qtyText(2.5), 'quantity text');
assert_eq('10', ShKit::qtyText('10.000'), 'whole quantities lose their decimals');
$threw = false;
try { ShKit::qty('0'); } catch (ShProblem $e) { $threw = true; }
assert_true($threw, 'a quantity of nought is refused');
$threw = false;
try { ShKit::price('-5'); } catch (ShProblem $e) { $threw = true; }
assert_true($threw, 'a negative price is refused');

// ---------------------------------------------------------------- templates read back the same
$items = ShParse::items("10 bags cement @ R95\n2x PVC elbow 20mm\nmilk 2L\nGloves - 5 pairs!");
$back = ShParse::items(ShTemplates::text(array_map(fn($i) => $i + ['category' => $i['category']], $items)));
assert_eq(array_map(fn($i) => [$i['name'], $i['qty'], $i['unit'], $i['price_cents'], $i['priority']], $items),
          array_map(fn($i) => [$i['name'], $i['qty'], $i['unit'], $i['price_cents'], $i['priority']], $back), 'a template\'s text reads back as the same items');

test_done('shopping_parse');
