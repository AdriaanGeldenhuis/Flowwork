<?php
/**
 * Payroll: employees' tax, UIF and SDL against SARS's published tables
 * (no database). Expected values are worked by hand from the official
 * brackets, rebates and medical credits on sars.gov.za.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/payroll/lib/PayEngine.php';

$y27 = ['brackets' => PayTax::OFFICIAL['2026-03-01']['brackets'], 'rebates' => PayTax::OFFICIAL['2026-03-01']['rebates'],
        'medical' => PayTax::OFFICIAL['2026-03-01']['medical'], 'uif_ceiling_year' => PayTax::UIF_CEILING_YEAR_CENTS, 'label' => '2026/27', 'source' => 'built-in'];
$y26 = ['brackets' => PayTax::OFFICIAL['2025-03-01']['brackets'], 'rebates' => PayTax::OFFICIAL['2025-03-01']['rebates'],
        'medical' => PayTax::OFFICIAL['2025-03-01']['medical'], 'uif_ceiling_year' => PayTax::UIF_CEILING_YEAR_CENTS, 'label' => '2025/26', 'source' => 'built-in'];

// Bracket base amounts are derived exactly as SARS publishes them
$bases = array_column(PayTax::withBases($y27['brackets']), 2);
assert_eq([0, 4411800, 7999800, 12559900, 18521500, 25978300, 66633900], $bases, '2026/27 base amounts (R44 118, R79 998 … R666 339)');
$bases = array_column(PayTax::withBases($y26['brackets']), 2);
assert_eq([0, 4267800, 7736200, 12147500, 17914700, 25125800, 64448900], $bases, '2025/26 base amounts (R42 678 … R644 489)');

// Annual tax before rebates
assert_eq(7399200, PayTax::annualTax(36000000, $y27['brackets']), 'R360 000 a year → R73 992 before rebates');
assert_eq(0, PayTax::annualTax(0, $y27['brackets']), 'no income, no tax');

// Monthly PAYE
$p = PayTax::paye($y27, 'monthly', 3000000, 0, 40, 0);
assert_eq(468100, $p['paye'], 'R30 000 a month (2026/27, under 65) → R4 681.00');
$p = PayTax::paye($y27, 'monthly', 800000, 0, 40, 0);
assert_eq(0, $p['paye'], 'R8 000 a month is under the R99 000 threshold → no PAYE');
$p = PayTax::paye($y26, 'monthly', 800000, 0, 40, 0);
assert_eq(375, $p['paye'], 'R8 000 a month in 2025/26 (threshold R95 750) → R3.75');
$p = PayTax::paye($y27, 'monthly', 2000000, 0, 66, 0);
assert_eq(130125, $p['paye'], 'R20 000 a month, aged 66 → secondary rebate → R1 301.25');
$p = PayTax::paye($y27, 'monthly', 2000000, 0, 76, 0);
assert_eq(103050, $p['paye'], 'R20 000 a month, aged 76 → tertiary rebate too → R1 030.50');
$p = PayTax::paye($y27, 'monthly', 20000000, 0, 40, 0);
assert_eq(7359575, $p['paye'], 'R200 000 a month → top bracket → R73 595.75');

// Medical scheme fees credit: R376 + R376 + R254 for three people
assert_eq(100600, PayTax::medicalCreditMonthly($y27['medical'], 3), 'medical credit for 3 people = R1 006 a month');
$p = PayTax::paye($y27, 'monthly', 3000000, 0, 40, 3);
assert_eq(367500, $p['paye'], 'R30 000 with 3 on medical aid → R4 681 − R1 006 = R3 675.00');
$p = PayTax::paye($y27, 'monthly', 500000, 0, 40, 4);
assert_eq(0, $p['paye'], 'medical credit never makes PAYE negative');

// Once-off income (bonus) is taxed at the margin, not annualised
$p = PayTax::paye($y27, 'monthly', 3000000, 3000000, 40, 0);
assert_eq(1282600, $p['paye'], 'R30 000 salary + R30 000 bonus → R4 681 + R8 145 = R12 826.00');

// Weekly and fortnightly annualise by 52 and 26
$p = PayTax::paye($y27, 'weekly', 300000, 0, 40, 0);
assert_eq(19731, $p['paye'], 'R3 000 a week → R197.31');
$p = PayTax::paye($y27, 'fortnight', 600000, 0, 40, 0);
assert_eq(39462, $p['paye'], 'R6 000 a fortnight → R394.62');

// UIF: 1% each, capped at R17 712 a month (R212 544 a year)
assert_eq([17712, 17712, 1771200, 1771200], PayTax::uif('monthly', 3000000), 'UIF on R30 000 a month is capped: R177.12 each');
assert_eq([3000, 3000, 300000, 408738], PayTax::uif('weekly', 300000), 'UIF on R3 000 a week: R30.00 each (weekly cap R4 087.38)');
assert_eq([10000, 10000, 1000000, 1771200], PayTax::uif('monthly', 1000000), 'UIF under the cap: 1% of R10 000');
assert_eq(30000, PayTax::sdl(3000000), 'SDL is 1% with no cap');

// Retirement contributions lower taxable pay (27.5%, R350 000 a year)
assert_eq(225000, PayTax::retirementDeductible('monthly', 225000, 3000000), 'R2 250 pension on R30 000 is fully deductible');
assert_eq(825000, PayTax::retirementDeductible('monthly', 1000000, 3000000), '27.5% limit: R8 250 of R10 000');
assert_eq(2916666, PayTax::retirementDeductible('monthly', 5000000, 30000000), 'R350 000 a year limit: R29 166.66 a month');

// Tax years
assert_eq('2026-03-01', PayTax::taxYearStart('2026-10-25'), 'October 2026 is in the year from 1 March 2026');
assert_eq('2025-03-01', PayTax::taxYearStart('2026-02-28'), 'February 2026 belongs to the year that started March 2025');
assert_eq('2028-02-29', PayTax::taxYearEnd('2027-06-01'), 'leap years end on 29 February');
assert_eq('2026/27', PayTax::taxYearLabel('2026-10-25'), 'tax year label');

// SA ID numbers
assert_eq('1980-01-01', PayTax::birthDateFromId('8001015009087'), 'birth date from a valid SA ID');
assert_eq(null, PayTax::birthDateFromId('8001015009088'), 'bad check digit is refused');
assert_eq(null, PayTax::birthDateFromId('12345'), 'short numbers are refused');
assert_eq(46, PayTax::ageAtYearEnd('1980-06-15', '2026-10-25'), 'age on 28 Feb 2027');

// ---------------------------------------------------------------- the engine
$basic = ['id' => 1, 'code' => 'BASIC_SALARY', 'name' => 'Basic salary', 'type' => 'earning', 'taxable' => 1, 'uif_subject' => 1, 'sdl_subject' => 1, 'pre_tax' => 0, 'irregular' => 0];
$items = [
    1 => $basic,
    2 => ['id' => 2, 'name' => 'Pension fund', 'type' => 'deduction', 'taxable' => 0, 'uif_subject' => 0, 'sdl_subject' => 0, 'pre_tax' => 1, 'irregular' => 0],
    3 => ['id' => 3, 'name' => 'Bonus', 'type' => 'earning', 'taxable' => 1, 'uif_subject' => 1, 'sdl_subject' => 1, 'pre_tax' => 0, 'irregular' => 1],
    4 => ['id' => 4, 'name' => 'Petrol', 'type' => 'reimbursement', 'taxable' => 0, 'uif_subject' => 0, 'sdl_subject' => 0, 'pre_tax' => 0, 'irregular' => 0],
    5 => ['id' => 5, 'name' => 'Company car', 'type' => 'benefit', 'taxable' => 1, 'uif_subject' => 0, 'sdl_subject' => 1, 'pre_tax' => 0, 'irregular' => 0],
    6 => ['id' => 6, 'name' => 'Loan', 'type' => 'deduction', 'taxable' => 0, 'uif_subject' => 0, 'sdl_subject' => 0, 'pre_tax' => 0, 'irregular' => 0],
];
$emp = ['id' => 7, 'base_salary_cents' => 3000000, 'hourly_rate_cents' => 0, 'hire_date' => '2020-01-01', 'termination_date' => null,
        'birth_date' => '1985-05-05', 'medical_members' => 0, 'uif_included' => 1, 'sdl_included' => 1, 'tax_number' => '0123456789', 'bank_account_no' => '123'];
$run = ['id' => 1, 'frequency' => 'monthly', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-25'];

$r = PayEngine::calculate($emp, $run, ['sdl_liable' => true], $y27, $items, $basic, [], []);
assert_eq(3000000, $r['gross_cents'], 'gross = salary');
assert_eq(468100, $r['paye_cents'], 'engine PAYE matches the table');
assert_eq(17712, $r['uif_employee_cents'], 'engine UIF capped');
assert_eq(30000, $r['sdl_cents'], 'engine SDL 1%');
assert_eq(3000000 - 468100 - 17712, $r['net_cents'], 'net = gross − PAYE − UIF');
assert_eq(3000000 + 17712 + 30000, $r['employer_cost_cents'], 'cost to company = gross + employer UIF + SDL');
assert_eq([], $r['debug']['warnings'], 'no warnings for a complete record');

$r = PayEngine::calculate($emp, $run, ['sdl_liable' => false], $y27, $items, $basic, [], []);
assert_eq(0, $r['sdl_cents'], 'no SDL when the company is not liable');

// Pension 7.5% of basic (regular item), petrol reimbursement, car benefit, bonus and a loan (adjustments)
$recurring = [['payitem_id' => 2, 'unit' => 'percent', 'rate_cents' => 750, 'amount_cents' => 0]];
$adjust = [
    ['payitem_id' => 3, 'description' => 'Bonus', 'qty' => 1, 'rate_cents' => 1000000, 'amount_cents' => 1000000],
    ['payitem_id' => 4, 'description' => 'Petrol', 'qty' => 1, 'rate_cents' => 50000, 'amount_cents' => 50000],
    ['payitem_id' => 5, 'description' => 'Company car', 'qty' => 1, 'rate_cents' => 200000, 'amount_cents' => 200000],
    ['payitem_id' => 6, 'description' => 'Loan', 'qty' => 1, 'rate_cents' => -100000, 'amount_cents' => -100000],
];
$r = PayEngine::calculate($emp, $run, ['sdl_liable' => true], $y27, $items, $basic, $recurring, $adjust);
assert_eq(4000000, $r['gross_cents'], 'cash earnings = salary + bonus (benefit is not cash)');
assert_eq(50000, $r['reimbursements_cents'], 'reimbursement paid back');
assert_eq(225000 + 100000, $r['other_deductions_cents'], 'pension + loan deducted');
// Taxable regular: 30 000 + 2 000 car − 2 250 pension = 29 750 → ×12 = 357 000
// tax = 44 118 + 26% × 111 900 = 73 212 − 17 820 = 55 392 → 4 616.00 a month
// with the R10 000 bonus: 367 000 → 44 118 + 26% × 121 900 = 75 812 − 17 820 = 57 992 → bonus tax 2 600
assert_eq(461600 + 260000, $r['paye_cents'], 'PAYE: regular R4 616.00 + bonus R2 600.00');
assert_eq(17712, $r['uif_employee_cents'], 'UIF still capped');
assert_eq((int)round((3000000 + 1000000 + 200000) / 100), $r['sdl_cents'], 'SDL on salary, bonus and the car benefit');
assert_eq(4000000 + 50000 - (461600 + 260000) - 17712 - 325000, $r['net_cents'], 'net pay');

// Starting mid-month: pro-rata by calendar days
$late = $emp;
$late['hire_date'] = '2026-10-17';
$r = PayEngine::calculate($late, $run, ['sdl_liable' => true], $y27, $items, $basic, [], []);
assert_eq((int)round(3000000 * 15 / 31), $r['gross_cents'], 'started on the 17th → 15 of 31 days');
assert_true(str_contains($r['lines'][0]['description'], '15 of 31'), 'the payslip says how many days');

// Weekly pay from a monthly salary: × 12 ÷ 52 (not ÷ 4.33); fortnightly × 12 ÷ 26 (not ÷ 2)
assert_eq(692308, PayEngine::periodSalary(3000000, 'weekly'), 'R30 000 a month = R6 923.08 a week');
assert_eq(1384615, PayEngine::periodSalary(3000000, 'fortnight'), 'R30 000 a month = R13 846.15 a fortnight');

// Hourly-paid person with no hours entered is flagged, not paid a salary
$hourly = $emp;
$hourly['base_salary_cents'] = 0;
$hourly['hourly_rate_cents'] = 8000;
$r = PayEngine::calculate($hourly, $run, ['sdl_liable' => true], $y27, $items, $basic, [], []);
assert_eq(0, $r['gross_cents'], 'no hours, no pay');
assert_true(in_array('no_hours', $r['debug']['warnings'], true), 'warned that hours are missing');
$r = PayEngine::calculate($hourly, $run, ['sdl_liable' => true], $y27, $items, $basic, [], [
    ['payitem_id' => 1, 'description' => 'Hours worked', 'qty' => 160, 'rate_cents' => 8000, 'amount_cents' => 1280000]]);
assert_eq(1280000, $r['gross_cents'], '160 hours × R80');
assert_eq(12800, $r['uif_employee_cents'], 'UIF on hourly pay');

// Deductions larger than pay are flagged
$r = PayEngine::calculate($emp, $run, ['sdl_liable' => true], $y27, $items, $basic, [], [
    ['payitem_id' => 6, 'description' => 'Loan', 'qty' => 1, 'rate_cents' => -5000000, 'amount_cents' => -5000000]]);
assert_true(in_array('negative_net', $r['debug']['warnings'], true), 'negative net pay is flagged');
assert_eq(0, $r['bank_amount_cents'], 'nothing is paid into the bank when net is negative');

// Overtime rate from salary: R30 000 ÷ (45 × 52 ÷ 12) = R153.85 an hour
assert_eq(15385, PayEngine::hourlyRate($emp, 45), 'hourly rate from a monthly salary');

test_done('payroll_tax');
