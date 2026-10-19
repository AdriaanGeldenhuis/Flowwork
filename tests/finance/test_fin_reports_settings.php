<?php
/**
 * Finance rebuild — reports (FinReports), budgets (FinBudgets) and settings
 * (Fin::config, FinSettings, roles).
 *   - Every report runs; the trial balance and balance sheet balance; the cash
 *     flow proves itself against the bank accounts — including receivables
 *     and payables found through the engine's mapping (the old report put the
 *     receivables movement under investing when no mapping was pinned).
 *   - Tie-outs use the same mapping (Tieout::glBalance used a pinned setting only).
 *   - Budgets: saving one cell no longer deletes the others (the old save wiped
 *     every unlocked month of the year and kept only what was posted).
 *   - Settings: wording / parts / rules per company, each person's look,
 *     mappings that must differ (checked against what is saved), the VAT basis
 *     guard, and who may see and do what.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinReports.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinBudgets.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinSettings.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinVat.php';
require_once FLOWWORK_ROOT . '/finances/lib/Tieout.php';

fin_setup($DB);
$C = TEST_COMPANY_ID; $U = TEST_USER_ID;
$ar = fin_code($DB, 'finance_ar_account_id');
$ap = fin_code($DB, 'finance_ap_account_id');
$rev = fin_pick_account($DB, 'revenue');
$exp = fin_pick_account($DB, 'expense');
$d = date('Y-m-d', strtotime('-5 days'));
fin_bank($DB, '1020', 'Main');
// an invoice-like sale on credit, half received; a bill on credit, paid; an owner loan; equipment bought
fin_post($DB, $d, [[$ar, 1150, 0], [$rev['code'], 0, 1000], [fin_code($DB, 'finance_vat_output_account_id'), 0, 150, tax_code_id_by_code($DB, 'STD')]]);
fin_post($DB, $d, [['1020', 575, 0], [$ar, 0, 575]]);
fin_post($DB, $d, [[$exp['code'], 200, 0], [$ap, 0, 200]]);
fin_post($DB, $d, [[$ap, 200, 0], ['1020', 0, 200]]);
$loan = fin_pick_account($DB, 'liability', 'loan');
fin_post($DB, $d, [['1020', 10000, 0], [$loan['code'], 0, 10000]]);
fin_post($DB, $d, [['1530', 4000, 0], ['1020', 0, 4000]]);

// unpin the receivables mapping: the engine still resolves it (standard account) — reports must too
$DB->prepare("UPDATE company_settings SET setting_value = '' WHERE company_id = ? AND setting_key = 'finance_ar_account_id'")->execute([$C]);
assert_eq($ar, fin_code($DB, 'finance_ar_account_id'), 'the engine resolves the receivables account without a pinned setting');

$mods = ['receivables' => true, 'payables' => true, 'vat' => true, 'assets' => true, 'budgets' => true, 'bank' => true];
foreach (FinReports::catalog($mods) as $g) foreach ($g['items'] as $it) {
    $r = FinReports::run($DB, $C, $it['key'], ['from' => date('Y-m-01', strtotime('-2 months')), 'to' => date('Y-m-d')], $mods);
    assert_true(isset($r['title'], $r['columns'], $r['rows']), 'report ' . $it['key'] . ' runs');
}
expect_error(fn() => FinReports::run($DB, $C, 'vat_summary', [], ['vat' => false]), 'a report of a part that is switched off is not there', 'not found');

$tb = FinReports::trialBalance($DB, $C, date('Y-m-d'));
assert_true($tb['checks'][0]['ok'], 'trial balance: debits equal credits');
$bs = FinReports::balanceSheet($DB, $C, date('Y-m-d'));
assert_true($bs['checks'][0]['ok'], 'balance sheet balances');
$cf = FinReports::cashflow($DB, $C, date('Y-m-01', strtotime('-2 months')), date('Y-m-d'));
assert_true($cf['checks'][0]['ok'], 'cash flow agrees with the bank: ' . $cf['checks'][0]['detail']);
$cell = function (array $rep, string $needle) { foreach ($rep['rows'] as $r) if (str_contains($r['label'], $needle)) return $r['cells']['amount'] ?? null; return null; };
assert_cents(-575.00, $cell($cf, $ar), 'receivables movement is working capital under operating (R575 still owed)');
assert_cents(-4000.00, $cell($cf, 'Cash from investing'), 'investing is only the equipment');
assert_cents(10000.00, $cell($cf, 'Cash from financing'), 'financing is the loan');
assert_cents(6375.00, $cell($cf, 'Net change in cash'), 'cash moved R6,375');

$t = new Tieout($DB, $C);
assert_cents(575.00, $t->glBalance('AR', date('Y-m-d')), 'tie-out reads the receivables ledger through the mapping');
$tie = FinReports::tieouts($DB, $C, date('Y-m-d'));
assert_true(isset($tie['checks'][0]), 'tie-out report has checks');

// ---------------------------------------------------------------- budgets
$fy = (int)substr(Fin::fyStart($DB, $C), 0, 4);
$months = FinBudgets::months($DB, $C, $fy);
[$y0, $m0] = $months[11]; [$y1, $m1] = $months[10];
$a1 = $exp['id']; $a2 = fin_pick_account($DB, 'expense', 'operating_expense')['id'];
FinBudgets::save($DB, $C, $U, 'bookkeeper', [['account_id' => $a1, 'y' => $y0, 'm' => $m0, 'amount' => '1000'], ['account_id' => $a2, 'y' => $y1, 'm' => $m1, 'amount' => '250']]);
FinBudgets::save($DB, $C, $U, 'bookkeeper', [['account_id' => $a1, 'y' => $y1, 'm' => $m1, 'amount' => '900']]);
$count = (int)$DB->query("SELECT COUNT(*) FROM gl_budgets WHERE company_id = $C")->fetchColumn();
assert_eq(3, $count, 'saving one cell keeps the others (the old save deleted them)');
FinBudgets::save($DB, $C, $U, 'bookkeeper', [['account_id' => $a2, 'y' => $y1, 'm' => $m1, 'amount' => '']]);
assert_eq(2, (int)$DB->query("SELECT COUNT(*) FROM gl_budgets WHERE company_id = $C")->fetchColumn(), 'a cell sent empty is removed');
expect_error(fn() => FinBudgets::save($DB, $C, $U, 'bookkeeper', [['account_id' => $a1, 'y' => $y0, 'm' => $m0, 'amount' => '-5']]), 'negative budgets are refused');
expect_error(fn() => FinBudgets::save($DB, $C, $U, 'viewer', [['account_id' => $a1, 'y' => $y0, 'm' => $m0, 'amount' => '5']]), 'a viewer cannot change budgets');
$g = FinBudgets::grid($DB, $C, $fy);
$row = array_values(array_filter($g['rows'], fn($r) => $r['id'] === $a1))[0];
assert_cents(1000.00, $row['budget'][11], 'grid shows the budget');

// --------------------------------------------------------------- settings
$cfg = Fin::config($DB, $C);
assert_eq('Bills', $cfg['labels']['bills'], 'standard wording');
FinSettings::saveCompany($DB, $C, $U, 'admin', ['labels' => ['bills' => 'Supplier invoices'], 'modules' => ['budgets' => false], 'rules' => ['members_read' => true]]);
$cfg = Fin::config($DB, $C);
assert_eq('Supplier invoices', $cfg['labels']['bills'], 'company wording saved');
assert_true(!$cfg['modules']['budgets'], 'budgets switched off');
assert_true(Fin::canSee('member', $cfg), 'members may look when the company allows it');
assert_true(!Fin::canWrite('member') && !Fin::canWrite('viewer') && Fin::canWrite('bookkeeper'), 'only bookkeepers and admins write');
assert_true(Fin::isAdmin('admin') && !Fin::isAdmin('bookkeeper'), 'only admins administer');
expect_error(fn() => FinSettings::saveCompany($DB, $C, $U, 'bookkeeper', ['labels' => ['bills' => 'x']]), 'a bookkeeper cannot change company settings');
expect_error(fn() => FinSettings::saveCompany($DB, $C, $U, 'admin', ['labels' => ['bills' => str_repeat('x', 41)]]), 'wording is kept short', '40');
FinSettings::resetCompany($DB, $C, $U, 'admin', 'all');
assert_eq('Bills', Fin::config($DB, $C)['labels']['bills'], 'reset to standard');
assert_true(!Fin::canSee('member', Fin::config($DB, $C)), 'members no longer see Finance');

// each person's own look is theirs alone
Fin::writePrefs($DB, $C, 7, ['density' => 'compact', 'accent' => '#2f7457', 'landing' => 'bank']);
$p7 = Fin::userPrefs($DB, $C, 7);
assert_eq('compact', $p7['density'], 'person 7 compact');
assert_eq('#2f7457', $p7['accent'], 'person 7 colour');
assert_eq('comfortable', Fin::userPrefs($DB, $C, 8)['density'], 'person 8 unaffected');
Fin::writePrefs($DB, $C, 7, ['accent' => 'javascript:alert(1)', 'landing' => 'nope']);
assert_eq('', Fin::userPrefs($DB, $C, 7)['accent'], 'a bad colour is ignored');
assert_eq('overview', Fin::userPrefs($DB, $C, 7)['landing'], 'an unknown landing page falls back');

// mappings: roles that must differ are checked against what is saved
$bankId = fin_account_id($DB, '1020');
expect_error(fn() => FinSettings::saveMappings($DB, $C, $U, 'admin', ['bank_account_id' => fin_account_id($DB, $ar)]), 'the bank and receivables roles need different accounts (receivables is not even pinned)', 'different accounts');
expect_error(fn() => FinSettings::saveMappings($DB, $C, $U, 'admin', ['ar_account_id' => fin_account_id($DB, $ap)]), 'receivables must be an asset account', 'asset');
expect_error(fn() => FinSettings::saveMappings($DB, $C, $U, 'admin', ['sales_account_id' => $bankId]), 'sales must be an income account', 'revenue');
FinSettings::saveMappings($DB, $C, $U, 'admin', ['bank_account_id' => $bankId]);
assert_eq('1020', fin_code($DB, 'finance_bank_account_id'), 'default bank mapping saved');

// VAT basis guard
$vp = FinVat::create($DB, $C, $U, 'admin', date('Y-m-01', strtotime('first day of -1 month')), date('Y-m-t', strtotime('first day of -1 month')));
FinVat::prepare($DB, $C, $U, 'admin', $vp);
expect_error(fn() => FinSettings::saveEngine($DB, $C, $U, 'admin', ['vat_basis' => 'payments']), 'the basis cannot change while a return is prepared', 'prepared');
FinVat::reopen($DB, $C, $U, 'admin', $vp);
FinSettings::saveEngine($DB, $C, $U, 'admin', ['vat_basis' => 'payments']);
assert_eq('payments', Fin::engineSettings($DB, $C)['vat_basis'], 'basis changed once nothing is in flight');
FinSettings::saveEngine($DB, $C, $U, 'admin', ['vat_basis' => 'invoice']);

test_done('fin_reports_settings');
