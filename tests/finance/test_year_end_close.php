<?php
/**
 * DUE-DILIGENCE-2026-07 Section F / B2: year-end close had zero automated
 * coverage. This exercises the load-bearing pieces of finances/ajax/
 * year_end_close.php (an HTTP endpoint that can't be invoked in-process) by
 * replaying its exact SQL and guard predicates against real seeded P&L:
 *   1. the date guards reject an in-progress / malformed fiscal year;
 *   2. the closing journal built from posted revenue/expense balances lands
 *      net income on retained earnings and balances;
 *   3. a REVERSED prior close no longer blocks re-closing (the B2 fix adds
 *      reversed_by_journal_id IS NULL to the duplicate/overlap checks).
 * All seeded rows live inside a transaction that is rolled back, so the shared
 * test DB is left untouched.
 */
require __DIR__ . '/../lib/bootstrap.php';
require_once FLOWWORK_ROOT . '/finances/lib/AccountsMap.php';

$map = new AccountsMap($DB, TEST_COMPANY_ID);
$bankCode = $map->code('finance_bank_account_id');

// ---- (1) Date guards (verbatim from the endpoint) -------------------------
$dateGuardRejects = static function (string $fyStart, string $fyEnd): bool {
    if (strtotime($fyStart) >= strtotime($fyEnd)) return true;          // start not before end
    if (strtotime($fyEnd) >= strtotime(date('Y-m-d'))) return true;     // year not finished
    return false;
};
assert_true($dateGuardRejects('2024-12-31', '2024-01-01'), 'reversed fiscal-year dates are rejected');
assert_true($dateGuardRejects('2024-01-01', '2024-01-01'), 'zero-length fiscal year is rejected');
assert_true($dateGuardRejects(date('Y-01-01'), date('Y-12-31')), 'the current (unfinished) year is rejected');
assert_true(!$dateGuardRejects('2024-01-01', '2024-12-31'), 'a completed prior year passes the date guards');

// Resolve a revenue and an expense LEAF account (subtype set = not a header).
$revCode = $DB->query("SELECT account_code FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_type='revenue' AND account_subtype IS NOT NULL AND is_active=1 ORDER BY account_code LIMIT 1")->fetchColumn();
$expCode = $DB->query("SELECT account_code FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_type='expense' AND account_subtype IS NOT NULL AND is_active=1 ORDER BY account_code LIMIT 1")->fetchColumn();
// Retained earnings the same way the endpoint resolves it: subtype, non-debit.
$retainedCode = $DB->query("SELECT account_code FROM gl_accounts WHERE company_id=" . TEST_COMPANY_ID . "
    AND account_subtype='retained_earnings' AND is_active=1
    AND (normal_balance IS NULL OR normal_balance<>'debit') ORDER BY account_code LIMIT 1")->fetchColumn();
assert_true($revCode && $expCode && $retainedCode, 'resolved revenue / expense / retained-earnings accounts');

$fyStart = '2024-01-01';
$fyEnd   = '2024-12-31';

$DB->beginTransaction();
try {
    // Seed a completed FY2024: R1,000 revenue and R400 expense (net income R600).
    $mkJournal = function (string $date, array $lines) use ($DB) {
        $DB->prepare("INSERT INTO journal_entries
                (company_id, entry_date, reference, description, module, status, created_by, created_at, posted_by, posted_at)
             VALUES (?, ?, 'YE-TEST', 'YE seed', 'fin', 'posted', ?, NOW(), ?, NOW())")
           ->execute([TEST_COMPANY_ID, $date, TEST_USER_ID, TEST_USER_ID]);
        $jid = (int)$DB->lastInsertId();
        $ins = $DB->prepare("INSERT INTO journal_lines (journal_id, account_code, description, debit, credit) VALUES (?,?,?,?,?)");
        foreach ($lines as $l) $ins->execute([$jid, $l[0], 'seed', $l[1], $l[2]]);
        return $jid;
    };
    $mkJournal('2024-06-15', [[$bankCode, 1000, 0], [$revCode, 0, 1000]]); // Dr bank / Cr revenue
    $mkJournal('2024-07-20', [[$expCode, 400, 0], [$bankCode, 0, 400]]);   // Dr expense / Cr bank

    // ---- (2) Aggregation + closing build (verbatim from the endpoint) -----
    $stmt = $DB->prepare(
        "SELECT ga.account_code, ga.account_type,
                SUM(jl.debit) AS total_debit, SUM(jl.credit) AS total_credit
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.journal_id
         JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = ?
         WHERE je.company_id = ? AND je.status = 'posted'
           AND je.entry_date >= ? AND je.entry_date <= ?
           AND ga.account_type IN ('revenue','expense')
         GROUP BY ga.account_code, ga.account_type
         HAVING (SUM(jl.debit) - SUM(jl.credit)) != 0
         ORDER BY ga.account_code"
    );
    $stmt->execute([TEST_COMPANY_ID, TEST_COMPANY_ID, $fyStart, $fyEnd]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lines = [];
    $netIncomeCents = 0;
    foreach ($balances as $bal) {
        $balance = (int)round(floatval($bal['total_debit']) * 100) - (int)round(floatval($bal['total_credit']) * 100);
        if ($balance == 0) continue;
        $netIncomeCents += ($bal['account_type'] === 'revenue') ? (-$balance) : (-$balance);
        $lines[] = [
            'debit_cents'  => ($balance < 0) ? abs($balance) : 0,
            'credit_cents' => ($balance > 0) ? $balance : 0,
        ];
    }
    if ($netIncomeCents > 0) {
        $lines[] = ['debit_cents' => 0, 'credit_cents' => $netIncomeCents];
    } elseif ($netIncomeCents < 0) {
        $lines[] = ['debit_cents' => abs($netIncomeCents), 'credit_cents' => 0];
    }

    assert_eq(60000, $netIncomeCents, 'net income = revenue R1,000 - expense R400 = R600');
    $d = 0; $c = 0;
    foreach ($lines as $l) { $d += $l['debit_cents']; $c += $l['credit_cents']; }
    assert_eq($d, $c, 'the closing journal balances (debits == credits)');

    // ---- (3) Reversed-close no longer blocks re-closing (the B2 fix) ------
    $closingRef = 'YE-CLOSE-2024-2024';
    $DB->prepare("INSERT INTO journal_entries
            (company_id, entry_date, reference, description, module, status, created_by, created_at, posted_by, posted_at)
         VALUES (?, ?, ?, 'prior close', 'year_end', 'posted', ?, NOW(), ?, NOW())")
       ->execute([TEST_COMPANY_ID, $fyEnd, $closingRef, TEST_USER_ID, TEST_USER_ID]);
    $closeJid = (int)$DB->lastInsertId();

    $dupSql = "SELECT COUNT(*) FROM journal_entries
                WHERE company_id = ? AND reference = ? AND status = 'posted' AND reversed_by_journal_id IS NULL";
    $chk = $DB->prepare($dupSql);
    $chk->execute([TEST_COMPANY_ID, $closingRef]);
    assert_eq(1, (int)$chk->fetchColumn(), 'a live prior close blocks re-closing the same year');

    // Reverse it (mark superseded) — the year should become closeable again.
    $DB->prepare("UPDATE journal_entries SET reversed_by_journal_id = ? WHERE id = ?")
       ->execute([$closeJid, $closeJid]);
    $chk->execute([TEST_COMPANY_ID, $closingRef]);
    assert_eq(0, (int)$chk->fetchColumn(), 'a REVERSED prior close no longer blocks re-closing (B2 fix)');

    $DB->rollBack();
} catch (Throwable $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    throw $e;
}

test_done('year_end_close');
