<?php
// finances/lib/AccountsMap.php
//
// This class centralises the logic for resolving general ledger account codes from
// company settings. Many modules within the finance area need to look up the
// appropriate GL accounts (for AR, AP, VAT, Sales, COGS, etc.) based on the
// company's configuration. If a setting refers to an account id the code will
// fetch the corresponding account_code from gl_accounts. If the setting is
// blank or invalid a sensible default can be supplied.

class AccountsMap
{
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
}
