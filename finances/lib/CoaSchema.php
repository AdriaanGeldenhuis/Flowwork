<?php
// /finances/lib/CoaSchema.php
//
// Single source of truth for the SARS-aligned Chart of Accounts schema.
// Mirrors the values used by the seed_sars_chart_of_accounts() stored proc
// and by /finances/assets/chart.js. Keep these three in sync.

class CoaSchema {

    /** account_type => list of allowed account_subtype values */
    public static function subtypesByType(): array {
        return [
            'asset' => [
                'bank', 'cash', 'accounts_receivable', 'inventory', 'prepayment',
                'current_asset', 'fixed_asset', 'accumulated_depreciation',
                'intangible_asset', 'investment', 'non_current_asset',
            ],
            'liability' => [
                'accounts_payable', 'vat_control', 'paye_liability', 'uif_liability',
                'sdl_liability', 'income_tax_payable', 'provisional_tax',
                'dividends_tax_payable', 'current_liability',
                'loan', 'non_current_liability',
            ],
            'equity' => [
                'share_capital', 'retained_earnings', 'current_year_earnings', 'drawings',
            ],
            'revenue' => [
                'operating_revenue', 'other_income', 'interest_income',
                'dividend_income', 'forex_gain',
            ],
            'expense' => [
                'cost_of_sales', 'operating_expense', 'payroll_expense',
                'depreciation', 'finance_cost', 'forex_loss', 'tax_expense',
            ],
        ];
    }

    /** Default normal balance for an account_type. */
    public static function defaultNormalBalance(string $type): string {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }

    /**
     * Numeric account-code bands per type. An account_code's leading digits
     * must fall within one of these ranges for the given type.
     * Format: [type => [[min, max], ...]]
     */
    public static function codeBands(): array {
        return [
            'asset'     => [[1000, 1999]],
            'liability' => [[2000, 2999]],
            'equity'    => [[3000, 3999]],
            'revenue'   => [[4000, 4999]],
            // Expenses span COGS (5xxx), operating (6xxx-7xxx — the COA migration
            // backfills 7000-7999 as operating_expense), finance costs (8xxx)
            // and tax expense (9xxx) — all the debit-normal P&L bands.
            'expense'   => [[5000, 9999]],
        ];
    }

    /** Validate a subtype against a type. Empty subtype is allowed (legacy rows). */
    public static function isValidSubtype(string $type, string $subtype): bool {
        if ($subtype === '') return true;
        $map = self::subtypesByType();
        return isset($map[$type]) && in_array($subtype, $map[$type], true);
    }

    /** Validate that account_code falls within the type's numeric band(s). */
    public static function isCodeInBand(string $type, string $code): bool {
        $bands = self::codeBands();
        if (!isset($bands[$type])) return false;
        // Use the leading digits only — allows suffixes like "1010-A".
        if (!preg_match('/^(\d{3,5})/', $code, $m)) return false;
        $n = (int)$m[1];
        foreach ($bands[$type] as [$min, $max]) {
            if ($n >= $min && $n <= $max) return true;
        }
        return false;
    }

    /**
     * Validate normal_balance against type. Contra accounts (e.g. accumulated
     * depreciation, sales returns) are allowed to invert.
     */
    public static function isValidNormalBalance(string $type, string $normalBalance, bool $isContra = false): bool {
        if (!in_array($normalBalance, ['debit', 'credit'], true)) return false;
        if ($isContra) return true;
        return $normalBalance === self::defaultNormalBalance($type);
    }

    /** Maximum allowed depth of the parent_id hierarchy. */
    public const MAX_DEPTH = 3;

    /**
     * Walk parent_id chain to ensure: same type, no cycle, depth <= MAX_DEPTH.
     * Returns null on success, error string on failure.
     *
     * @param PDO $DB
     * @param int $companyId
     * @param int $parentId         Proposed parent_id
     * @param string $childType     Proposed child's account_type
     * @param int|null $selfId      The account being edited (to detect cycle), or null on insert
     */
    public static function validateParent(PDO $DB, int $companyId, int $parentId, string $childType, ?int $selfId): ?string {
        $depth = 1;
        $cursor = $parentId;
        $seen = [];
        while ($cursor) {
            if ($selfId !== null && (int)$cursor === $selfId) {
                return 'Parent assignment would create a cycle';
            }
            if (isset($seen[$cursor])) {
                return 'Parent chain contains a cycle';
            }
            $seen[$cursor] = true;
            $stmt = $DB->prepare("SELECT account_id, parent_id, account_type FROM gl_accounts WHERE account_id = ? AND company_id = ?");
            $stmt->execute([$cursor, $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 'Parent account not found';
            if ($row['account_type'] !== $childType) {
                return 'Parent account type does not match (' . $row['account_type'] . ' vs ' . $childType . ')';
            }
            $cursor = $row['parent_id'] ? (int)$row['parent_id'] : null;
            $depth++;
            if ($depth > self::MAX_DEPTH) {
                return 'Account hierarchy may not exceed ' . self::MAX_DEPTH . ' levels';
            }
        }
        return null;
    }

    /**
     * Number of posted journal lines that reference a given account_code
     * within a company. Used to gate destructive edits.
     */
    public static function postedLineCount(PDO $DB, int $companyId, string $accountCode): int {
        $stmt = $DB->prepare("
            SELECT COUNT(*)
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_id
            WHERE je.company_id = ?
              AND je.status = 'posted'
              AND jl.account_code = ?
        ");
        $stmt->execute([$companyId, $accountCode]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Number of journal lines (any status) that reference a given account_code.
     * Used to prevent deletion when draft/prepared entries still exist.
     */
    public static function allLineCount(PDO $DB, int $companyId, string $accountCode): int {
        $stmt = $DB->prepare("
            SELECT COUNT(*)
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_id
            WHERE je.company_id = ?
              AND jl.account_code = ?
        ");
        $stmt->execute([$companyId, $accountCode]);
        return (int)$stmt->fetchColumn();
    }

    /** YTD net balance for an account, signed by normal_balance. */
    public static function ytdBalance(PDO $DB, int $companyId, string $accountCode, string $normalBalance, string $fyStartDate): float {
        $stmt = $DB->prepare("
            SELECT COALESCE(SUM(jl.debit), 0)  AS d,
                   COALESCE(SUM(jl.credit), 0) AS c
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_id
            WHERE je.company_id = ?
              AND je.status = 'posted'
              AND je.entry_date >= ?
              AND jl.account_code = ?
        ");
        $stmt->execute([$companyId, $fyStartDate, $accountCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['d' => 0, 'c' => 0];
        $d = (float)$row['d'];
        $c = (float)$row['c'];
        return $normalBalance === 'debit' ? ($d - $c) : ($c - $d);
    }

    /**
     * Resolve the company's fiscal year start date as YYYY-MM-DD.
     * company_settings.finance_fiscal_year_start stores a month name (e.g. "February").
     * If today is before that month in the current year, FY start is in the prior year.
     */
    /**
     * Parse a stored finance_fiscal_year_start value into a month number (1-12).
     * Accepts a month NAME ("March"), a month number ("3" / "03"), or an "MM-DD"
     * string (the day is ignored here). Defaults to 3 (March — the SARS default
     * tax-year start) for empty or unrecognised values.
     *
     * This is the single tolerant authority: the Finance Settings UI stores this
     * key as a month name while admin/finance.php stores it as a number, so every
     * reader must accept both or it computes the wrong fiscal-year boundary.
     */
    public static function parseFiscalMonth(?string $value): int {
        $value = trim((string)$value);
        if ($value === '') return 3;
        // Month number, optionally with a "-DD" day suffix (e.g. "03" or "03-01")
        if (preg_match('/^(\d{1,2})(?:-\d{1,2})?$/', $value, $m)) {
            $n = (int)$m[1];
            return ($n >= 1 && $n <= 12) ? $n : 3;
        }
        // Month name (e.g. "March")
        $ts = strtotime($value . ' 1');
        if ($ts !== false) {
            $n = (int)date('n', $ts);
            if ($n >= 1 && $n <= 12) return $n;
        }
        return 3;
    }

    public static function fiscalYearStartDate(PDO $DB, int $companyId, ?string $today = null): string {
        $today = $today ?? date('Y-m-d');
        $stmt = $DB->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'finance_fiscal_year_start' LIMIT 1");
        $stmt->execute([$companyId]);
        $monthNum = self::parseFiscalMonth($stmt->fetchColumn() ?: '');

        $todayTs = strtotime($today);
        $year = (int)date('Y', $todayTs);
        $thisYearStart = mktime(0, 0, 0, $monthNum, 1, $year);
        if ($todayTs < $thisYearStart) {
            $year--;
        }
        return sprintf('%04d-%02d-01', $year, $monthNum);
    }

    /** Lookup tables exposed for API/UI use. */
    public static function asJson(): array {
        return [
            'subtypes_by_type' => self::subtypesByType(),
            'code_bands' => self::codeBands(),
            'max_depth' => self::MAX_DEPTH,
        ];
    }
}
