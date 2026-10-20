# Payroll

South African payroll on the Maison design system: employees, pay runs,
payslips, the bank payments file, SARS returns (EMP201, EMP501) and posting
to Finance. Open to **admins and bookkeepers** of the active company only
(salaries, ID and bank numbers); settings and tax tables need an **admin**.

## Structure

```
payroll/
  index.php          Overview: the run that is due, last pay day, SARS owed, what needs fixing
  employees.php      Employees (js/employees.js) — dialog with Details, Pay, Tax & bank, Regular items, History
  runs.php           Pay runs + the new-run dialog (js/runs.js)
  run_view.php?id=   One run (js/run.js) — stages, money, people, payslip dialog with hours / overtime / bonus
  payitems.php       Pay items (js/items.js)
  reports.php        EMP201, EMP501, pay summary, cost by project (js/reports.js)
  settings.php       Schedule, overtime, SARS, Finance, checks, tax tables (js/settings.js)
  run_new.php, payslips.php   redirects for old links
  api/               JSON endpoints (CSRF on every POST via auth_gate; role check in _bootstrap.php)
    employees.php runs.php items.php settings.php reports.php (+ &format=csv) payslip.php bank.php
  lib/
    Pay.php          loads everything
    PayTax.php       tax arithmetic + the official SARS tables (no database; unit tested)
    PayEngine.php    one person's pay for one run (pure calculate() + loader)
    PayRuns.php      run lifecycle; every write locks the run row in a transaction
    PayEmployees.php PayItems.php PayReports.php PaySettings.php PayPayslips.php PayKit.php
    PaySchema.php    columns + tax tables provisioned on first use (mirrors Migrations/2026-10-21-payroll-rebuild.sql)
    PayBoot.php      page bootstrap (role gate, "Payroll is private" page)
    PayslipPdf.php   zero-dependency PDF payslip
  css/payroll.css  js/payroll.js (shared helpers, window.FWP)  partials/ (head, foot, icons)
```

## How a run works

| Stage | Status in `pay_runs` | What can happen |
|---|---|---|
| Being prepared | `draft` / `calculated` / `review` | add or remove people, hours, overtime, bonuses, once-off deductions; recalculate |
| Approved | `approved` | figures and bank details frozen after a final recalculation; send back to change |
| Finalised | `locked` | payslips stored under `storage/payroll/{company}/run_{id}/` (closed to the web, served by `api/payslip.php`), emailed when asked, bank file; an admin can reopen while it is not in the books |
| In the books | `posted` | `PostingService::postPayrollRun` wrote the journal; nothing changes |

Starting a run adds everyone on that frequency employed in the period
(pro-rated by calendar days when they start or leave part-way). A second run
over the same dates for the same frequency is refused — add bonuses to the
existing run. Changing someone's details recalculates them in every run being
prepared and says which approved runs still hold old figures.

## The tax maths (PayTax / PayEngine)

* Regular taxable pay × periods (12 / 26 / 52) → tax from the brackets → less
  rebates by age at the end of the tax year (65+, 75+; age from the SA ID
  number) → ÷ periods.
* Once-off pay (items marked *once-off*, e.g. bonus) is taxed at the margin on
  top of the annualised regular pay and deducted in full in that period.
* Medical scheme fees credit: per month for the first two people and each
  additional dependant (`employees.medical_members`), scaled for weekly and
  fortnightly pay.
* Retirement contributions (deductions marked *before tax*) lower taxable pay
  up to 27.5% of pay and R350 000 a year.
* UIF: 1% + 1% on pay up to R17 712 a month (R212 544 a year). SDL: 1% when
  the company is liable (`payroll_settings.sdl_liable`).
* A monthly salary pays × 12 ÷ 52 a week and × 12 ÷ 26 a fortnight.

## Each new tax year (after the February budget)

1. Add the new year to `PayTax::OFFICIAL` (brackets as `[from_cents, rate_bp]`,
   rebates, medical credits) from sars.gov.za → *Rates of tax for individuals*
   and *Medical tax credit rates*; check the UIF ceiling.
2. Add the expected figures to `tests/payroll/test_payroll_tax.php` and run it.
3. Deploy: `PaySchema` copies the new row into `tax_tables_za` on first use.

Until then a company admin can enter the new year under **Settings → Tax
tables** (their own table wins over Flowwork's for that year). The overview
warns in February and March when next year's table is missing.

## Finance

Posting (finances/lib/PostingService.php `postPayrollRun`): Dr wages (gross +
reimbursements + employer contributions), Dr employer UIF / SDL expense;
Cr bank (net pay), PAYE, UIF (both shares), SDL, and each deduction / employer
contribution to its pay item's account — else Salaries & Wages Payable (found
by name; created at the first free code from 2210 when the chart has none).
Earnings and reimbursements with their own expense account are debited there.

Controls: bank details are frozen when a run is approved — the bank file and
payslips use them, and the bank file names anyone whose details changed since.
Reopening a finalised run (admins) resets its payslips so corrected ones are
created and emailed again. Pay items and settings are admin-only.

## Tests

```
php tests/run.php payroll        # tests/payroll/test_payroll_tax.php, test_payroll_runs.php
                                 # and the Finance posting test, tests/finance/test_payroll.php
```
