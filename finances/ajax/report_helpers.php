<?php
// /finances/ajax/report_helpers.php
// Shared helper functions for financial report endpoints.

/**
 * Fetch report metadata including company details and prepared-by info.
 * Returns an array suitable for inclusion in every report JSON response.
 */
function getReportMeta(PDO $DB, int $companyId, int $userId): array
{
    $stmt = $DB->prepare(
        "SELECT name, reg_number, vat_number, tax_number FROM companies WHERE id = ?"
    );
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $DB->prepare(
        "SELECT first_name, last_name FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $preparedBy = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

    return [
        'company_name' => $company['name'] ?? '',
        'reg_number'   => $company['reg_number'] ?? '',
        'vat_number'   => $company['vat_number'] ?? '',
        'tax_number'   => $company['tax_number'] ?? '',
        'prepared_by'  => $preparedBy ?: 'System',
        'generated_at' => date('c'), // ISO 8601
    ];
}

/**
 * Resolve the fiscal year start date from company settings.
 * Falls back to January 1 of the current year if not set.
 * The setting value is expected as a month number (1-12) or MM-DD string.
 */
function getFiscalYearStart(PDO $DB, int $companyId, string $endDate): string
{
    $stmt = $DB->prepare(
        "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'finance_fiscal_year_start' LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $value = $stmt->fetchColumn();

    // Default: Jan 1 of the year of the end date
    $endYear = (int)substr($endDate, 0, 4);

    if (!$value) {
        return $endYear . '-01-01';
    }

    // Support MM-DD format (e.g. "03-01" for March 1)
    if (preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($value), $m)) {
        $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $day   = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        // If fiscal year start month is after the end date month, use previous year
        $fiscalStart = $endYear . '-' . $month . '-' . $day;
        if ($fiscalStart > $endDate) {
            $fiscalStart = ($endYear - 1) . '-' . $month . '-' . $day;
        }
        return $fiscalStart;
    }

    // Support month number only (e.g. "3" for March)
    if (is_numeric(trim($value))) {
        $month = str_pad((int)$value, 2, '0', STR_PAD_LEFT);
        $fiscalStart = $endYear . '-' . $month . '-01';
        if ($fiscalStart > $endDate) {
            $fiscalStart = ($endYear - 1) . '-' . $month . '-01';
        }
        return $fiscalStart;
    }

    return $endYear . '-01-01';
}
