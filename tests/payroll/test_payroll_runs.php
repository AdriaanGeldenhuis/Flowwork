<?php
/**
 * Payroll: a pay run from start to the books — people, regular items,
 * hours / overtime / bonus adjustments, approval checks, finalising with
 * payslips, the bank file, posting a balanced journal (deductions to their
 * own liability accounts), SARS returns and the guards that keep finished
 * runs unchanged.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/payroll/lib/Pay.php';

$C = TEST_COMPANY_ID;
$U = TEST_USER_ID;
PaySchema::ensure($DB);

$made = ['employees' => [], 'items' => [], 'runs' => []];
$prevSettings = $DB->query("SELECT * FROM payroll_settings WHERE company_id = $C")->fetch(PDO::FETCH_ASSOC);
try {
    // Bank account for posting
    $bankId = (int)$DB->query("SELECT id FROM gl_bank_accounts WHERE company_id = $C AND gl_account_id IS NOT NULL AND is_active = 1 LIMIT 1")->fetchColumn();
    if (!$bankId) {
        $gl = (int)$DB->query("SELECT account_id FROM gl_accounts WHERE company_id = $C AND account_subtype = 'bank' AND is_active = 1 ORDER BY account_code LIMIT 1")->fetchColumn();
        $DB->prepare("INSERT INTO gl_bank_accounts (company_id, name, bank_name, account_no, currency, gl_account_id) VALUES (?, 'Payroll Test Bank', 'TestBank', '000111', 'ZAR', ?)")->execute([$C, $gl]);
        $bankId = (int)$DB->lastInsertId();
    }
    PayKit::settings($DB, $C); // makes sure the row exists
    PaySettings::save($DB, $C, $U, ['bank_account_id' => $bankId, 'sdl_liable' => '1', 'auto_post_to_finance' => '0', 'paye_reference' => '7123456789',
                                    'monthly_anchor_day' => 25, 'weekly_anchor_day' => 5, 'hours_per_week' => 45]);
    $bad = false;
    try { PaySettings::save($DB, $C, $U, ['paye_reference' => '1234']); } catch (PayInvalid $e) { $bad = isset($e->errors['paye_reference']); }
    assert_true($bad, 'a PAYE reference must be 10 digits starting with 7');

    // Pay items
    $before = $DB->query("SELECT id FROM payitems WHERE company_id = $C")->fetchAll(PDO::FETCH_COLUMN);
    PayItems::addDefaults($DB, $C, $U);
    assert_eq(0, PayItems::addDefaults($DB, $C, $U), 'adding the usual items twice adds nothing the second time');
    $items = [];
    foreach (PayItems::list($DB, $C)['rows'] as $r) $items[$r['code']] = $r;
    foreach (['BASIC_SALARY', 'OVERTIME', 'BONUS', 'PENSION', 'MEDICAL'] as $code) assert_true(isset($items[$code]), "item $code exists");
    $made['items'] = array_values(array_diff(array_column(array_filter($items, fn($i) => !$i['system']), 'id'), $before));

    // People
    $base = ['employment_type' => 'permanent', 'pay_frequency' => 'monthly', 'uif_included' => 1, 'sdl_included' => 1, 'bank_name' => 'FNB', 'branch_code' => '250655', 'bank_account_no' => '62000000001'];
    [$anna] = PayEmployees::save($DB, $C, $U, array_merge($base, ['first_name' => 'Anna', 'last_name' => 'Test', 'employee_no' => 'PTEST-A', 'id_number' => '8001015009087', 'tax_number' => '0123456789',
                                                      'hire_date' => '2024-01-15', 'basis' => 'salary', 'monthly' => '30000', 'medical_members' => 2]));
    [$ben] = PayEmployees::save($DB, $C, $U, array_merge($base, ['first_name' => 'Ben', 'last_name' => 'Hourly', 'employee_no' => 'PTEST-B', 'tax_number' => '0123456780', 'birth_date' => '1990-04-04',
                                                     'hire_date' => '2025-02-01', 'basis' => 'hourly', 'hourly' => '80']));
    [$cara] = PayEmployees::save($DB, $C, $U, array_merge($base, ['first_name' => 'Cara', 'last_name' => 'New', 'employee_no' => 'PTEST-C', 'birth_date' => '1999-09-09',
                                                      'hire_date' => '2026-10-17', 'basis' => 'salary', 'monthly' => '20000', 'bank_account_no' => '', 'branch_code' => '']));
    [$dan] = PayEmployees::save($DB, $C, $U, array_merge($base, ['first_name' => 'Dan', 'last_name' => 'Weekly', 'employee_no' => 'PTEST-D', 'hire_date' => '2025-01-01',
                                                     'pay_frequency' => 'weekly', 'basis' => 'salary', 'monthly' => '15000']));
    $made['employees'] = [$anna, $ben, $cara, $dan];
    assert_eq('1980-01-01', PayEmployees::get($DB, $C, $anna)['birth_date'], 'birth date taken from the SA ID number');
    $err = null;
    try { PayEmployees::save($DB, $C, $U, array_merge($base, ['first_name' => 'X', 'last_name' => 'Y', 'employee_no' => 'PTEST-A', 'hire_date' => '2026-01-01', 'basis' => 'salary', 'monthly' => '1000'])); }
    catch (PayInvalid $e) { $err = $e->errors; }
    assert_true(isset($err['employee_no']), 'employee numbers are unique');
    $err = null;
    try { PayEmployees::save($DB, $C, $U, array_merge($base, ['first_name' => 'X', 'last_name' => 'Y', 'id_number' => '8001015009088', 'hire_date' => '2026-01-01', 'basis' => 'salary', 'monthly' => '1000'])); }
    catch (PayInvalid $e) { $err = $e->errors; }
    assert_true(isset($err['id_number']), 'a mistyped SA ID number is caught');

    // Anna: pension 7.5% of basic and R2 000 medical aid every month
    PayEmployees::saveItem($DB, $C, $U, $anna, ['payitem_id' => $items['PENSION']['id'], 'unit' => 'percent', 'percent' => '7.5']);
    PayEmployees::saveItem($DB, $C, $U, $anna, ['payitem_id' => $items['MEDICAL']['id'], 'unit' => 'fixed', 'amount' => '2000']);
    assert_eq(2, count(PayEmployees::items($DB, $C, $anna)), 'two regular items');

    // The October run
    $sug = PayKit::suggest($DB, $C, 'monthly', PayKit::settings($DB, $C));
    assert_true((bool)preg_match('/^\d{4}-\d{2}-01$/', $sug['period_start']), 'a monthly run starts on the 1st');
    $res = PayRuns::create($DB, $C, $U, ['frequency' => 'monthly', 'name' => 'October 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-23']);
    $runId = $res['id'];
    $made['runs'][] = $runId;
    $made['all_runs'][] = $runId;
    $run = PayRuns::get($DB, $C, $runId);
    $ids = array_column($run['people'], 'employee_id');
    assert_true(in_array($anna, $ids) && in_array($ben, $ids) && in_array($cara, $ids), 'monthly people are in the run');
    assert_true(!in_array($dan, $ids), 'weekly people are not');
    $dup = null;
    try { PayRuns::create($DB, $C, $U, ['frequency' => 'monthly', 'period_start' => '2026-10-15', 'period_end' => '2026-11-14', 'pay_date' => '2026-11-14']); } catch (PayInvalid $e) { $dup = $e->errors; }
    assert_true(isset($dup['period_start']), 'a second monthly run over the same dates is refused');

    // Cara started on the 17th: 15 of 31 days
    $p = PayRuns::person($DB, $C, $runId, $cara);
    assert_eq((int)round(2000000 * 15 / 31), $p['totals']['gross'], 'pro-rata first month');

    // Approval stops while Ben has no hours
    $msg = '';
    try { PayRuns::approve($DB, $C, $U, $runId); } catch (PayProblem $e) { $msg = $e->getMessage(); }
    assert_true(str_contains($msg, 'Ben Hourly'), 'approval names who still needs hours');
    PayRuns::addAdjustment($DB, $C, $U, $runId, $ben, ['kind' => 'hours', 'qty' => '160']);
    PayRuns::addAdjustment($DB, $C, $U, $runId, $anna, ['kind' => 'overtime', 'qty' => '4', 'when' => 'weekday']);
    PayRuns::addAdjustment($DB, $C, $U, $runId, $anna, ['kind' => 'item', 'payitem_id' => $items['BONUS']['id'], 'amount' => '5000', 'note' => 'Year-end']);
    $p = PayRuns::person($DB, $C, $runId, $ben);
    assert_eq(1280000, $p['totals']['gross'], '160 hours × R80');
    $p = PayRuns::person($DB, $C, $runId, $anna);
    $ot = (int)round(4 * round(PayEngine::hourlyRate(['hourly_rate_cents' => 0, 'base_salary_cents' => 3000000], 45) * 1.5));
    assert_eq(3000000 + $ot + 500000, $p['totals']['gross'], 'salary + overtime + bonus');
    assert_eq(225000 + 200000, $p['totals']['deductions'], 'pension and medical aid deducted');
    assert_true($p['totals']['paye'] > 0 && $p['totals']['uif_ee'] === 17712, 'PAYE worked out, UIF capped');
    $bonusLine = array_values(array_filter($p['lines'], fn($l) => $l['description'] === 'Bonus'))[0] ?? null;
    assert_true($bonusLine && $bonusLine['adjustment'] && $bonusLine['note'] === 'Year-end', 'the bonus is an adjustment with its note');

    // Recalculating keeps adjustments
    PayRuns::recalc($DB, $C, $U, $runId);
    $p2 = PayRuns::person($DB, $C, $runId, $anna);
    assert_eq($p['totals']['net'], $p2['totals']['net'], 'recalculation keeps the same figures');

    // Removing an adjustment
    PayRuns::removeAdjustment($DB, $C, $U, $runId, $bonusLine['id']);
    assert_eq(3000000 + $ot, PayRuns::person($DB, $C, $runId, $anna)['totals']['gross'], 'the bonus is gone');
    PayRuns::addAdjustment($DB, $C, $U, $runId, $anna, ['kind' => 'item', 'payitem_id' => $items['BONUS']['id'], 'amount' => '5000']);

    // A once-off loan deduction (its pay item has no account of its own) and a refund of R300 over-deducted
    $dedBefore = PayRuns::person($DB, $C, $runId, $anna)['totals']['deductions'];
    PayRuns::addAdjustment($DB, $C, $U, $runId, $anna, ['kind' => 'item', 'payitem_id' => $items['LOAN']['id'], 'amount' => '1000']);
    PayRuns::addAdjustment($DB, $C, $U, $runId, $anna, ['kind' => 'item', 'payitem_id' => $items['LOAN']['id'], 'amount' => '-300', 'description' => 'Loan refund']);
    assert_eq($dedBefore + 70000, PayRuns::person($DB, $C, $runId, $anna)['totals']['deductions'], 'R1 000 loan less a R300 refund deducts R700');
    // A commission and a correction to it
    PayRuns::addAdjustment($DB, $C, $U, $runId, $ben, ['kind' => 'item', 'payitem_id' => $items['COMMISSION']['id'], 'amount' => '2000']);
    PayRuns::addAdjustment($DB, $C, $U, $runId, $ben, ['kind' => 'item', 'payitem_id' => $items['COMMISSION']['id'], 'amount' => '-500', 'description' => 'Commission correction']);
    assert_eq(1280000 + 150000, PayRuns::person($DB, $C, $runId, $ben)['totals']['gross'], 'hours + R2 000 commission − R500 correction');

    // Approve → frozen
    PayRuns::approve($DB, $C, $U, $runId);
    assert_eq('approved', PayRuns::row($DB, $C, $runId)['status'], 'approved');
    $frozen = false;
    try { PayRuns::addAdjustment($DB, $C, $U, $runId, $anna, ['kind' => 'overtime', 'qty' => '1']); } catch (PayProblem $e) { $frozen = true; }
    assert_true($frozen, 'an approved run cannot change');
    $stale = PayEmployees::saveItem($DB, $C, $U, $anna, ['payitem_id' => $items['MEDICAL']['id'], 'unit' => 'fixed', 'amount' => '2000']);
    assert_true(in_array('stale', array_column($stale, 'action'), true), 'changing someone in an approved run says that run has old figures');
    $annaItems = PayEmployees::items($DB, $C, $anna);
    PayEmployees::removeItem($DB, $C, $U, $anna, (int)end($annaItems)['id']);
    PayRuns::reopen($DB, $C, $U, 'admin', $runId);
    assert_eq('calculated', PayRuns::row($DB, $C, $runId)['status'], 'sent back to preparation');
    PayRuns::approve($DB, $C, $U, $runId);


    // Finalise: payslips (no email in tests)
    $fin = PayRuns::finalise($DB, $C, $U, $runId, false);
    $peopleInRun = count(PayRuns::get($DB, $C, $runId)['people']);
    assert_eq($peopleInRun, $fin['payslips'], 'a payslip for everyone in the run');
    assert_eq('locked', PayRuns::row($DB, $C, $runId)['status'], 'finalised');
    [$pdf, $fn] = PayPayslips::pdf($DB, $C, $runId, $anna);
    assert_true(str_starts_with($pdf, '%PDF-1.4') && str_ends_with(trim($pdf), '%%EOF'), 'payslip is a PDF');
    assert_true(str_contains($fn, 'Anna-Test'), 'payslip file name has the person');
    assert_true(is_file(PayPayslips::path($C, $runId, $anna)), 'payslip stored under storage/payroll');
    $gone = false;
    try { PayRuns::delete($DB, $C, $U, $runId); } catch (PayProblem $e) { $gone = true; }
    assert_true($gone, 'a finalised run cannot be deleted');
    $renamed = false;
    try { PayRuns::rename($DB, $C, $U, $runId, 'Other name', ''); } catch (PayProblem $e) { $renamed = true; }
    assert_true($renamed, 'a finalised run keeps its name');

    // Reopening a finalised run resets its payslips so the corrected ones are emailed again
    $DB->prepare("UPDATE pay_run_employees SET payslip_emailed_at = NOW() WHERE run_id = ?")->execute([$runId]);
    PayRuns::reopen($DB, $C, $U, 'admin', $runId);
    assert_eq(0, (int)$DB->query("SELECT COUNT(*) FROM pay_run_employees WHERE run_id = $runId AND (payslip_emailed_at IS NOT NULL OR payslip_path IS NOT NULL)")->fetchColumn(), 'emailed and stored payslips are reset');
    assert_true(!is_file(PayPayslips::path($C, $runId, $anna)), 'the old payslip file is removed');
    PayRuns::approve($DB, $C, $U, $runId);
    PayRuns::finalise($DB, $C, $U, $runId, false);
    assert_true(is_file(PayPayslips::path($C, $runId, $anna)), 'finalising again creates a new payslip');

    // Bank details are frozen at approval: a change afterwards does not reach the bank file
    PayEmployees::save($DB, $C, $U, array_merge($base, ['id' => $anna, 'first_name' => 'Anna', 'last_name' => 'Test', 'employee_no' => 'PTEST-A', 'id_number' => '8001015009087',
        'tax_number' => '0123456789', 'hire_date' => '2024-01-15', 'basis' => 'salary', 'monthly' => '30000', 'medical_members' => 2, 'bank_account_no' => '99999999999']));

    // Bank file: Cara has no bank details
    $bank = PayRuns::bankRows($DB, $C, $runId);
    assert_eq($peopleInRun - 1, count($bank['rows']), 'everyone with bank details is in the bank file');
    assert_eq(['Cara New'], $bank['missing'], 'the one without bank details is named');
    $annaRow = array_values(array_filter($bank['rows'], fn($r) => $r['employee_no'] === 'PTEST-A'))[0] ?? null;
    assert_true(in_array('Anna Test', $bank['changed'], true), 'a bank account changed after approval is named');
    assert_eq('62000000001', $annaRow['bank_account_no'] ?? '', 'and the file pays into the account as approved');

    // Post to the books
    $before = (int)$DB->query("SELECT COUNT(*) FROM journal_entries WHERE company_id = $C")->fetchColumn();
    PayRuns::post($DB, $C, $U, $runId);
    $r = PayRuns::row($DB, $C, $runId);
    assert_eq('posted', $r['status'], 'in the books');
    assert_true((int)$r['journal_id'] > 0, 'journal linked');
    assert_balanced_journal($DB, (int)$r['journal_id'], '(payroll run)');
    $tot = PayRuns::get($DB, $C, $runId)['totals'];
    $wages = (float)$DB->query("SELECT SUM(debit) FROM journal_lines WHERE journal_id = {$r['journal_id']} AND description = 'Payroll Wages Expense'")->fetchColumn();
    assert_eq($tot['gross'] + $tot['reimb'], (int)round($wages * 100), 'wages expense = gross pay (deductions are not netted off)');
    $dedCredit = (float)$DB->query("SELECT SUM(credit) FROM journal_lines WHERE journal_id = {$r['journal_id']} AND description LIKE '%payable%' AND description NOT IN ('PAYE Payable','UIF Payable','SDL Payable')")->fetchColumn();
    assert_eq($tot['deductions'], (int)round($dedCredit * 100), 'deductions credited to what is owed to the fund / scheme');
    $loanTo = $DB->query("SELECT ga.account_name FROM journal_lines jl JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = $C
                           WHERE jl.journal_id = {$r['journal_id']} AND jl.description = 'Payroll deductions payable'")->fetchColumn();
    assert_true($loanTo && stripos($loanTo, 'wages payable') !== false, 'a deduction without its own account goes to Salaries & Wages Payable (' . $loanTo . '), never to whatever is numbered 2210');
    $twice = false;
    try { PayRuns::post($DB, $C, $U, $runId); } catch (PayProblem $e) { $twice = true; }
    assert_true($twice, 'posting twice is refused');
    $reopen = false;
    try { PayRuns::reopen($DB, $C, $U, 'admin', $runId); } catch (PayProblem $e) { $reopen = true; }
    assert_true($reopen, 'a run in the books cannot be reopened');

    // SARS returns
    $emp201 = PayReports::emp201($DB, $C, '2026-10');
    assert_eq($tot['paye'], $emp201['totals']['paye'], 'EMP201 PAYE');
    assert_eq($tot['uif_ee'] + $tot['uif_er'], $emp201['totals']['uif'], 'EMP201 UIF (employee + employer)');
    assert_eq($tot['sdl'], $emp201['totals']['sdl'], 'EMP201 SDL');
    assert_eq('2026-11-06', $emp201['due'], 'due on the last weekday before Saturday 7 November 2026');
    $annual = PayReports::recon($DB, $C, '2026-03-01', 'annual');
    assert_eq($tot['paye'], $annual['totals']['paye'], 'annual reconciliation includes October');
    assert_eq('2027-02-28', $annual['to'], 'annual runs to the end of February');
    $interim = PayReports::recon($DB, $C, '2026-03-01', 'interim');
    assert_eq(0, $interim['totals']['paye'], 'interim (March – August) does not include October');
    assert_eq(6, count($interim['months']), 'interim covers six months');
    $annaRow = array_values(array_filter($annual['people'], fn($x) => $x['id'] === $anna))[0];
    assert_true(isset($annaRow['codes']['3605']) && $annaRow['codes']['3605'] === 500000, 'the bonus is reported under source code 3605');
    $benRow = array_values(array_filter($annual['people'], fn($x) => $x['id'] === $ben))[0];
    assert_eq(150000, $benRow['codes']['3606'] ?? null, 'a commission correction reduces code 3606 (R2 000 − R500)');
    $sum = PayReports::summary($DB, $C, '2026-10-01', '2026-10-31');
    assert_eq($tot['net'], $sum['totals']['net'], 'summary net pay');

    // Paid people stay; never-paid people can be deleted
    $kept = false;
    try { PayEmployees::delete($DB, $C, $U, $anna); } catch (PayProblem $e) { $kept = true; }
    assert_true($kept, 'someone who was paid cannot be deleted');
    PayEmployees::delete($DB, $C, $U, $dan);
    $made['employees'] = array_diff($made['employees'], [$dan]);

    // The November run follows on and picks up a salary change while being prepared
    $sug = PayKit::suggest($DB, $C, 'monthly', PayKit::settings($DB, $C));
    assert_eq('2026-11-01', $sug['period_start'], 'the next run follows October');
    assert_eq('2026-11-25', $sug['pay_date'], 'pay day is the 25th (a Wednesday)');
    $nov = PayRuns::create($DB, $C, $U, $sug)['id'];
    $made['runs'][] = $nov;
    $before = PayRuns::person($DB, $C, $nov, $anna)['totals']['gross'];
    $e = PayEmployees::get($DB, $C, $anna);
    [, , $touched] = PayEmployees::save($DB, $C, $U, array_merge($base, ['id' => $anna, 'first_name' => 'Anna', 'last_name' => 'Test', 'employee_no' => 'PTEST-A', 'id_number' => '8001015009087',
                                                               'tax_number' => '0123456789', 'hire_date' => '2024-01-15', 'basis' => 'salary', 'monthly' => '33000', 'medical_members' => 2]));
    assert_true(in_array('updated', array_column($touched, 'action'), true), 'the open November run was updated');
    assert_eq(3300000, PayRuns::person($DB, $C, $nov, $anna)['totals']['gross'], 'November uses the new salary');
    PayEmployees::terminate($DB, $C, $U, $ben, '2026-10-31', 'resigned', '');
    $novIds = array_column(PayRuns::get($DB, $C, $nov)['people'], 'employee_id');
    assert_true(!in_array($ben, $novIds), 'someone who left in October is taken out of November');
    PayRuns::delete($DB, $C, $U, $nov);
    $made['runs'] = [$runId];
} finally {
    foreach ($made['runs'] as $rid) {
        foreach (glob(dirname(PayPayslips::path($C, $rid, 0)) . '/*.pdf') ?: [] as $f) @unlink($f);
        @rmdir(dirname(PayPayslips::path($C, $rid, 0)));
        $DB->exec("DELETE FROM pay_run_lines WHERE run_id = " . (int)$rid);
        $DB->exec("DELETE FROM pay_run_employees WHERE run_id = " . (int)$rid);
        $DB->exec("DELETE FROM pay_runs WHERE id = " . (int)$rid);
    }
    if ($made['employees']) {
        $in = implode(',', array_map('intval', $made['employees']));
        $DB->exec("DELETE FROM pay_run_lines WHERE employee_id IN ($in)");
        $DB->exec("DELETE FROM pay_run_employees WHERE employee_id IN ($in)");
        $DB->exec("DELETE FROM employee_payitems WHERE employee_id IN ($in)");
        $DB->exec("DELETE FROM employees WHERE id IN ($in)");
    }
    if ($made['items']) $DB->exec("DELETE FROM payitems WHERE id IN (" . implode(',', array_map('intval', $made['items'])) . ")");
    if ($prevSettings) {
        $DB->prepare("UPDATE payroll_settings SET default_bank_account_id = ?, sdl_liable = ?, auto_post_to_finance = ?, paye_reference = ? WHERE company_id = ?")
           ->execute([$prevSettings['default_bank_account_id'], $prevSettings['sdl_liable'] ?? 1, $prevSettings['auto_post_to_finance'], $prevSettings['paye_reference'] ?? null, $C]);
    }
}

test_done('payroll_runs');
