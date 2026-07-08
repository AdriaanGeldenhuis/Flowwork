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
 *
 * Delegates month resolution to CoaSchema::parseFiscalMonth so it accepts every
 * stored format (month NAME "March", month number "3"/"03", or "MM-DD") and
 * defaults to March — the SARS default tax-year start. The old version only
 * understood numbers/MM-DD and defaulted to January, so a company configured
 * through the Finance Settings UI (which stores a month name) silently got a
 * 1-January boundary here, misstating the P&L period and balance-sheet
 * current-year-earnings split for every non-January fiscal year.
 */
function getFiscalYearStart(PDO $DB, int $companyId, string $endDate): string
{
    require_once __DIR__ . '/../lib/CoaSchema.php';
    $endYear = (int)substr($endDate, 0, 4);

    try {
        $stmt = $DB->prepare(
            "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'finance_fiscal_year_start' LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $value = trim((string)$stmt->fetchColumn());
    } catch (\Exception $e) {
        $value = '';
    }

    $month = CoaSchema::parseFiscalMonth($value);
    // Preserve an explicit day when the value is in "MM-DD" form; otherwise day 1.
    $day = 1;
    if (preg_match('/^\d{1,2}-(\d{1,2})$/', $value, $md)) {
        $d = (int)$md[1];
        if ($d >= 1 && $d <= 31) { $day = $d; }
    }

    $mm = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
    $dd = str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    $fiscalStart = $endYear . '-' . $mm . '-' . $dd;
    if ($fiscalStart > $endDate) {
        $fiscalStart = ($endYear - 1) . '-' . $mm . '-' . $dd;
    }
    return $fiscalStart;
}
