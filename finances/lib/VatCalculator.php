<?php
// finances/lib/VatCalculator.php
//
// Centralised VAT calculation for SARS VAT201 returns, from posted journal
// lines (invoice/accrual basis). Output and input tax are broken down by tax
// code so the VAT201 boxes (standard, zero-rated, exempt, capital goods) are
// populated exactly. Untagged revenue is NOT silently assumed standard-rated:
// it is reported separately so the health check can flag it.

class VatCalculator
{
    /**
     * Full SARS VAT201 box set for a period — the ONE computation every
     * consumer (report page, prepare screen, CSV export, period snapshot)
     * must share. Supplies figures exclude manual adjustment journals, which
     * are measured separately (Box 4 output / Box 6A input); Box 5/9/10 are
     * the SARS arithmetic:
     *   Box 5  = 1A + 4,  Box 9 = 6A + 7 + 8,  Box 10 = 5 - 9.
     */
    public static function vat201Boxes(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode
    ): array {
        $data = self::calculate($db, $companyId, $startDate, $endDate,
            $vatOutputCode, $vatInputCode, true);

        // Manual adjustments (module 'vat_adjust') post INTO the VAT output
        // and input accounts — measure them there. Output increases are
        // credits; input increases are debits.
        $stmt = $db->prepare(
            "SELECT jl.account_code,
                    SUM(jl.credit - jl.debit) AS credit_net,
                    SUM(jl.debit - jl.credit) AS debit_net
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND je.module = 'vat_adjust'
               AND jl.account_code IN (?, ?)
             GROUP BY jl.account_code"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode, $vatInputCode]);
        $adjOutputC = 0;
        $adjInputC = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['account_code'] === $vatOutputCode) {
                $adjOutputC = (int)round(((float)$row['credit_net']) * 100);
            } elseif ($row['account_code'] === $vatInputCode) {
                $adjInputC = (int)round(((float)$row['debit_net']) * 100);
            }
        }
        $data['change_in_use_output_cents'] = $adjOutputC;
        $data['change_in_use_input_cents']  = $adjInputC;
        $data['box5_total_output_cents']    = $data['total_output_vat_cents'] + $adjOutputC;
        $data['box9_total_input_cents']     = $data['total_input_vat_cents'] + $adjInputC;
        $data['box10_net_cents']            = $data['box5_total_output_cents'] - $data['box9_total_input_cents'];
        return $data;
    }

    /**
     * Calculate VAT totals for a date range on the invoice (accrual) basis.
     *
     * @param PDO    $db
     * @param int    $companyId
     * @param string $startDate     YYYY-MM-DD
     * @param string $endDate       YYYY-MM-DD
     * @param string $vatOutputCode GL account code for output VAT
     * @param string $vatInputCode  GL account code for input VAT
     * @return array cents-denominated VAT201 aggregates
     */
    public static function calculate(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode,
        bool $excludeAdjustments = false
    ): array {
        // Manual VAT201 adjustments (module 'vat_adjust') post directly into
        // the output/input accounts. For VAT201 presentation the "supplies"
        // figures (Box 1A / 6-8) must EXCLUDE them — they are reported in
        // Box 4 / Box 6A separately — otherwise they double-count.
        $adjFilter = $excludeAdjustments ? " AND COALESCE(je.module,'') <> 'vat_adjust'" : '';

        // --- Output VAT by tax code ---
        $stmt = $db->prepare(
            "SELECT
                COALESCE(tc.code, 'UNTAGGED') AS tax_code,
                SUM(jl.credit - jl.debit) AS vat_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             LEFT JOIN gl_tax_codes tc ON jl.tax_code_id = tc.tax_code_id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND jl.account_code = ?$adjFilter
             GROUP BY COALESCE(tc.code, 'UNTAGGED')"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode]);
        $outputStandardVat = 0.0;
        $totalOutputVat = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float)$row['vat_amount'];
            $totalOutputVat += $amt;
            $code = strtoupper($row['tax_code']);
            if ($code === 'ZERO' || $code === 'EXEMPT') {
                continue; // zero/exempt VAT should be 0 by definition
            }
            $outputStandardVat += $amt;
        }

        // --- Output tax BASE amounts from revenue lines, by tax code ---
        $stmt = $db->prepare(
            "SELECT
                COALESCE(tc.code, 'UNTAGGED') AS tax_code,
                SUM(jl.credit - jl.debit) AS base_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
             LEFT JOIN gl_tax_codes tc ON jl.tax_code_id = tc.tax_code_id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND ga.account_type = 'revenue'
             GROUP BY COALESCE(tc.code, 'UNTAGGED')"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        $outputStandardBase = 0.0;
        $outputZeroBase = 0.0;
        $outputExemptBase = 0.0;
        $untaggedRevenueBase = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float)$row['base_amount'];
            switch (strtoupper($row['tax_code'])) {
                case 'ZERO':
                    $outputZeroBase += $amt;
                    break;
                case 'EXEMPT':
                    $outputExemptBase += $amt;
                    break;
                case 'UNTAGGED':
                    // Not silently standard-rated: reported for the health
                    // check. (Every posting path now tags revenue lines, so
                    // anything here is legacy or hand-crafted data.)
                    $untaggedRevenueBase += $amt;
                    break;
                default:
                    $outputStandardBase += $amt;
            }
        }

        // --- Input VAT: capital goods (Box 7) vs other, from the explicit
        // is_capital_goods flag written by the posting engine ---
        $stmt = $db->prepare(
            "SELECT
                CASE WHEN jl.is_capital_goods = 1 THEN 'capital' ELSE 'other' END AS input_category,
                SUM(jl.debit - jl.credit) AS vat_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND jl.account_code = ?$adjFilter
             GROUP BY input_category"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatInputCode]);
        $inputCapital = 0.0;
        $inputOther = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float)$row['vat_amount'];
            if ($row['input_category'] === 'capital') {
                $inputCapital += $amt;
            } else {
                $inputOther += $amt;
            }
        }
        $totalInputVat = $inputCapital + $inputOther;

        return [
            'output_standard_base_cents' => (int)round($outputStandardBase * 100),
            'output_standard_vat_cents'  => (int)round($outputStandardVat * 100),
            'output_zero_base_cents'     => (int)round($outputZeroBase * 100),
            'output_exempt_base_cents'   => (int)round($outputExemptBase * 100),
            'untagged_revenue_base_cents'=> (int)round($untaggedRevenueBase * 100),
            'total_output_vat_cents'     => (int)round($totalOutputVat * 100),
            'input_capital_cents'        => (int)round($inputCapital * 100),
            'input_other_cents'          => (int)round($inputOther * 100),
            'total_input_vat_cents'      => (int)round($totalInputVat * 100),
            'net_vat_cents'              => (int)round($totalOutputVat * 100) - (int)round($totalInputVat * 100),
        ];
    }
}
