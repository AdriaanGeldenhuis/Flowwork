<?php
/**
 * Suppliers AI without the database: reading a request in plain words
 * (SaParse — trade, place also misspelt, quantity, budget, when, requirements,
 * English and Afrikaans; the title never carries the budget), the match and
 * its reasons (SaScore), and the small helpers (SaKit: amounts, phone numbers,
 * domains, the company's words, keys that are never shown).
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/suppliers_ai/lib/Sa.php';

$T = '2026-10-21'; // a Wednesday
$r = fn(string $s, array $rules = []) => SaParse::read($s, $rules, $T);

// ---------------------------------------------------------------- the example on the old page
$x = $r('Need 3 plumbers in Vanderbijlpark for geysers tomorrow under R5k');
assert_eq('plumbing', $x['trade']['key'], 'plumbers are plumbing');
assert_eq('Vanderbijlpark', $x['location'], 'the place');
assert_eq('3 plumbers', $x['quantity'], 'how many');
assert_eq(3, $x['count'], 'as a number');
assert_eq(500000, $x['budget'], 'under R5k is R 5 000');
assert_eq('2026-10-22', $x['needed_by'], 'tomorrow');
assert_true(!$x['urgent'], 'tomorrow is not "urgent"');
assert_eq(['plumber', 'geyser'], $x['keywords'], 'the words that matter, singular');
assert_true(!str_contains(strtolower($x['title']), 'r5k') && !str_contains(strtolower($x['title']), 'under'), 'the title never carries the budget: ' . $x['title']);
assert_true(!str_contains(strtolower($x['title']), 'tomorrow'), 'nor "tomorrow" (it would be wrong by the time the email is read)');

// ---------------------------------------------------------------- places
assert_eq('Vanderbijlpark', $r('Plumber vandebijlpark')['location'], 'a misspelt town is still found (the company\'s own city is spelt that way)');
assert_eq('Vanderbijlpark', $r('glass suppliers in vanderbijlpark')['location'], 'lower case');
assert_eq('Johannesburg', $r('electrician in jhb asap')['location'], 'jhb');
assert_eq('Pretoria East', $r('Roof leak repairs Pretoria East on 12 Nov')['location'], 'a known town with its side');
assert_eq('Meyerton', $r('loodgieter naby Meyerton môre')['location'], 'naby (Afrikaans)');
assert_eq(null, $r('cement in stock at least 20 bags')['location'], '"in stock" and "at least" are not places');
assert_eq(null, $r('50mm PE pipe and fittings')['location'], 'PE pipe is not Gqeberha');
assert_eq('Klipfontein', $r('sand supplier near Klipfontein for delivery')['location'], 'a place it does not know, after "near", as written');

// ---------------------------------------------------------------- trades
assert_eq('glass', $r('glass suppliers in vanderbijlpark')['trade']['key'], 'glass (the old module had no such trade, so it found nobody)');
assert_eq('roofing', $r('Roof leak repairs Pretoria East')['trade']['key'], 'a roof leak is roofing, not plumbing');
assert_eq('aggregates', $r('20 cubes river sand delivered to Sasolburg by Friday')['trade']['key'], 'river sand');
assert_eq('aggregates', $r('19mm crushed stone 10 tons')['trade']['key'], 'crushed stone');
assert_eq('electrical', $r('2 electricians with COC')['trade']['key'], 'electricians');
assert_eq(['electrical'], $r('2 electricians with COC')['trades'], 'COC alone does not add a trade');
assert_eq('plumbing', $r('Ons soek ’n loodgieter naby Meyerton')['trade']['key'], 'loodgieter');
assert_eq('plumbing', $r('plumbing contractor for a new bathroom')['trade']['key'], '"contractor" does not outrank a specific trade');
assert_eq('building', $r('building contractor for an extension')['trade']['key'], 'but a building contractor is building');
assert_eq(null, $r('Milk')['trade'], 'no trade for milk');
$rules = SaKit::rules(['categories' => ['pool' => ['swimming pool', 'pool pump', 'chlorine']], 'synonyms' => ['jojo' => ['water tank']]]);
$p = $r('need someone for the swimming pool', $rules);
assert_eq('pool', $p['trade']['key'], 'the company\'s own trade');
assert_eq('Pool', $p['trade']['label'], 'with a label');
assert_true(in_array('water tank', $r('2 jojo tanks', $rules)['keywords'], true), 'a synonym adds its words');
$legacy = SaKit::rules(['categories' => ['plumber' => ['geyser specialist']]]);
assert_eq('plumbing', $r('geyser specialist please', $legacy)['trade']['key'], 'the old module\'s trade names still work');

// ---------------------------------------------------------------- quantities
assert_eq('20 m³', $r('20 cubes river sand')['quantity'], 'cubes are m³');
assert_eq('10 t', $r('19mm crushed stone 10 tons')['quantity'], 'tons');
assert_eq('2.5 t', $r('2.5 ton building sand')['quantity'], 'a decimal');
assert_eq(null, $r('19mm crushed stone')['quantity'], '19mm is a size, not 19 metres');
assert_eq('3 units', $r('aircon service at least 3 units')['quantity'], 'units');
assert_eq('2 electricians', $r('two electricians tomorrow')['quantity'], 'a number in words');
assert_eq('500 bricks', $r('500 bricks delivered')['quantity'], 'bricks');

// ---------------------------------------------------------------- budget
assert_eq(800000, $r('loodgieter, begroting R8000')['budget'], 'begroting');
assert_eq(1500000, $r('electric fence installer budget 15 000')['budget'], 'a budget without R');
assert_eq(1250000, $r('max R12,500 for the job')['budget'], 'thousands with a comma');
assert_eq(120000000, $r('crane hire, up to R1.2m')['budget'], 'R1.2m');
assert_eq(20000, $r('R200 m of cable')['budget'], '"R200 m of cable" is R 200, not R 200 million');
assert_eq(null, $r('G5 gravel for the road')['budget'], 'no R, no budget');

// ---------------------------------------------------------------- when
assert_eq('2026-10-21', $r('generator asap')['needed_by'], 'asap is today');
assert_true($r('generator asap')['urgent'], 'and urgent');
assert_eq('2026-10-22', $r('loodgieter môre')['needed_by'], 'môre');
assert_eq('2026-10-23', $r('sand by Friday')['needed_by'], 'by Friday (this week)');
assert_eq('2026-10-30', $r('sand next Friday')['needed_by'], 'next Friday is next week\'s');
assert_eq('2026-10-26', $r('electrician next week')['needed_by'], 'next week is its Monday');
assert_eq('2026-10-23', $r('quotes this week')['needed_by'], 'this week is Friday');
assert_eq('2026-10-31', $r('needed by month end')['needed_by'], 'month end');
assert_eq('2026-10-24', $r('within 3 days')['needed_by'], 'within 3 days');
assert_eq('2026-11-12', $r('on 12 Nov')['needed_by'], '12 Nov');
assert_eq('2026-11-12', $r('by 12/11')['needed_by'], 'd/m, the South African way');
assert_eq('2027-01-15', $r('15 January')['needed_by'], 'a date already past this year is next year\'s');
assert_eq(null, $r('2.5 ton sand')['needed_by'], '2.5 is not the 2nd of May');
assert_eq(null, $r('24/7 call-out')['needed_by'], '24/7 is not a date');

// ---------------------------------------------------------------- requirements
$labels = fn($s) => array_column($r($s)['requirements'], 'label');
assert_true(in_array('Certificate of compliance (COC)', $labels('electrician with COC'), true), 'COC');
assert_true(in_array('B-BBEE level 1', $labels('B-BBEE level 1 supplier'), true), 'B-BBEE level');
assert_true(in_array('CIDB grade 5GB', $labels('contractor CIDB grade 5GB'), true), 'CIDB grade');
assert_true(in_array('Insured (public liability)', $labels('must be insured'), true), 'insured');
assert_true(in_array('Delivers to site', $labels('20 cubes sand delivered'), true), 'delivery');
assert_true(in_array('After hours or weekends', $labels('24/7 plumber'), true), 'after hours');
assert_true(!in_array('5gb', $r('crushed stone, CIDB grade 5GB')['keywords'], true), 'a CIDB grade is not a search word');
assert_eq('19mm crushed stone', $r('19mm crushed stone 10 tons, CIDB grade 5GB')['query'], 'the Google search keeps the size and drops the paperwork');

// ---------------------------------------------------------------- titles
assert_eq('Loodgieter naby Meyerton', $r('Ons soek ’n loodgieter naby Meyerton môre, begroting R8000')['title'], 'Afrikaans: no "ons soek", no date, no budget');
assert_eq('2 electricians in Johannesburg with COC', $r('Need 2 electricians in Johannesburg with COC, available next week')['title'], 'no dangling "available"');

// ---------------------------------------------------------------- the match and its reasons
$base = ['source' => 'crm', 'status' => 'active', 'preferred' => false, 'hits' => ['name' => ['Plumbers']], 'perf' => [], 'compliance' => ['status' => 'valid', 'blocking' => false, 'expired' => [], 'missing' => [], 'expiring' => []]];
[$plain, $why] = SaScore::score($base, ['radius' => 25]);
[$pref] = SaScore::score(['preferred' => true] + $base, ['radius' => 25]);
assert_true($pref > $plain, 'a preferred supplier ranks higher');
assert_true(in_array('The name says “Plumbers”', array_column($why, 't'), true), 'the reason is in words');
[$exp, $expWhy] = SaScore::score(['compliance' => ['status' => 'expired', 'blocking' => true, 'expired' => ['Tax clearance'], 'missing' => [], 'expiring' => []]] + $base, ['radius' => 25]);
assert_true($exp < $plain, 'expired documents rank lower');
assert_true(in_array('danger', array_column($expWhy, 'tone'), true), 'and say so in red');
[$weak] = SaScore::score(['hits' => ['name' => ['Contractor'], 'weak' => true]] + $base, ['radius' => 25]);
assert_true($weak < $plain, 'a match on a second trade\'s word counts less');
[$bought] = SaScore::score(['bills' => ['n' => 3, 'last' => '2026-09-01', 'desc' => 'Geyser 150L', 'price' => 420000]] + $base, ['radius' => 25, 'books' => true]);
assert_true($bought > $plain, 'bought from before ranks higher');
[, $boughtWhy] = SaScore::score(['bills' => ['n' => 1, 'last' => '2026-09-01', 'desc' => 'Geyser 150L', 'price' => 420000]] + $base, ['radius' => 25, 'books' => false]);
assert_true(!str_contains(implode(' ', array_column($boughtWhy, 't')), 'R 4'), 'what was paid shows only to the books');
$g = ['source' => 'google', 'phone' => '016 123 4567', 'website' => '', 'hits' => ['name' => ['plumb']], 'google' => ['rating' => 4.7, 'rating_count' => 120, 'type' => 'Plumber']];
[$near] = SaScore::score($g + ['distance_km' => 3.0], ['radius' => 25]);
[$far, $farWhy] = SaScore::score($g + ['distance_km' => 60.0], ['radius' => 25]);
assert_true($near > $far, 'closer ranks higher');
assert_true(str_contains(implode(' ', array_column($farWhy, 't')), 'outside 25 km'), 'and "outside 25 km" says why');
[$lowRated] = SaScore::score(['google' => ['rating' => 2.9, 'rating_count' => 80, 'type' => '']] + $g + ['distance_km' => 3.0], ['radius' => 25]);
assert_true($lowRated < $near, 'a poor rating ranks lower');
[$bare, $bareWhy] = SaScore::score(['phone' => '', 'website' => '', 'google' => ['rating' => null, 'rating_count' => 0, 'type' => '']] + $g + ['distance_km' => null], ['radius' => 25]);
assert_true(in_array('Google lists no phone number or website', array_column($bareWhy, 't'), true), 'no phone and no website is said, not filled in');
assert_eq('Tax, BEE and 2 more', SaScore::few(['Tax', 'BEE', 'COID', 'Insurance']), 'long document lists are shortened');

// ---------------------------------------------------------------- helpers
assert_eq(123450, SaKit::cents('1 234,50'), 'cents from "1 234,50"');
assert_eq(123450, SaKit::cents('R1,234.50'), 'cents from "R1,234.50"');
assert_eq(null, SaKit::cents('lots'), 'not an amount');
assert_eq('821234567', SaKit::phoneKey('+27 82 123 4567'), 'the same line in any format…');
assert_eq('821234567', SaKit::phoneKey('082 123 4567'), '…compares equal');
assert_eq('27821234567', SaKit::dialable('082 123 4567'), 'a South African number made international for WhatsApp');
assert_eq('example.co.za', SaKit::domain('https://www.Example.co.za/contact'), 'a website\'s domain');
assert_eq('example.co.za', SaKit::domain('quotes@example.co.za'), 'an email\'s domain');
assert_true(SaKit::freeMail('gmail.com'), 'gmail says nothing about the business');
assert_eq('sk-a…wxyz', SaKit::mask('sk-abcdefghijklmnopqrstuvwxyz'), 'a key is shown masked');
assert_true(!SaKit::validOpenAiKey('OPENAI_KEY_REMOVED'), 'the old placeholder key is not a key');
assert_true(SaKit::validGoogleKey('AIzaSyA1234567890abcdefghijklmnopqrstuv'), 'a Google key');
$rl = SaKit::rules(['deny_list' => ['phones' => ['082 123 4567', '+27821234567'], 'domains' => ['https://www.Bad.co.za'], 'names' => ['  Shady  Co ']], 'synonyms' => ['Geyser' => ['Water Heater', '', 'water heater']]]);
assert_eq(['821234567'], $rl['deny_list']['phones'], 'never-suggest phones are kept once, in any format');
assert_eq(['bad.co.za'], $rl['deny_list']['domains'], 'domains are cleaned');
assert_eq(['shady co'], $rl['deny_list']['names'], 'names are cleaned');
assert_eq(['geyser' => ['water heater']], $rl['synonyms'], 'synonyms lower case, once');
$l1 = SaKit::latin1('Brick & block 🧱 quote — m³');
assert_true(!preg_match('/[^\x{20}-\x{FF}\x{2014}]/u', $l1) && str_starts_with($l1, 'Brick & block') && str_contains($l1, 'm³'), 'a character the order line cannot hold is replaced; m³ and the dash stay (' . $l1 . ')');

// ---------------------------------------------------------------- the purchase order line (SaPo::line)
assert_eq([800000, 15.0], SaPo::line(800000, 'excl', true), '"plus VAT": the quote is the line, VAT added on it');
assert_eq([100000, 15.0], SaPo::line(100000, 'excl', false), '"plus VAT" without a VAT number in the CRM is still ordered with VAT, as it was compared');
assert_eq([1043, 15.0], SaPo::line(1200, 'incl', true), '"VAT included" from a registered supplier: VAT taken out to the cent that adds back (R 10.43 + 15% = R 12.00)');
assert_eq([1000, 0.0], SaPo::line(1000, 'incl', false), '"VAT included" without a VAT number: ordered as quoted, no VAT line');
assert_eq([5000, 0.0], SaPo::line(5000, 'none', true), 'no VAT: as quoted');
$off = 0;
for ($c = 100; $c <= 1000000; $c += 100) { [$u] = SaPo::line($c, 'incl', true); if (abs((int)round($u * 1.15) - $c) > 1) $off++; }
assert_eq(0, $off, 'every whole-rand quote up to R 10 000 comes back within a cent');

test_done('sai_parse');
