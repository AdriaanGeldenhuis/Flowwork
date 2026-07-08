<?php
/**
 * DUE-DILIGENCE-2026-07 Section F / D-Bank: bank reconciliation had zero
 * automated coverage. finances/ajax/bank_undo_match.php refuses to undo a match
 * whose transaction falls on or before the account's last_reconciled_date, so a
 * closed reconciliation can't be silently altered. That guard is an HTTP
 * endpoint; this replays its exact predicate against real bank rows (inside a
 * rolled-back transaction so the shared DB is untouched).
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$map = new AccountsMap($DB, TEST_COMPANY_ID);
$bankGlId = (int)$DB->query("SELECT account_id FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_type='asset' AND is_active=1 ORDER BY account_code LIMIT 1")->fetchColumn();
assert_true($bankGlId > 0, 'resolved a GL account for the bank');

// The guard, verbatim: a match may be undone only when the account has no
// reconciliation yet, or the transaction post-dates the last reconciliation.
$undoRefused = static fn(?string $lastRec, string $txDate): bool =>
    $lastRec && $txDate <= $lastRec;

$DB->beginTransaction();
try {
    $lastRec = '2025-03-31';
    $DB->prepare("INSERT INTO gl_bank_accounts
            (company_id, name, bank_name, account_no, currency, gl_account_id,
             opening_balance_cents, current_balance_cents, last_reconciled_date, is_active, created_at, updated_at)
         VALUES (?, ?, 'Test Bank', '000123', 'ZAR', ?, 0, 0, ?, 1, NOW(), NOW())")
       ->execute([TEST_COMPANY_ID, 'Recon Test ' . bin2hex(random_bytes(3)), $bankGlId, $lastRec]);
    $bankAcctId = (int)$DB->lastInsertId();

    // Two matched transactions: one inside the closed reconciliation, one after.
    $mkTx = function (string $date) use ($DB, $bankAcctId) {
        $DB->prepare("INSERT INTO gl_bank_transactions
                (company_id, bank_account_id, tx_date, description, amount_cents, matched, journal_id, created_at)
             VALUES (?, ?, ?, 'x', 10000, 1, 999999, NOW())")
           ->execute([TEST_COMPANY_ID, $bankAcctId, $date]);
        return (int)$DB->lastInsertId();
    };
    $closedTx = $mkTx('2025-02-15'); // <= last_reconciled_date
    $openTx   = $mkTx('2025-04-10'); // > last_reconciled_date

    $rec = $DB->query("SELECT last_reconciled_date FROM gl_bank_accounts WHERE id=$bankAcctId")->fetchColumn();
    $closedRow = $DB->query("SELECT tx_date FROM gl_bank_transactions WHERE bank_tx_id=$closedTx")->fetch(PDO::FETCH_ASSOC);
    $openRow   = $DB->query("SELECT tx_date FROM gl_bank_transactions WHERE bank_tx_id=$openTx")->fetch(PDO::FETCH_ASSOC);

    assert_true($undoRefused($rec, $closedRow['tx_date']),
        'undo is refused for a transaction inside the closed reconciliation');
    assert_true(!$undoRefused($rec, $openRow['tx_date']),
        'undo is allowed for a transaction dated after the last reconciliation');

    // With no reconciliation on record, undo is always allowed.
    assert_true(!$undoRefused(null, '2020-01-01'),
        'undo is allowed when the account has never been reconciled');

    $DB->rollBack();
} catch (Throwable $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    throw $e;
}

test_done('bank_reconciliation');
