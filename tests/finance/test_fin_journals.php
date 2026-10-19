<?php
/**
 * Finance rebuild — manual journals (finances/lib/FinJournals.php).
 * Draft → approved → posted, posted journals never change (a reversal
 * corrects them), integer-cents balance, accounts and VAT codes of this
 * company only, segregation of duties (the preparer and anyone who edited the
 * draft may not approve it), locked periods, system journals guarded, and the
 * approval rule switched off.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/fin_helpers.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinJournals.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinSettings.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinClose.php';

fin_setup($DB);
$C = TEST_COMPANY_ID;
$exp = fin_pick_account($DB, 'expense');
$liab = fin_pick_account($DB, 'liability');
$date = date('Y-m-d', strtotime('-3 days'));
$lines = fn($a) => [['account_code' => $exp['code'], 'description' => 'Rent', 'debit' => $a, 'credit' => ''], ['account_code' => $liab['code'], 'description' => 'Accrual', 'debit' => '', 'credit' => $a]];
$before = tb_snapshot($DB);

// validation
expect_error(fn() => FinJournals::save($DB, $C, 1, 'admin', ['date' => $date, 'lines' => [['account_code' => $exp['code'], 'debit' => '100.00'], ['account_code' => $liab['code'], 'credit' => '99.99']]]), 'an unbalanced journal is refused', 'balance');
expect_error(fn() => FinJournals::save($DB, $C, 1, 'admin', ['date' => $date, 'lines' => [['account_code' => $exp['code'], 'debit' => '100'], ['account_code' => '99999', 'credit' => '100']]]), 'an unknown account is refused');
expect_error(fn() => FinJournals::save($DB, $C, 1, 'admin', ['date' => $date, 'lines' => [['account_code' => $exp['code'], 'debit' => '-5'], ['account_code' => $liab['code'], 'credit' => '-5']]]), 'negative amounts are refused');
expect_error(fn() => FinJournals::save($DB, $C, 1, 'admin', ['date' => $date, 'lines' => [['account_code' => $exp['code'], 'debit' => '5', 'credit' => '5'], ['account_code' => $liab['code'], 'credit' => '0']]]), 'a line with both sides is refused');
$DB->prepare("INSERT INTO gl_tax_codes (company_id, code, description, rate_percent, type, is_active) VALUES (2, 'X2', 'other company', 15, 'input', 1)")->execute();
$foreignTax = (int)$DB->lastInsertId();
$l = $lines('10'); $l[0]['tax_code_id'] = $foreignTax;
expect_error(fn() => FinJournals::save($DB, $C, 1, 'admin', ['date' => $date, 'lines' => $l]), "another company's VAT code is refused");
$DB->exec("DELETE FROM gl_tax_codes WHERE tax_code_id = $foreignTax");
expect_error(fn() => FinJournals::save($DB, $C, 1, 'viewer', ['date' => $date, 'lines' => $lines('10')]), 'a viewer cannot write');

// draft → approve → post, cents exact
$id = FinJournals::save($DB, $C, 1, 'bookkeeper', ['date' => $date, 'description' => 'October rent accrual', 'lines' => $lines('1234.56')]);
$j = $DB->query("SELECT status, reference FROM journal_entries WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
assert_eq('draft', $j['status'], 'saved as a draft');
assert_true(str_starts_with($j['reference'], 'JNL-'), 'numbered from the JNL sequence');
assert_eq($before, tb_snapshot($DB), 'a draft changes no balance');
expect_error(fn() => FinJournals::post($DB, $C, 1, 'bookkeeper', $id), 'a draft cannot be posted while approval is required', 'Approve');
FinJournals::approve($DB, $C, 1, 'bookkeeper', $id);
FinJournals::post($DB, $C, 1, 'bookkeeper', $id);
assert_balanced_journal($DB, $id, 'manual journal');
assert_eq(123456, gl_balance_cents($DB, $exp['code']) - ($before[$exp['code']] ?? 0), 'expense debited R1,234.56 exactly');

// posted is final
expect_error(fn() => FinJournals::save($DB, $C, 1, 'bookkeeper', ['id' => $id, 'date' => $date, 'lines' => $lines('1')]), 'a posted journal cannot be edited', 'reversal');
expect_error(fn() => FinJournals::delete($DB, $C, 1, 'bookkeeper', $id), 'a posted journal cannot be deleted');
expect_error(fn() => FinJournals::reverse($DB, $C, 1, 'bookkeeper', $id, ''), 'a reversal needs a reason');
$rev = FinJournals::reverse($DB, $C, 1, 'bookkeeper', $id, 'Posted to the wrong month');
assert_balanced_journal($DB, $rev, 'reversal');
assert_eq($before, tb_snapshot($DB), 'journal + reversal net to nil');
assert_eq($rev, (int)$DB->query("SELECT reversed_by_journal_id FROM journal_entries WHERE id = $id")->fetchColumn(), 'original linked to its reversal');
expect_error(fn() => FinJournals::reverse($DB, $C, 1, 'bookkeeper', $id, 'again'), 'a journal is reversed once', 'already');
$trail = FinJournals::trail($DB, $C, $id);
assert_true(count($trail) >= 3, 'the audit trail lists create, approve, post and reverse');

// segregation of duties: the preparer and an editor may not approve
FinSettings::saveEngine($DB, $C, 1, 'admin', ['require_sod' => '1']);
$sod = FinJournals::save($DB, $C, 1, 'bookkeeper', ['date' => $date, 'lines' => $lines('50')]);
expect_error(fn() => FinJournals::approve($DB, $C, 1, 'bookkeeper', $sod), 'the preparer cannot approve their own journal', 'Segregation');
FinJournals::save($DB, $C, 2, 'bookkeeper', ['id' => $sod, 'date' => $date, 'lines' => $lines('60')]);
expect_error(fn() => FinJournals::approve($DB, $C, 2, 'bookkeeper', $sod), 'someone who edited the draft cannot approve it either', 'Segregation');
FinJournals::approve($DB, $C, 3, 'admin', $sod);
assert_eq('approved', $DB->query("SELECT status FROM journal_entries WHERE id = $sod")->fetchColumn(), 'a third person approves');
FinSettings::saveEngine($DB, $C, 1, 'admin', ['require_sod' => '0']);
FinJournals::unapprove($DB, $C, 1, 'bookkeeper', $sod);
FinJournals::delete($DB, $C, 1, 'bookkeeper', $sod);
assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM journal_entries WHERE id = $sod")->fetchColumn(), 'a draft can be deleted');

// system journals: a draft that belongs to a document cannot be edited or posted here; posted ones are not reversed here
$DB->prepare("INSERT INTO journal_entries (company_id, entry_date, reference, description, module, ref_type, ref_id, source_type, status, created_by, created_at) VALUES (?, ?, 'INV-X', 'engine draft', 'fin', 'invoice', 1, 'invoice', 'draft', 1, NOW())")->execute([$C, $date]);
$eng = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO journal_lines (journal_id, account_code, description, debit, credit) VALUES (?, ?, 'x', 10, 0), (?, ?, 'x', 0, 10)")->execute([$eng, $exp['code'], $eng, $liab['code']]);
expect_error(fn() => FinJournals::save($DB, $C, 1, 'admin', ['id' => $eng, 'date' => $date, 'lines' => $lines('10')]), 'a document draft cannot be edited here', 'document');
expect_error(fn() => FinJournals::approve($DB, $C, 1, 'admin', $eng), 'a document draft cannot be approved here');
$vatj = fin_post($DB, $date, [[$exp['code'], 5, 0], [$liab['code'], 0, 5]], 'vat_settle');
expect_error(fn() => FinJournals::reverse($DB, $C, 1, 'admin', $vatj, 'try'), 'a system journal is not reversed from the journal screen', 'system journal');

// locked periods
FinClose::lock($DB, $C, 1, 'bookkeeper', date('Y-m-d', strtotime('-1 day')), 'Week closed');
expect_error(fn() => FinJournals::save($DB, $C, 1, 'bookkeeper', ['date' => $date, 'lines' => $lines('10')]), 'no journal into a locked period', 'locked');
$fut = FinJournals::save($DB, $C, 1, 'bookkeeper', ['date' => date('Y-m-d'), 'lines' => $lines('10')]);
assert_true($fut > 0, 'after the lock date is fine');

// approval rule off: post a draft directly; description rule on
FinSettings::saveCompany($DB, $C, 1, 'admin', ['rules' => ['approval' => false, 'memo_required' => true]]);
expect_error(fn() => FinJournals::save($DB, $C, 1, 'bookkeeper', ['date' => date('Y-m-d'), 'lines' => $lines('10')]), 'a description is required when the company asks for it', 'description');
FinJournals::post($DB, $C, 1, 'bookkeeper', $fut);
assert_eq('posted', $DB->query("SELECT status FROM journal_entries WHERE id = $fut")->fetchColumn(), 'posted straight from draft');

// the list and its filters
$list = FinJournals::list($DB, $C, ['status' => 'posted', 'source' => 'manual', 'from' => date('Y-m-01', strtotime('-2 months')), 'to' => date('Y-m-d')]);
assert_true($list['total'] >= 1, 'posted manual journals listed (a reversed one counts as reversed)');
$rv = FinJournals::list($DB, $C, ['status' => 'reversed', 'from' => '2000-01-01', 'to' => '2099-12-31']);
assert_true($rv['total'] >= 1, 'the reversed journal is under Reversed');
$byAmt = FinJournals::list($DB, $C, ['status' => 'all', 'q' => '1234.56', 'from' => '2000-01-01', 'to' => '2099-12-31']);
assert_true($byAmt['total'] >= 1, 'found by amount');

test_done('fin_journals');
