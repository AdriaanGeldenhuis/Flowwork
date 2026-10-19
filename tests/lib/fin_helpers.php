<?php
/**
 * Helpers for the rebuilt Finance tests (tests/finance/test_fin_*.php):
 * provisioning, account lookups, a bank account and quick postings.
 */
require_once FLOWWORK_ROOT . '/finances/lib/Fin.php';
require_once FLOWWORK_ROOT . '/finances/lib/FinanceBootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/PostingService.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

function fin_setup(PDO $db): void
{
    FinSchema::reset();
    FinSchema::ensure($db);
    Audit::setPdo($db);
    FinanceBootstrap::ensure($db, TEST_COMPANY_ID);
    Fin::flush();
}

function fin_code(PDO $db, string $mapping): string
{
    return (new AccountsMap($db, TEST_COMPANY_ID))->code($mapping);
}

function fin_account_id(PDO $db, string $code): int
{
    $st = $db->prepare("SELECT account_id FROM gl_accounts WHERE company_id = ? AND account_code = ?");
    $st->execute([TEST_COMPANY_ID, $code]);
    return (int)$st->fetchColumn();
}

/** First active, non-control account of a type (optionally a subtype). */
function fin_pick_account(PDO $db, string $type, ?string $subtype = null): array
{
    $sql = "SELECT account_id, account_code FROM gl_accounts WHERE company_id = ? AND account_type = ? AND is_active = 1 AND is_control = 0"
         . ($subtype ? " AND account_subtype = ?" : " AND COALESCE(account_subtype,'') NOT IN ('fixed_asset','vat_control','bank','cash')") . " ORDER BY account_code LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute($subtype ? [TEST_COMPANY_ID, $type, $subtype] : [TEST_COMPANY_ID, $type]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    assert_true((bool)$r, "an active $type account" . ($subtype ? " ($subtype)" : '') . ' exists');
    return ['id' => (int)$r['account_id'], 'code' => (string)$r['account_code']];
}

/** A bank account on GL 1020 (or another free bank/cash account). */
function fin_bank(PDO $db, string $glCode = '1020', string $name = 'Test Bank'): int
{
    require_once FLOWWORK_ROOT . '/finances/lib/FinBank.php';
    return FinBank::saveAccount($db, TEST_COMPANY_ID, TEST_USER_ID, 'admin', [
        'name' => $name, 'bank_name' => 'FNB', 'account_no' => '62' . random_int(10000000, 99999999), 'gl_account_id' => fin_account_id($db, $glCode),
        'opening_balance' => '0', 'opening_date' => date('Y-m-01', strtotime('-3 months')),
    ]);
}

/** Post a balanced ad-hoc journal: $lines = [[code, debit, credit, tax_code_id?], …]. */
function fin_post(PDO $db, string $date, array $lines, string $module = 'fin', string $ref = ''): int
{
    $jl = [];
    foreach ($lines as $l) $jl[] = ['account_code' => $l[0], 'description' => 'test', 'debit' => $l[1], 'credit' => $l[2], 'tax_code_id' => $l[3] ?? null];
    return (new PostingService($db, TEST_COMPANY_ID, TEST_USER_ID))->postAdHocJournal(['entry_date' => $date, 'reference' => $ref ?: 'T-' . bin2hex(random_bytes(3)), 'description' => 'test', 'module' => $module], $jl);
}

/** Expect a callable to throw; returns the message. */
function expect_error(callable $fn, string $message, ?string $contains = null): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        assert_true(true, $message);
        if ($contains !== null) assert_true(stripos($e->getMessage(), $contains) !== false, $message . ' — says “' . $contains . '” (got: ' . $e->getMessage() . ')');
        return $e->getMessage();
    }
    fail($message . ' — expected an error, none was raised');
    return '';
}
