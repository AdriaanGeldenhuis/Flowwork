<?php
// /payroll/lib/PayTax.php — South African employees' tax arithmetic (no database).
//
// Everything here is plain integer-cent maths so it can be tested against
// SARS's own published figures (tests/payroll/test_payroll_tax.php).
//
// Official tables (sars.gov.za → Tax rates → Rates of tax for individuals,
// and → Medical tax credit rates; UIF ceiling: Government Gazette 44641 of
// 28 May 2021 — R17 712 a month / R212 544 a year since 1 June 2021).
// A tax year runs 1 March – end of February and is named after the year it
// ends in: "2027" is 1 March 2026 – 28 February 2027.
//
// Brackets are [from_cents, rate_bp]: the rate (basis points, 1800 = 18%)
// applies to income above `from`. The base amount of each bracket is worked
// out from the brackets below it, so a table only needs limits and rates.

final class PayTax
{
    /** Pay periods in a year for each pay frequency. */
    public const PERIODS = ['monthly' => 12, 'fortnight' => 26, 'weekly' => 52];

    /** UIF: 1% from the employee and 1% from the employer, on pay up to the ceiling. */
    public const UIF_RATE_BP = 100;
    public const UIF_CEILING_YEAR_CENTS = 21254400; // R17 712 × 12
    /** SDL: 1% of pay, for employers whose payroll is over R500 000 a year. */
    public const SDL_RATE_BP = 100;
    /** Retirement fund contributions are deductible up to 27.5% of pay, at most R350 000 a year. */
    public const RETIREMENT_CAP_BP = 2750;
    public const RETIREMENT_CAP_YEAR_CENTS = 35000000;

    /**
     * Official SARS tables, keyed by the first day of the tax year.
     * medical = monthly credit for the main member and the first dependant
     * (each), and for every additional dependant.
     */
    public const OFFICIAL = [
        '2024-03-01' => [
            'year' => 2025, 'to' => '2025-02-28',
            'brackets' => [[0, 1800], [23710000, 2600], [37050000, 3100], [51280000, 3600], [67300000, 3900], [85790000, 4100], [181700000, 4500]],
            'rebates' => [1723500, 944400, 314500], 'medical' => [36400, 24600],
        ],
        '2025-03-01' => [
            'year' => 2026, 'to' => '2026-02-28',
            'brackets' => [[0, 1800], [23710000, 2600], [37050000, 3100], [51280000, 3600], [67300000, 3900], [85790000, 4100], [181700000, 4500]],
            'rebates' => [1723500, 944400, 314500], 'medical' => [36400, 24600],
        ],
        '2026-03-01' => [
            'year' => 2027, 'to' => '2027-02-28',
            'brackets' => [[0, 1800], [24510000, 2600], [38310000, 3100], [53020000, 3600], [69580000, 3900], [88700000, 4100], [187860000, 4500]],
            'rebates' => [1782000, 976500, 324900], 'medical' => [37600, 25400],
        ],
    ];

    /** First day of the tax year a date falls in ("2026-10-25" → "2026-03-01"). */
    public static function taxYearStart(string $date): string
    {
        $t = strtotime($date);
        $y = (int)date('Y', $t);
        if ((int)date('n', $t) < 3) $y--;
        return $y . '-03-01';
    }

    /** Last day of the tax year a date falls in (28 or 29 February). */
    public static function taxYearEnd(string $date): string
    {
        $y = (int)substr(self::taxYearStart($date), 0, 4) + 1;
        return date('Y-m-t', strtotime($y . '-02-01'));
    }

    /** "2026/27" for any date in the tax year that ends in February 2027. */
    public static function taxYearLabel(string $date): string
    {
        $y = (int)substr(self::taxYearStart($date), 0, 4);
        return $y . '/' . substr((string)($y + 1), 2);
    }

    /** Brackets with their base amounts: [[from, rate_bp, base], …] sorted by from. */
    public static function withBases(array $brackets): array
    {
        usort($brackets, fn($a, $b) => (int)$a[0] <=> (int)$b[0]);
        $out = [];
        $base = 0;
        foreach ($brackets as $i => $b) {
            if ($i > 0) {
                [$pf, $pr] = $brackets[$i - 1];
                $base += intdiv(((int)$b[0] - (int)$pf) * (int)$pr, 10000);
            }
            $out[] = [(int)$b[0], (int)$b[1], $base];
        }
        return $out;
    }

    /** Tax on a year's taxable income, before rebates (cents, rounded to the cent). */
    public static function annualTax(int $taxableCents, array $brackets): int
    {
        if ($taxableCents <= 0) return 0;
        $tax = 0;
        foreach (self::withBases($brackets) as [$from, $rate, $base]) {
            if ($taxableCents > $from) $tax = $base + (int)round(($taxableCents - $from) * $rate / 10000);
        }
        return $tax;
    }

    /** Rebates for someone of $age on the last day of the tax year (null age: primary only). */
    public static function rebate(array $rebates, ?int $age): int
    {
        $r = (int)($rebates[0] ?? 0);
        if ($age !== null && $age >= 65) $r += (int)($rebates[1] ?? 0);
        if ($age !== null && $age >= 75) $r += (int)($rebates[2] ?? 0);
        return $r;
    }

    /** Monthly medical scheme fees tax credit for $members people on the scheme (main member counts). */
    public static function medicalCreditMonthly(array $medical, int $members): int
    {
        if ($members <= 0) return 0;
        [$main, $extra] = [(int)($medical[0] ?? 0), (int)($medical[1] ?? 0)];
        return $main * min(2, $members) + $extra * max(0, $members - 2);
    }

    /**
     * Employees' tax for one pay period, the SARS way:
     *   regular income is annualised (× periods), taxed, less rebates, ÷ periods;
     *   once-off income (bonus, annual payment) is taxed at the margin on top
     *   of the annualised regular income and deducted in full in this period;
     *   the medical scheme fees credit is taken off last.
     *
     * @param array $table   ['brackets' => …, 'rebates' => …, 'medical' => …]
     * @return array ['paye' => cents, 'steps' => explanation]
     */
    public static function paye(array $table, string $frequency, int $regularCents, int $irregularCents, ?int $age, int $medicalMembers): array
    {
        $periods = self::PERIODS[$frequency] ?? 12;
        $regularCents = max(0, $regularCents);
        $irregularCents = max(0, $irregularCents);
        $annual = $regularCents * $periods;
        $rebate = self::rebate($table['rebates'], $age);
        $taxAnnual = self::annualTax($annual, $table['brackets']);
        $regularYear = max(0, $taxAnnual - $rebate);
        $regularPeriod = (int)round($regularYear / $periods);
        $bonusTax = 0;
        if ($irregularCents > 0) {
            $withBonus = max(0, self::annualTax($annual + $irregularCents, $table['brackets']) - $rebate);
            $bonusTax = max(0, $withBonus - $regularYear);
        }
        $medical = (int)round(self::medicalCreditMonthly($table['medical'], $medicalMembers) * 12 / $periods);
        $before = $regularPeriod + $bonusTax;
        $paye = max(0, $before - $medical);
        return ['paye' => $paye, 'steps' => [
            'periods' => $periods, 'annual_income' => $annual, 'tax_before_rebates' => $taxAnnual, 'rebates' => $rebate, 'age' => $age,
            'tax_on_regular_year' => $regularYear, 'tax_on_regular_period' => $regularPeriod,
            'once_off_income' => $irregularCents, 'tax_on_once_off' => $bonusTax,
            'medical_members' => $medicalMembers, 'medical_credit' => min($medical, $before), 'paye' => $paye,
        ]];
    }

    /** UIF for one period: [employee, employer, base used]. */
    public static function uif(string $frequency, int $uifPayCents, ?int $ceilingYearCents = null): array
    {
        $periods = self::PERIODS[$frequency] ?? 12;
        $cap = (int)round(($ceilingYearCents ?: self::UIF_CEILING_YEAR_CENTS) / $periods);
        $base = min(max(0, $uifPayCents), $cap);
        $each = (int)round($base * self::UIF_RATE_BP / 10000);
        return [$each, $each, $base, $cap];
    }

    /** SDL for one period (employer only). */
    public static function sdl(int $sdlPayCents): int
    {
        return (int)round(max(0, $sdlPayCents) * self::SDL_RATE_BP / 10000);
    }

    /** Retirement contributions that may reduce taxable income this period. */
    public static function retirementDeductible(string $frequency, int $contributionsCents, int $taxablePayCents): int
    {
        $periods = self::PERIODS[$frequency] ?? 12;
        $cap = min((int)floor(max(0, $taxablePayCents) * self::RETIREMENT_CAP_BP / 10000), intdiv(self::RETIREMENT_CAP_YEAR_CENTS, $periods));
        return max(0, min($contributionsCents, $cap));
    }

    /**
     * A South African ID number's date of birth, or null when the number is
     * not a valid 13-digit SA ID (checks the Luhn digit). Two-digit years
     * are placed in the century that keeps the person under 100 today.
     */
    public static function birthDateFromId(string $id): ?string
    {
        $id = preg_replace('/\D/', '', $id);
        if (strlen($id) !== 13 || !self::luhn($id)) return null;
        $yy = (int)substr($id, 0, 2);
        $mm = (int)substr($id, 2, 2);
        $dd = (int)substr($id, 4, 2);
        $now = (int)date('Y');
        $year = 2000 + $yy;
        if ($year > $now) $year -= 100;
        if (!checkdate($mm, $dd, $year)) return null;
        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int)$digits[$i];
            if ($alt) { $n *= 2; if ($n > 9) $n -= 9; }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    /** Age on the last day of the tax year that $date falls in (null when unknown). */
    public static function ageAtYearEnd(?string $birthDate, string $date): ?int
    {
        if (!$birthDate || !strtotime($birthDate)) return null;
        $b = new DateTimeImmutable($birthDate);
        $end = new DateTimeImmutable(self::taxYearEnd($date));
        return $b > $end ? 0 : $b->diff($end)->y;
    }
}
