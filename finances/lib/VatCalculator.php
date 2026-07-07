<?php
// finances/lib/VatCalculator.php
//
// Centralised VAT calculation helper for SARS VAT201 returns.
// Breaks down output and input VAT by tax code so that the VAT201
// fields (standard rated, zero rated, exempt, capital goods, other)
// are populated correctly.

class VatCalculator
{
    /**
     * Calculate VAT totals for a given date range, broken down by
     * SARS VAT201 categories.
     *
     * @param PDO    $db
     * @param int    $companyId
     * @param string $startDate  YYYY-MM-DD
     * @param string $endDate    YYYY-MM-DD
     * @param string $vatOutputCode  GL account code for output VAT (e.g. '2110')
     * @param string $vatInputCode   GL account code for input VAT (e.g. '2120')
     * @return array
     */
    public static function calculate(
        PDO $db,
        int $companyId,
        string $startDate,
        string $endDate,
        string $vatOutputCode,
        string $vatInputCode
    ): array {
        // --- Output VAT breakdown by tax code ---
        $stmt = $db->prepare(
            "SELECT
                COALESCE(tc.code, 'STD') AS tax_code,
                COALESCE(tc.type, 'output') AS tax_type,
                SUM(jl.credit - jl.debit) AS vat_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             LEFT JOIN gl_tax_codes tc ON jl.tax_code_id = tc.tax_code_id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND jl.account_code = ?
             GROUP BY COALESCE(tc.code, 'STD'), COALESCE(tc.type, 'output')"
        );
        $stmt->execute([$companyId, $startDate, $endDate, $vatOutputCode]);
        $outputRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate output VAT: standard vs zero vs exempt
        $outputStandardVat = 0.0;
        $totalOutputVat = 0.0;
        foreach ($outputRows as $row) {
            $amt = (float)$row['vat_amount'];
            $totalOutputVat += $amt;
            // NULL tax_code or STD/STANDARD => standard rated
            $code = strtoupper($row['tax_code']);
            if ($code === 'ZERO' || $code === 'EXEMPT') {
                // Zero-rated and exempt should have 0 VAT by definition,
                // but include any amounts that slip through
                continue;
            }
            $outputStandardVat += $amt;
        }

        // --- Output tax BASE amounts from revenue lines ---
        // Use gl_accounts.account_type to identify revenue accounts, not a
        // hardcoded '4%' code prefix (not all charts use 4xxx for revenue).
        $stmt = $db->prepare(
            "SELECT
                tc.code AS tax_code,
                SUM(jl.credit - jl.debit) AS base_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
             LEFT JOIN gl_tax_codes tc ON jl.tax_code_id = tc.tax_code_id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND ga.account_type = 'revenue'
             GROUP BY tc.code"
        );
        $stmt->execute([$companyId, $startDate, $endDate]);
        $baseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $outputStandardBase = 0.0;
        $outputZeroBase = 0.0;
        $outputExemptBase = 0.0;
        foreach ($baseRows as $row) {
            $amt = (float)$row['base_amount'];
            $code = strtoupper((string)($row['tax_code'] ?? ''));
            if ($code === 'ZERO') {
                $outputZeroBase += $amt;
            } elseif ($code === 'STD' || $code === 'STANDARD') {
                // Only revenue explicitly tagged standard-rated belongs in
                // VAT201 field 1 (standard rated supplies)
                $outputStandardBase += $amt;
            } else {
                // EXEMPT, untagged and other non-VAT revenue (interest,
                // dividends, sundry income) belongs in the exempt /
                // non-supplies bucket, not the standard-rated base
                $outputExemptBase += $amt;
            }
        }

        // If no revenue lines were tagged standard-rated but we do have
        // standard output VAT, reverse-engineer the standard base from VAT
        // at 15% and move it out of the exempt/non-supplies bucket so the
        // same revenue is not counted twice.
        if ($outputStandardBase == 0.0 && $outputStandardVat > 0.0) {
            $outputStandardBase = $outputStandardVat / 0.15;
            $outputExemptBase = max(0.0, $outputExemptBase - $outputStandardBase);
        }

        // --- Input VAT: split capital goods vs other ---
        // Prefer the explicit journal_lines.is_capital_goods flag (added by the
        // capital-goods-tracking migration). Only when the company has never
        // used the flag do we fall back to the legacy heuristic: a sibling
        // line on the same journal hits a fixed asset account
        // (account_subtype='fixed_asset').
        $stmt = $db->prepare(
            "SELECT EXISTS (
                SELECT 1 FROM journal_lines jl
                JOIN journal_entries je ON jl.journal_id = je.id
                WHERE je.company_id = ? AND jl.is_capital_goods = 1
             )"
        );
        $stmt->execute([$companyId]);
        $usesCapitalFlag = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT
                CASE
                    WHEN jl.is_capital_goods = 1 THEN 'capital'
                    WHEN ? = 0 AND EXISTS (
                        SELECT 1 FROM journal_lines sibling
                        JOIN gl_accounts ga ON ga.account_code = sibling.account_code
                            AND ga.company_id = je.company_id
                        WHERE sibling.journal_id = jl.journal_id
                          AND sibling.id != jl.id
                          AND ga.account_subtype = 'fixed_asset'
                          AND (sibling.debit > 0 OR sibling.credit > 0)
                    ) THEN 'capital'
                    ELSE 'other'
                END AS input_category,
                SUM(jl.debit - jl.credit) AS vat_amount
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND jl.account_code = ?
             GROUP BY input_category"
        );
        $stmt->execute([$usesCapitalFlag, $companyId, $startDate, $endDate, $vatInputCode]);
        $inputRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $inputCapital = 0.0;
        $inputOther = 0.0;
        foreach ($inputRows as $row) {
            $amt = (float)$row['vat_amount'];
            if ($row['input_category'] === 'capital') {
                $inputCapital += $amt;
            } else {
                $inputOther += $amt;
            }
        }
        $totalInputVat = $inputCapital + $inputOther;

        // Convert to cents
        $outputStandardBaseCents = (int)round($outputStandardBase * 100);
        $outputStandardVatCents  = (int)round($outputStandardVat * 100);
        $outputZeroBaseCents     = (int)round($outputZeroBase * 100);
        $outputExemptBaseCents   = (int)round($outputExemptBase * 100);
        $totalOutputVatCents     = (int)round($totalOutputVat * 100);
        $inputCapitalCents       = (int)round($inputCapital * 100);
        $inputOtherCents         = (int)round($inputOther * 100);
        $totalInputVatCents      = (int)round($totalInputVat * 100);
        $netVatCents             = $totalOutputVatCents - $totalInputVatCents;

        return [
            'output_standard_base_cents' => $outputStandardBaseCents,
            'output_standard_vat_cents'  => $outputStandardVatCents,
            'output_zero_base_cents'     => $outputZeroBaseCents,
            'output_exempt_base_cents'   => $outputExemptBaseCents,
            'total_output_vat_cents'     => $totalOutputVatCents,
            'input_capital_cents'        => $inputCapitalCents,
            'input_other_cents'          => $inputOtherCents,
            'total_input_vat_cents'      => $totalInputVatCents,
            'net_vat_cents'              => $netVatCents,
        ];
    }
}
