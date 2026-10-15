<?php
// finances/lib/AccountsMap.php
//
// This class centralises the logic for resolving general ledger account codes from
// company settings. Many modules within the finance area need to look up the
// appropriate GL accounts (for AR, AP, VAT, Sales, COGS, etc.) based on the
// company's configuration. If a setting refers to an account id the code will
// fetch the corresponding account_code from gl_accounts. If the setting is
// blank or invalid a sensible default can be supplied.

require_once __DIR__ . '/FinanceBootstrap.php';

class AccountsMap
{
    /**
     * Single authority for fallback account codes, aligned with the seeded
     * SARS chart (Migrations/2026-04-08-coa-sars-upgrade.sql). These are the
     * LAST resort — explicit company_settings mappings always win, and
     * finances/tools/finance_setup.php seeds those settings per company by
     * account subtype (legacy charts may use different codes for the same
     * role, e.g. company charts where 1200 is Accounts Receivable).
     */
    public const DEFAULTS = [
        'finance_ar_account_id'               => '1100', // Accounts Receivable Control
        'finance_ap_account_id'               => '2010', // Accounts Payable Control
        'finance_bank_account_id'             => '1020', // Primary Bank Account
        'finance_sales_account_id'            => '4010', // Sales — Standard Rated
        'finance_vat_output_account_id'       => '2110', // VAT Output
        'finance_vat_input_account_id'        => '2120', // VAT Input
        'finance_vat_control_account_id'      => '2100', // VAT Control (net, SARS)
        'finance_inventory_account_id'        => '1200', // Stock on Hand
        'finance_cogs_account_id'             => '5150', // Cost of Goods Sold
        'finance_expense_account_id'          => '5020', // Purchases
        'finance_wage_expense_account_id'     => '6010', // Salaries & Wages
        'finance_paye_account_id'             => '2130', // PAYE Payable
        'finance_uif_account_id'              => '2140', // UIF Payable
        'finance_sdl_account_id'              => '2150', // SDL Payable
        'finance_uif_expense_account_id'      => '6050', // UIF — Employer
        'finance_sdl_expense_account_id'      => '6060', // SDL — Employer
        'finance_bad_debt_account_id'         => '6800', // Bad Debts Written Off
        'finance_fx_gain_account_id'          => '4950', // Forex Gain — Realised
        'finance_fx_loss_account_id'          => '8050', // Forex Loss — Realised
        'finance_gain_on_disposal_account_id' => '4960', // Profit on Disposal
        'finance_loss_on_disposal_account_id' => '8060', // Loss on Disposal
        'finance_retained_earnings_account_id'=> '3100', // Retained Earnings
    ];

    /**
     * Account subtype(s) that identify each mapping role in a company chart,
     * used to resolve mappings on charts whose codes differ from the seed.
     * Listed in preference order; is_control accounts win within a subtype.
     */
    public const SUBTYPES = [
        'finance_ar_account_id'          => ['accounts_receivable'],
        'finance_ap_account_id'          => ['accounts_payable'],
        'finance_bank_account_id'        => ['bank'],
        'finance_inventory_account_id'   => ['inventory'],
        'finance_paye_account_id'        => ['paye_liability'],
        'finance_uif_account_id'         => ['uif_liability'],
        'finance_sdl_account_id'         => ['sdl_liability'],
        'finance_retained_earnings_account_id' => ['retained_earnings'],
    ];

    private $db;
    private $companyId;

    /**
     * Constructor
     *
     * @param PDO $db
     * @param int $companyId
     */
    public function __construct(PDO $db, int $companyId)
    {
        $this->db = $db;
        $this->companyId = $companyId;
    }

    /**
     * Resolve a mapping key to an account code using the canonical fallback
     * from self::DEFAULTS. Prefer this over get() with an ad-hoc literal so
     * every caller shares one authority.
     *
     * An explicit setting that points at a real account always wins. Without
     * one, the canonical default is used only when that account exists on
     * this company's chart and plays the role; otherwise the role is resolved
     * to the company's own account (or the seed account is created) and
     * pinned — see FinanceBootstrap. A setting whose account was deleted is
     * repaired the same way. This is what keeps legacy charts (where e.g. the
     * bank is 1110, not the seed's 1020) and new companies posting.
     */
    public function code(string $settingKey): string
    {
        if (!isset(self::DEFAULTS[$settingKey])) {
            throw new InvalidArgumentException("Unknown finance account mapping key: $settingKey");
        }
        $explicit = $this->explicitCode($settingKey);
        if ($explicit !== null) {
            return $explicit;
        }
        $brokenSetting = $this->rawSetting($settingKey) !== '';
        return FinanceBootstrap::resolveMapping($this->db, $this->companyId, $settingKey, $brokenSetting)
            ?? self::DEFAULTS[$settingKey];
    }

    /**
     * Account code an explicit company setting points at, or null when the
     * setting is blank or its account does not exist on this company's chart.
     * Numeric values are account ids, anything else an account code (as get()).
     */
    public function explicitCode(string $settingKey): ?string
    {
        $value = $this->rawSetting($settingKey);
        if ($value === '') {
            return null;
        }
        $stmt = $this->db->prepare(is_numeric($value)
            ? "SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ? LIMIT 1"
            : "SELECT account_code FROM gl_accounts WHERE account_code = ? AND company_id = ? LIMIT 1");
        $stmt->execute([is_numeric($value) ? (int)$value : $value, $this->companyId]);
        $code = $stmt->fetchColumn();
        return $code !== false && $code !== null && $code !== '' ? (string)$code : null;
    }

    /** Trimmed raw setting value ('' when unset or falsy, as get() treats it). */
    private function rawSetting(string $settingKey): string
    {
        $stmt = $this->db->prepare(
            "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1"
        );
        $stmt->execute([$this->companyId, $settingKey]);
        $value = trim((string)$stmt->fetchColumn());
        return $value === '0' ? '' : $value;
    }

    /**
     * Resolve a GL account code from a company setting. If the setting is
     * empty or references a non-existent account the provided default code
     * will be returned.
     *
     * @param string $settingKey The key in company_settings (e.g. finance_ar_account_id)
     * @param string $defaultCode The fallback account code
     * @return string
     */
    public function get(string $settingKey, string $defaultCode): string
    {
        // Look up the setting value
        $stmt = $this->db->prepare(
            "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1"
        );
        $stmt->execute([$this->companyId, $settingKey]);
        $value = $stmt->fetchColumn();
        if (!$value) {
            return $defaultCode;
        }
        // If numeric treat as account_id and fetch account_code
        if (is_numeric($value)) {
            $stmt = $this->db->prepare(
                "SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ? LIMIT 1"
            );
            $stmt->execute([(int)$value, $this->companyId]);
            $code = $stmt->fetchColumn();
            return $code ?: $defaultCode;
        }
        // Otherwise assume the setting contains the account code directly
        return trim($value);
    }

    /**
     * Resolve a GL account code from a gl_account_id. If null or invalid
     * returns null. This is useful when invoice or bill lines specify a
     * gl_account_id for income or expense.
     *
     * @param int|null $accountId
     * @return string|null
     */
    public function getById(?int $accountId): ?string
    {
        if (!$accountId) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([$accountId, $this->companyId]);
        $code = $stmt->fetchColumn();
        return $code ?: null;
    }

    /**
     * Resolve an account_id from a company setting key. Returns the integer
     * account_id or null if not found.
     *
     * @param string $settingKey
     * @return int|null
     */
    public function getAccountId(string $settingKey): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1"
        );
        $stmt->execute([$this->companyId, $settingKey]);
        $value = $stmt->fetchColumn();
        if (!$value) {
            return null;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        // If setting contains an account_code, look up the account_id
        $stmt = $this->db->prepare(
            "SELECT account_id FROM gl_accounts WHERE account_code = ? AND company_id = ? LIMIT 1"
        );
        $stmt->execute([trim($value), $this->companyId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Resolve an account_code from an account_id. Returns the account_code
     * string or null if not found.
     *
     * @param int|null $accountId
     * @return string|null
     */
    public function getAccountCodeById(?int $accountId): ?string
    {
        return $this->getById($accountId);
    }
}
