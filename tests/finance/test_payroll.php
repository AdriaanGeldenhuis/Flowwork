<?php
/**
 * WS7: payroll posting — balanced journal, employer UIF/SDL split to their
 * expense accounts, configured bank account required (never guessed).
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$svc = new PostingService($DB, TEST_COMPANY_ID, TEST_USER_ID);
$map = new AccountsMap($DB, TEST_COMPANY_ID);

// A run: gross 10000, PAYE 1800, UIF ee 100 / er 100, SDL 100, net 8100.
$DB->prepare("INSERT INTO pay_runs (company_id, run_number, name, frequency, period_start, period_end, pay_date, status, created_by, created_at)
              VALUES (?, 'PRTEST-1', 'Test run', 'monthly', ?, ?, ?, 'locked', ?, NOW())")
   ->execute([TEST_COMPANY_ID, date('Y-m-01'), date('Y-m-t'), date('Y-m-d'), TEST_USER_ID]);
$runId = (int)$DB->lastInsertId();
$DB->prepare("INSERT INTO pay_run_employees (company_id, run_id, employee_id, gross_cents, taxable_income_cents,
              paye_cents, uif_employee_cents, uif_employer_cents, sdl_cents, other_deductions_cents,
              reimbursements_cents, net_cents, employer_cost_cents, bank_amount_cents)
              VALUES (?, ?, 1, 1000000, 1000000, 180000, 10000, 10000, 10000, 0, 0, 810000, 1020000, 810000)")
   ->execute([TEST_COMPANY_ID, $runId]);

// Without a configured payroll bank account, posting must refuse (not guess).
$DB->exec("UPDATE payroll_settings SET default_bank_account_id = NULL WHERE company_id = " . TEST_COMPANY_ID);
$threw = false;
try { $svc->postPayrollRun($runId); } catch (Exception $e) { $threw = true; }
assert_true($threw, 'payroll posting requires a configured bank account');

// Configure a bank account and post (create a GL-linked one if none exists).
$bankId = (int)$DB->query("SELECT id FROM gl_bank_accounts WHERE company_id = " . TEST_COMPANY_ID .
    " AND gl_account_id IS NOT NULL AND is_active = 1 LIMIT 1")->fetchColumn();
if (!$bankId) {
    $glBankAccountId = (int)$DB->query(
        "SELECT account_id FROM gl_accounts WHERE company_id = " . TEST_COMPANY_ID .
        " AND account_subtype = 'bank' AND is_active = 1 ORDER BY account_code LIMIT 1")->fetchColumn();
    $DB->prepare("INSERT INTO gl_bank_accounts (company_id, name, bank_name, account_no, currency, gl_account_id)
                  VALUES (?, 'Payroll Test Bank', 'TestBank', '000111', 'ZAR', ?)")
       ->execute([TEST_COMPANY_ID, $glBankAccountId]);
    $bankId = (int)$DB->lastInsertId();
}
assert_true($bankId > 0, 'a GL-linked bank account exists');
$upd = $DB->exec("UPDATE payroll_settings SET default_bank_account_id = $bankId WHERE company_id = " . TEST_COMPANY_ID);
if (!$upd) { // no payroll_settings row yet
    $DB->exec("INSERT INTO payroll_settings (company_id, default_bank_account_id) VALUES (" . TEST_COMPANY_ID . ", $bankId)");
}
$svc->postPayrollRun($runId);
$jid = (int)$DB->query("SELECT journal_id FROM pay_runs WHERE id = $runId")->fetchColumn();
assert_true($jid > 0, 'payroll journal created');
assert_balanced_journal($DB, $jid, '(payroll)');

// Employer UIF and SDL land on their own expense accounts, not in wages.
$uifExp = $map->code('finance_uif_expense_account_id');
$sdlExp = $map->code('finance_sdl_expense_account_id');
$wage   = $map->code('finance_wage_expense_account_id');
assert_eq(10000, gl_balance_cents($DB, $uifExp), 'employer UIF on its expense account (100.00)');
assert_eq(10000, gl_balance_cents($DB, $sdlExp), 'employer SDL on its expense account (100.00)');
assert_eq(1000000, gl_balance_cents($DB, $wage), 'wages = gross only (10000.00)');

// Liabilities: PAYE 1800, UIF 200 (ee+er), SDL 100 on the mapped accounts.
assert_eq(-180000, gl_balance_cents($DB, $map->code('finance_paye_account_id')), 'PAYE liability');
assert_eq(-20000, gl_balance_cents($DB, $map->code('finance_uif_account_id')), 'UIF liability (ee+er)');
assert_eq(-10000, gl_balance_cents($DB, $map->code('finance_sdl_account_id')), 'SDL liability');

test_done('payroll');
