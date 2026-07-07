<?php
/**
 * finances/tools/finance_setup.php
 *
 * Idempotent per-company finance configuration. Run once after migrations
 * (and any time a company is added):
 *
 *   php finances/tools/finance_setup.php            # all companies
 *   php finances/tools/finance_setup.php --company=3
 *
 * What it does, per company:
 *  1. Ensures a dedicated net "VAT Control" account exists (legacy charts often
 *     have VAT Output/Input but no net control account, and code 2100 may be
 *     occupied by a legacy header account — the tool picks the next free code
 *     in the 21xx band when needed).
 *  2. Seeds any missing finance_* company_settings mappings by resolving the
 *     company's own chart by account subtype first (charts predating the SARS
 *     seed use different codes for the same role), then by the canonical seed
 *     code in AccountsMap::DEFAULTS.
 *
 * Explicit existing settings are never overwritten.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/lib/AccountsMap.php';

$options = getopt('', ['company::']);
$onlyCompany = isset($options['company']) ? (int)$options['company'] : null;

$companies = $DB->query('SELECT id, name FROM companies ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

foreach ($companies as $company) {
    $cid = (int)$company['id'];
    if ($onlyCompany !== null && $cid !== $onlyCompany) {
        continue;
    }
    echo "== Company {$cid} ({$company['name']}) ==\n";
    ensure_vat_control_account($DB, $cid);
    ensure_bad_debt_account($DB, $cid);
    seed_finance_settings($DB, $cid);
}
echo "Done.\n";

// ---------------------------------------------------------------------------

function ensure_vat_control_account(PDO $db, int $cid): void
{
    // A net VAT control is a vat_control-subtype account that is neither the
    // output nor the input leg.
    $stmt = $db->prepare(
        "SELECT account_id, account_code FROM gl_accounts
          WHERE company_id = ? AND is_active = 1 AND account_subtype = 'vat_control'
            AND account_name NOT LIKE '%output%' AND account_name NOT LIKE '%input%'
          ORDER BY (account_name LIKE '%control%') DESC, account_code
          LIMIT 1"
    );
    $stmt->execute([$cid]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   VAT Control present: {$row['account_code']}\n";
        return;
    }

    $code = next_free_code($db, $cid, '2100', 2100, 2199);
    $parent = account_id_by_code($db, $cid, '2000');
    $db->prepare(
        "INSERT INTO gl_accounts
            (company_id, account_code, account_name, description, account_type,
             normal_balance, account_subtype, parent_id, is_system, is_control,
             is_locked, allow_manual_journal, is_active, currency, afs_line_code)
         VALUES (?, ?, 'VAT Control', 'Net VAT payable to SARS', 'liability',
                 'credit', 'vat_control', ?, 1, 1, 1, 0, 1, 'ZAR', 'BS-CL-Tax')"
    )->execute([$cid, $code, $parent]);
    echo "   VAT Control created at code $code\n";
}

function ensure_bad_debt_account(PDO $db, int $cid): void
{
    // Write-offs post here (bad debt expense + s22 VAT relief). Resolve by
    // NAME — legacy charts reuse code 6800 for other things.
    $stmt = $db->prepare(
        "SELECT account_id, account_code FROM gl_accounts
          WHERE company_id = ? AND is_active = 1
            AND account_type = 'expense' AND account_name LIKE '%bad debt%'
          ORDER BY account_code LIMIT 1"
    );
    $stmt->execute([$cid]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   Bad Debts account present: {$row['account_code']}\n";
        return;
    }
    $code = next_free_code($db, $cid, '6800', 6800, 6899);
    $parent = account_id_by_code($db, $cid, '6000');
    $db->prepare(
        "INSERT INTO gl_accounts
            (company_id, account_code, account_name, description, account_type,
             normal_balance, account_subtype, parent_id, is_system, is_control,
             is_locked, allow_manual_journal, is_active, currency, afs_line_code)
         VALUES (?, ?, 'Bad Debts Written Off', 'Irrecoverable debts (s22 VAT relief)',
                 'expense', 'debit', 'operating_expense', ?, 1, 0, 0, 1, 1, 'ZAR', 'IS-OpEx')"
    )->execute([$cid, $code, $parent]);
    echo "   Bad Debts account created at code $code\n";
}

function seed_finance_settings(PDO $db, int $cid): void
{
    foreach (AccountsMap::DEFAULTS as $key => $defaultCode) {
        if (setting_exists($db, $cid, $key)) {
            continue;
        }
        $accountId = null;

        if ($key === 'finance_vat_output_account_id') {
            $accountId = find_account($db, $cid,
                "account_subtype = 'vat_control' AND account_name LIKE '%output%'");
        } elseif ($key === 'finance_vat_input_account_id') {
            $accountId = find_account($db, $cid,
                "account_subtype = 'vat_control' AND account_name LIKE '%input%'");
        } elseif ($key === 'finance_vat_control_account_id') {
            $accountId = find_account($db, $cid,
                "account_subtype = 'vat_control'
                 AND account_name NOT LIKE '%output%' AND account_name NOT LIKE '%input%'");
        } elseif ($key === 'finance_bad_debt_account_id') {
            // Resolve by NAME: legacy charts reuse code 6800 for other things
            // (company 1 has 6800 = Depreciation).
            $accountId = find_account($db, $cid,
                "account_type = 'expense' AND account_name LIKE '%bad debt%'");
        } elseif (isset(AccountsMap::SUBTYPES[$key])) {
            $subtypes = "'" . implode("','", AccountsMap::SUBTYPES[$key]) . "'";
            $accountId = find_account($db, $cid, "account_subtype IN ($subtypes)");
        }

        // Fall back to the canonical seed code — but only when the account at
        // that code actually plays the expected role on this chart (guards
        // against legacy charts where e.g. 1100 is a "Current Assets" header
        // or 6800 is Depreciation instead of Bad Debts).
        if (!$accountId && $key !== 'finance_bad_debt_account_id') {
            $accountId = account_id_by_code($db, $cid, $defaultCode);
            if ($accountId && isset(AccountsMap::SUBTYPES[$key])) {
                $stmt = $db->prepare(
                    "SELECT account_subtype FROM gl_accounts WHERE account_id = ?");
                $stmt->execute([$accountId]);
                if (!in_array($stmt->fetchColumn(), AccountsMap::SUBTYPES[$key], true)) {
                    $accountId = null;
                }
            }
        }

        if ($accountId) {
            $db->prepare(
                "INSERT INTO company_settings (company_id, setting_key, setting_value)
                 VALUES (?, ?, ?)"
            )->execute([$cid, $key, (string)$accountId]);
            echo "   $key => account_id $accountId\n";
        } else {
            echo "   $key UNRESOLVED — configure in Finance Settings\n";
        }
    }
}

/** Prefer control accounts, then accounts that already carry postings, then lowest code. */
function find_account(PDO $db, int $cid, string $where): ?int
{
    $sql = "SELECT a.account_id
              FROM gl_accounts a
             WHERE a.company_id = ? AND a.is_active = 1 AND ($where)
             ORDER BY a.is_control DESC,
                      EXISTS (SELECT 1 FROM journal_lines jl
                               JOIN journal_entries je ON je.id = jl.journal_id
                              WHERE jl.account_code = a.account_code
                                AND je.company_id = a.company_id) DESC,
                      a.account_code
             LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$cid]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function account_id_by_code(PDO $db, int $cid, string $code): ?int
{
    $stmt = $db->prepare(
        "SELECT account_id FROM gl_accounts WHERE company_id = ? AND account_code = ? LIMIT 1");
    $stmt->execute([$cid, $code]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function setting_exists(PDO $db, int $cid, string $key): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM company_settings WHERE company_id = ? AND setting_key = ? LIMIT 1");
    $stmt->execute([$cid, $key]);
    return (bool)$stmt->fetchColumn();
}

function next_free_code(PDO $db, int $cid, string $preferred, int $from, int $to): string
{
    if (!account_id_by_code($db, $cid, $preferred)) {
        return $preferred;
    }
    for ($c = $from; $c <= $to; $c++) {
        if (!account_id_by_code($db, $cid, (string)$c)) {
            return (string)$c;
        }
    }
    throw new RuntimeException("No free account code in band $from-$to for company $cid");
}
