<?php
// /finances/ajax/report_helpers.php
// Shared helper functions for financial report endpoints.

/**
 * Fetch report metadata including company details and prepared-by info.
 * Defensive: works even if reg_number/vat_number/tax_number columns don't exist yet.
 */
function getReportMeta(PDO $DB, int $companyId, int $userId): array
{
    $company = ['name' => '', 'reg_number' => '', 'vat_number' => '', 'tax_number' => ''];
    try {
        $stmt = $DB->prepare("SELECT name, reg_number, vat_number, tax_number FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $company = [
                'name'       => $row['name'] ?? '',
                'reg_number' => $row['reg_number'] ?? '',
                'vat_number' => $row['vat_number'] ?? '',
                'tax_number' => $row['tax_number'] ?? '',
            ];
        }
    } catch (\Exception $e) {
        // Columns may not exist yet — fall back to name only
        try {
            $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $company['name'] = $row['name'] ?? '';
        } catch (\Exception $e2) {
            // ignore
        }
    }

    $preparedBy = 'System';
    try {
        $stmt = $DB->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($name) $preparedBy = $name;
        }
    } catch (\Exception $e) {
        // ignore
    }

    return [
        'company_name' => $company['name'],
        'reg_number'   => $company['reg_number'],
        'vat_number'   => $company['vat_number'],
        'tax_number'   => $company['tax_number'],
        'prepared_by'  => $preparedBy,
        'generated_at' => date('c'),
    ];
}

/**
 * Check whether a column exists on a table. Cached per request.
 */
function columnExists(PDO $DB, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $DB->query("SELECT `$column` FROM `$table` LIMIT 0");
        $cache[$key] = true;
    } catch (\Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Resolve the fiscal year start date from company settings.
 * Falls back to January 1 of the current year if not set.
 */
function getFiscalYearStart(PDO $DB, int $companyId, string $endDate): string
{
    $endYear = (int)substr($endDate, 0, 4);

    try {
        $stmt = $DB->prepare(
            "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'finance_fiscal_year_start' LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $value = $stmt->fetchColumn();
    } catch (\Exception $e) {
        return $endYear . '-01-01';
    }

    if (!$value) {
        return $endYear . '-01-01';
    }

    // Support MM-DD format (e.g. "03-01" for March 1)
    if (preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($value), $m)) {
        $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $day   = str_pad($m[2], 2, '0', STR_PAD_LEFT);
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
