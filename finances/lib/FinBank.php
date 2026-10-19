<?php
// finances/lib/FinBank.php
//
// Bank: accounts, statement lines, matching, rules, statement import and
// reconciliation.
//
// A statement line is matched in one of three ways:
//   - to a posting already in the books (a customer receipt from Quotes &
//     Invoices, a supplier payment, payroll, a manual journal …) — nothing
//     new is posted, the line just points at that journal. This is what
//     keeps money that was recorded elsewhere from being counted twice;
//   - categorised: a journal is posted against one or more accounts (split),
//     with VAT taken out of the gross on the account's tax code. The VAT leg
//     goes to VAT output for output codes and VAT input for input codes, on
//     the side the money moves (a supplier refund lowers input VAT instead of
//     raising output VAT); a purchase can be flagged as a capital good;
//   - a transfer to another of the company's bank accounts (both lines point
//     at one journal).
// Undoing a match reverses only a journal the bank created; a match to an
// existing posting is simply released. Nothing dated on or before a closed
// reconciliation, or in a locked period, can be matched, released or
// imported. Rules never post to control accounts (receivables, payables,
// VAT, bank control) — the same protection as a manual match.
//
// Reconciliation (per bank account, to a statement date):
//   statement balance = opening + imported lines up to the date (the
//   statement is complete when this equals the balance on the paper),
//   books balance     = the bank account in the general ledger,
//   difference        = statement − (books + unmatched statement lines −
//                       postings in the books not on the statement yet),
//   where postings dated before the first imported line count as the
//   opening balance (an opening balance never posted to the books shows up
//   as a difference).
// It closes only when the statement balance agrees, every line up to the
// date is matched and the difference is nil; each close is kept as history
// (bank_reconciliations) and an administrator can reopen the latest.
// Amounts on bank tables are cents.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/ReversalService.php';
require_once __DIR__ . '/PostingService.php';
require_once __DIR__ . '/AccountsMap.php';

final class FinBank
{
    // -------------------------------------------------------------- accounts

    public static function accounts(PDO $db, int $companyId, bool $withInactive = false): array
    {
        $st = $db->prepare("SELECT ba.*, ga.account_code, ga.account_name FROM gl_bank_accounts ba
                         LEFT JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                             WHERE ba.company_id = ?" . ($withInactive ? '' : ' AND ba.is_active = 1') . " ORDER BY ba.is_active DESC, ba.name");
        $st->execute([$companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $cnt = $db->prepare("SELECT SUM(matched = 0) un, COALESCE(SUM(CASE WHEN matched = 0 THEN amount_cents END), 0) un_c, COUNT(*) n, MAX(tx_date) last
                               FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ?");
        $out = [];
        foreach ($rows as $b) {
            $cnt->execute([$companyId, (int)$b['id']]);
            $c = $cnt->fetch(PDO::FETCH_ASSOC);
            $out[] = [
                'id' => (int)$b['id'], 'name' => (string)$b['name'], 'bank' => (string)($b['bank_name'] ?? ''), 'account_no' => (string)($b['account_no'] ?? ''),
                'last4' => substr(preg_replace('/\D/', '', (string)$b['account_no']), -4), 'currency' => (string)($b['currency'] ?: 'ZAR'),
                'gl_account_id' => $b['gl_account_id'] ? (int)$b['gl_account_id'] : null, 'account_code' => (string)($b['account_code'] ?? ''), 'account_name' => (string)($b['account_name'] ?? ''),
                'opening' => ((int)$b['opening_balance_cents']) / 100, 'statement' => ((int)$b['current_balance_cents']) / 100,
                'books' => $b['account_code'] ? self::booksBalance($db, $companyId, (string)$b['account_code'], date('Y-m-d')) / 100 : null,
                'reconciled' => $b['last_reconciled_date'], 'active' => (bool)$b['is_active'],
                'unmatched' => (int)($c['un'] ?? 0), 'unmatched_total' => ((int)($c['un_c'] ?? 0)) / 100, 'lines' => (int)($c['n'] ?? 0), 'last_line' => $c['last'] ?? null,
            ];
        }
        return $out;
    }

    public static function booksBalance(PDO $db, int $companyId, string $code, string $date): int
    {
        $st = $db->prepare("SELECT COALESCE(SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ? AND je.entry_date <= ?");
        $st->execute([$companyId, $code, $date]);
        return (int)$st->fetchColumn();
    }

    /** $in: id?, name, bank_name, account_no, gl_account_id, opening_balance (only before any line), active */
    public static function saveAccount(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $id = (int)($in['id'] ?? 0);
        $name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 120);
        $bank = mb_substr(trim(strip_tags((string)($in['bank_name'] ?? ''))), 0, 120);
        $no = mb_substr(preg_replace('/[^0-9A-Za-z -]/', '', (string)($in['account_no'] ?? '')), 0, 64);
        $gl = (int)($in['gl_account_id'] ?? 0);
        if ($name === '') throw new FinUserError('Give the bank account a name.');
        if (!$gl) throw new FinUserError('Choose the ledger account this bank account posts to.');
        $st = $db->prepare("SELECT account_type, account_subtype, is_active FROM gl_accounts WHERE account_id = ? AND company_id = ?");
        $st->execute([$gl, $companyId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new FinUserError('That ledger account is not on your chart.');
        if ($a['account_type'] !== 'asset' || !in_array($a['account_subtype'], ['bank', 'cash'], true)) throw new FinUserError('Choose a bank or cash account (an asset with the bank or cash subtype).');
        $st = $db->prepare("SELECT name FROM gl_bank_accounts WHERE company_id = ? AND gl_account_id = ? AND id <> ? AND is_active = 1");
        $st->execute([$companyId, $gl, $id]);
        if ($other = $st->fetchColumn()) throw new FinUserError("“{$other}” already posts to that ledger account. Each bank account needs its own.");
        $opening = Fin::cents($in['opening_balance'] ?? '0') ?? 0;
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $name, $bank, $no, $gl, $opening, $in) {
            if ($id) {
                $st = $db->prepare("SELECT * FROM gl_bank_accounts WHERE id = ? AND company_id = ? FOR UPDATE");
                $st->execute([$id, $companyId]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new FinUserError('That bank account was not found.');
                $st = $db->prepare("SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ?");
                $st->execute([$companyId, $id]);
                $hasLines = (int)$st->fetchColumn() > 0;
                if ($hasLines && $opening !== (int)$old['opening_balance_cents']) throw new FinUserError('The opening balance is fixed once statement lines are imported.');
                if ($hasLines && $gl !== (int)$old['gl_account_id']) {
                    $st = $db->prepare("SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ? AND matched = 1");
                    $st->execute([$companyId, $id]);
                    if ((int)$st->fetchColumn() > 0) throw new FinUserError('Matched lines already posted to the current ledger account, so it stays.');
                }
                $active = array_key_exists('active', $in) ? (int)!empty($in['active']) : (int)$old['is_active'];
                $db->prepare("UPDATE gl_bank_accounts SET name = ?, bank_name = ?, account_no = ?, gl_account_id = ?, opening_balance_cents = ?, is_active = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
                   ->execute([$name, $bank, $no, $gl, $opening, $active, $id, $companyId]);
                self::recomputeBalance($db, $companyId, $id);
                Fin::audit($db, $companyId, $userId, 'bank_account_updated', 'bank_account', $id, ['name' => $name, 'active' => $active]);
                return $id;
            }
            $db->prepare("INSERT INTO gl_bank_accounts (company_id, name, bank_name, account_no, gl_account_id, opening_balance_cents, current_balance_cents, currency, is_active, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'ZAR', 1, NOW())")->execute([$companyId, $name, $bank, $no, $gl, $opening, $opening]);
            $id = (int)$db->lastInsertId();
            Fin::audit($db, $companyId, $userId, 'bank_account_created', 'bank_account', $id, ['name' => $name, 'gl_account_id' => $gl]);
            return $id;
        });
    }

    public static function recomputeBalance(PDO $db, int $companyId, int $bankAccountId): void
    {
        $db->prepare("UPDATE gl_bank_accounts SET current_balance_cents = opening_balance_cents
                        + (SELECT COALESCE(SUM(amount_cents), 0) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ?)
                       WHERE id = ? AND company_id = ?")->execute([$companyId, $bankAccountId, $bankAccountId, $companyId]);
    }

    private static function account(PDO $db, int $companyId, int $id, bool $lock = false): array
    {
        $st = $db->prepare("SELECT ba.*, ga.account_code FROM gl_bank_accounts ba LEFT JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                             WHERE ba.id = ? AND ba.company_id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id, $companyId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new FinUserError('That bank account was not found.');
        return $a;
    }

    // ------------------------------------------------------------ statement

    /** $f: account, status (unmatched|matched|all), from, to, q, dir (in|out), page */
    public static function lines(PDO $db, int $companyId, array $f): array
    {
        $where = ['t.company_id = ?'];
        $args = [$companyId];
        if ((int)($f['account'] ?? 0)) { $where[] = 't.bank_account_id = ?'; $args[] = (int)$f['account']; }
        if ($d = Fin::ymd($f['from'] ?? '')) { $where[] = 't.tx_date >= ?'; $args[] = $d; }
        if ($d = Fin::ymd($f['to'] ?? '')) { $where[] = 't.tx_date <= ?'; $args[] = $d; }
        if (($f['dir'] ?? '') === 'in') $where[] = 't.amount_cents > 0';
        if (($f['dir'] ?? '') === 'out') $where[] = 't.amount_cents < 0';
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $c = preg_match('/^[R\s\-\d,.]+$/i', $q) ? Fin::cents($q) : null;
            if ($c) { $where[] = '(ABS(t.amount_cents) = ? OR t.description LIKE ?)'; $args[] = abs($c); $args[] = '%' . Fin::likeEscape($q) . '%'; }
            else { $where[] = '(t.description LIKE ? OR t.reference LIKE ?)'; $args[] = '%' . Fin::likeEscape($q) . '%'; $args[] = '%' . Fin::likeEscape($q) . '%'; }
        }
        $base = implode(' AND ', $where);
        $st = $db->prepare("SELECT SUM(t.matched = 0) un, SUM(t.matched = 1) m, COUNT(*) n FROM gl_bank_transactions t WHERE $base");
        $st->execute($args);
        $cnt = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = (string)($f['status'] ?? 'unmatched');
        if ($status === 'unmatched') $where[] = 't.matched = 0';
        elseif ($status === 'matched') $where[] = 't.matched = 1';
        $sql = implode(' AND ', $where);
        $per = 100;
        $page = max(1, (int)($f['page'] ?? 1));
        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN t.amount_cents > 0 THEN t.amount_cents END), 0), COALESCE(SUM(CASE WHEN t.amount_cents < 0 THEN t.amount_cents END), 0)
                              FROM gl_bank_transactions t WHERE $sql");
        $st->execute($args);
        [$total, $in, $out] = $st->fetch(PDO::FETCH_NUM);
        $st = $db->prepare("SELECT t.bank_tx_id, t.bank_account_id, t.tx_date, t.description, t.amount_cents, t.reference, t.matched, t.journal_id,
                                   ba.name bank, ba.last_reconciled_date, je.reference j_ref, je.description j_desc, je.ref_type j_type, je.ref_id j_ref_id
                              FROM gl_bank_transactions t
                              JOIN gl_bank_accounts ba ON ba.id = t.bank_account_id AND ba.company_id = t.company_id
                         LEFT JOIN journal_entries je ON je.id = t.journal_id AND je.company_id = t.company_id
                             WHERE $sql ORDER BY t.tx_date " . (($f['sort'] ?? '') === 'asc' ? 'ASC' : 'DESC') . ", t.bank_tx_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($args);
        $rows = [];
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids[] = (int)$r['journal_id'];
            $rows[] = [
                'id' => (int)$r['bank_tx_id'], 'account' => (int)$r['bank_account_id'], 'bank' => (string)$r['bank'], 'date' => $r['tx_date'],
                'description' => (string)$r['description'], 'reference' => (string)($r['reference'] ?? ''), 'amount' => ((int)$r['amount_cents']) / 100,
                'matched' => (bool)$r['matched'], 'journal_id' => $r['journal_id'] ? (int)$r['journal_id'] : null,
                'match' => $r['journal_id'] ? ['reference' => (string)$r['j_ref'], 'description' => (string)$r['j_desc'],
                    'kind' => ($r['j_type'] === 'bank_tx') ? ((int)$r['j_ref_id'] === (int)$r['bank_tx_id'] ? 'categorised' : 'transfer') : 'existing'] : null,
                'reconciled' => $r['last_reconciled_date'] && $r['tx_date'] <= $r['last_reconciled_date'],
            ];
        }
        // the accounts each categorised line went to
        $ids = array_values(array_filter(array_unique($ids)));
        if ($ids) {
            $st = $db->prepare("SELECT jl.journal_id, GROUP_CONCAT(DISTINCT jl.account_code ORDER BY jl.id SEPARATOR ',') codes FROM journal_lines jl
                                 WHERE jl.journal_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY jl.journal_id");
            $st->execute($ids);
            $codes = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as &$r) if ($r['journal_id']) $r['codes'] = array_values(array_filter(explode(',', (string)($codes[$r['journal_id']] ?? ''))));
            unset($r);
        }
        return ['rows' => $rows, 'total' => (int)$total, 'page' => $page, 'per' => $per, 'in' => ((int)$in) / 100, 'out' => ((int)$out) / 100,
                'counts' => ['unmatched' => (int)($cnt['un'] ?? 0), 'matched' => (int)($cnt['m'] ?? 0), 'all' => (int)($cnt['n'] ?? 0)]];
    }

    private static function tx(PDO $db, int $companyId, int $id, bool $lock = false): array
    {
        $st = $db->prepare("SELECT t.*, ba.gl_account_id, ba.last_reconciled_date, ba.name bank, ga.account_code bank_code
                              FROM gl_bank_transactions t JOIN gl_bank_accounts ba ON ba.id = t.bank_account_id AND ba.company_id = t.company_id
                         LEFT JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                             WHERE t.bank_tx_id = ? AND t.company_id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id, $companyId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new FinUserError('That bank line was not found.');
        return $t;
    }

    /** A line may change: not in a closed reconciliation, not in a locked period. */
    private static function assertChangeable(PDO $db, int $companyId, array $t): void
    {
        if ($t['last_reconciled_date'] && $t['tx_date'] <= $t['last_reconciled_date']) {
            throw new FinUserError('This line is in a closed reconciliation (to ' . $t['last_reconciled_date'] . '). Reopen the reconciliation first.', 409);
        }
        if ((new PeriodService($db, $companyId))->isLocked($t['tx_date'])) throw new FinUserError('The period of ' . $t['tx_date'] . ' is locked.', 409);
        if (empty($t['bank_code'])) throw new FinUserError('This bank account has no ledger account yet. Choose one in its settings.');
    }

    /** One line with everything to decide how to match it. */
    public static function line(PDO $db, int $companyId, int $id): array
    {
        $t = self::tx($db, $companyId, $id);
        require_once __DIR__ . '/FinFiles.php';
        $out = [
            'line' => ['id' => (int)$t['bank_tx_id'], 'account' => (int)$t['bank_account_id'], 'bank' => (string)$t['bank'], 'date' => $t['tx_date'],
                       'description' => (string)$t['description'], 'reference' => (string)($t['reference'] ?? ''), 'amount' => ((int)$t['amount_cents']) / 100,
                       'matched' => (bool)$t['matched'], 'journal_id' => $t['journal_id'] ? (int)$t['journal_id'] : null,
                       'reconciled' => $t['last_reconciled_date'] && $t['tx_date'] <= $t['last_reconciled_date'],
                       'locked' => (new PeriodService($db, $companyId))->isLocked($t['tx_date'])],
            'files' => FinFiles::list($db, $companyId, 'bank_txn', $id),
        ];
        if (!$t['matched']) {
            $out['existing'] = self::existingCandidates($db, $companyId, $t);
            $out['history'] = self::historyGuess($db, $companyId, $t);
            $out['rule'] = self::ruleFor($db, $companyId, $t);
            $out['transfers'] = self::transferCandidates($db, $companyId, $t);
        }
        return $out;
    }

    /** Postings in the books on this bank account, with the same amount, near the date, not yet matched to any line. */
    public static function existingCandidates(PDO $db, int $companyId, array $t): array
    {
        if (empty($t['bank_code'])) return [];
        $from = (new DateTimeImmutable($t['tx_date']))->modify('-30 days')->format('Y-m-d');
        $to = (new DateTimeImmutable($t['tx_date']))->modify('+30 days')->format('Y-m-d');
        $st = $db->prepare("SELECT je.id, je.entry_date, je.reference, je.description, je.ref_type, je.source_type,
                                   SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)) net
                              FROM journal_entries je JOIN journal_lines jl ON jl.journal_id = je.id AND jl.account_code = ?
                             WHERE je.company_id = ? AND je.status = 'posted' AND je.reversed_by_journal_id IS NULL AND je.reverses_journal_id IS NULL
                               AND je.entry_date BETWEEN ? AND ?
                               AND NOT EXISTS (SELECT 1 FROM gl_bank_transactions x WHERE x.company_id = je.company_id AND x.journal_id = je.id)
                          GROUP BY je.id HAVING net = ?
                          ORDER BY ABS(DATEDIFF(je.entry_date, ?)), je.id DESC LIMIT 6");
        $st->execute([$t['bank_code'], $companyId, $from, $to, (int)$t['amount_cents'], $t['tx_date']]);
        require_once __DIR__ . '/FinJournals.php';
        $words = self::words((string)$t['description'] . ' ' . (string)$t['reference']);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $days = (int)abs((strtotime($r['entry_date']) - strtotime($t['tx_date'])) / 86400);
            $overlap = count(array_intersect($words, self::words($r['reference'] . ' ' . $r['description'])));
            $score = max(10, min(99, 92 - $days * 2 + $overlap * 6));
            $out[] = ['journal_id' => (int)$r['id'], 'date' => $r['entry_date'], 'reference' => (string)$r['reference'], 'description' => (string)$r['description'],
                      'source' => FinJournals::sourceOf($r), 'days' => $days, 'score' => $score];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private static function words(string $s): array
    {
        $w = preg_split('/[^a-z0-9]+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter($w, fn($x) => strlen($x) >= 3 && !in_array($x, ['eft', 'payment', 'pmt', 'ref', 'the', 'and', 'bank', 'debit', 'credit', 'deposit', 'transfer'], true))));
    }

    /** What similar lines were categorised as before (account + VAT code). */
    public static function historyGuess(PDO $db, int $companyId, array $t): ?array
    {
        $words = self::words((string)$t['description']);
        if (!$words) return null;
        $key = '%' . Fin::likeEscape($words[0]) . '%';
        $st = $db->prepare("SELECT jl.account_code, jl.tax_code_id, COUNT(*) n FROM gl_bank_transactions x
                              JOIN journal_entries je ON je.id = x.journal_id AND je.company_id = x.company_id AND je.ref_type = 'bank_tx' AND je.reversed_by_journal_id IS NULL
                              JOIN journal_lines jl ON jl.journal_id = je.id
                              JOIN gl_accounts ga ON ga.company_id = je.company_id AND ga.account_code = jl.account_code AND ga.is_control = 0
                                   AND NOT (ga.account_type = 'asset' AND ga.account_subtype IN ('bank','cash')) AND IFNULL(ga.account_subtype, '') <> 'vat_control'
                             WHERE x.company_id = ? AND x.matched = 1 AND x.description LIKE ? AND SIGN(x.amount_cents) = SIGN(?)
                          GROUP BY jl.account_code, jl.tax_code_id ORDER BY n DESC LIMIT 1");
        $st->execute([$companyId, $key, (int)$t['amount_cents']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['account_code' => (string)$r['account_code'], 'tax_code_id' => $r['tax_code_id'] ? (int)$r['tax_code_id'] : null, 'times' => (int)$r['n']] : null;
    }

    /** Opposite lines on the company's other bank accounts (same amount, within 5 days). */
    public static function transferCandidates(PDO $db, int $companyId, array $t): array
    {
        $st = $db->prepare("SELECT x.bank_tx_id, x.tx_date, x.description, ba.name FROM gl_bank_transactions x JOIN gl_bank_accounts ba ON ba.id = x.bank_account_id AND ba.company_id = x.company_id
                             WHERE x.company_id = ? AND x.bank_account_id <> ? AND x.matched = 0 AND x.amount_cents = ? AND x.tx_date BETWEEN ? AND ? LIMIT 5");
        $st->execute([$companyId, (int)$t['bank_account_id'], -(int)$t['amount_cents'],
                      (new DateTimeImmutable($t['tx_date']))->modify('-5 days')->format('Y-m-d'), (new DateTimeImmutable($t['tx_date']))->modify('+5 days')->format('Y-m-d')]);
        return array_map(fn($r) => ['id' => (int)$r['bank_tx_id'], 'date' => $r['tx_date'], 'description' => (string)$r['description'], 'bank' => (string)$r['name']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // -------------------------------------------------------------- matching

    /** Point the line at a posting already in the books. */
    public static function matchExisting(PDO $db, int $companyId, int $userId, string $role, int $txId, int $journalId): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $txId, $journalId) {
            $t = self::tx($db, $companyId, $txId, true);
            if ((int)$t['matched']) throw new FinUserError('This line is already matched.', 409);
            self::assertChangeable($db, $companyId, $t);
            $st = $db->prepare("SELECT je.status, je.reversed_by_journal_id, SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)) net
                                  FROM journal_entries je JOIN journal_lines jl ON jl.journal_id = je.id AND jl.account_code = ?
                                 WHERE je.id = ? AND je.company_id = ? GROUP BY je.id FOR UPDATE");
            $st->execute([$t['bank_code'], $journalId, $companyId]);
            $j = $st->fetch(PDO::FETCH_ASSOC);
            if (!$j) throw new FinUserError('That journal does not touch this bank account.');
            if ($j['status'] !== 'posted' || $j['reversed_by_journal_id']) throw new FinUserError('Only a posted journal that was not reversed can be matched.', 409);
            if ((int)$j['net'] !== (int)$t['amount_cents']) throw new FinUserError('The amounts differ: the line is ' . Fin::money($t['amount_cents'] / 100) . ', the posting ' . Fin::money($j['net'] / 100) . '.');
            $st = $db->prepare("SELECT bank_tx_id FROM gl_bank_transactions WHERE company_id = ? AND journal_id = ? LIMIT 1");
            $st->execute([$companyId, $journalId]);
            if ($st->fetchColumn()) throw new FinUserError('That posting is already matched to another bank line.', 409);
            $db->prepare("UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ? AND matched = 0")->execute([$journalId, $txId, $companyId]);
            Fin::audit($db, $companyId, $userId, 'bank_tx_matched', 'bank_txn', $txId, ['bank_tx_id' => $txId, 'journal_id' => $journalId, 'how' => 'existing']);
        });
    }

    /**
     * Post the line against one or more accounts. $lines: [{account_code, amount (gross, positive; optional for a single line),
     * tax_code_id?, description?, capital?}]. The gross amounts must add up to the line.
     * @return int journal id
     */
    public static function categorise(PDO $db, int $companyId, int $userId, string $role, int $txId, array $lines, string $sourceType = 'bank_tx', ?string $memo = null): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $txId, $lines, $sourceType, $memo) {
            $t = self::tx($db, $companyId, $txId, true);
            if ((int)$t['matched']) throw new FinUserError('This line is already matched.', 409);
            self::assertChangeable($db, $companyId, $t);
            $amountC = (int)$t['amount_cents'];
            if ($amountC === 0) throw new FinUserError('A nil line has nothing to post.');
            $absC = abs($amountC);
            $in = $amountC > 0;
            $lines = array_values(array_filter($lines, 'is_array'));
            if (!$lines) throw new FinUserError('Choose an account.');
            if (count($lines) > 20) throw new FinUserError('Split a line into 20 parts at most.');
            if (count($lines) === 1 && (Fin::cents($lines[0]['amount'] ?? '') ?? 0) === 0) $lines[0]['amount'] = $absC / 100;
            $sum = 0;
            $map = new AccountsMap($db, $companyId);
            $vatOut = $map->code('finance_vat_output_account_id');
            $vatIn = $map->code('finance_vat_input_account_id');
            $desc = mb_substr(trim($memo ?? '') ?: ((string)$t['description'] ?: 'Bank transaction'), 0, 255);
            $ref = 'BANKTX' . $txId;
            $jl = [['account_code' => $t['bank_code'], 'description' => $desc, 'debit' => $in ? $absC / 100 : 0, 'credit' => $in ? 0 : $absC / 100, 'reference' => $ref]];
            foreach ($lines as $i => $l) {
                $n = $i + 1;
                $code = trim((string)($l['account_code'] ?? ''));
                $grossC = Fin::cents($l['amount'] ?? '') ?? 0;
                if ($code === '') throw new FinUserError("Part $n: choose an account.");
                if ($grossC <= 0) throw new FinUserError("Part $n: enter a positive amount.");
                $sum += $grossC;
                $st = $db->prepare("SELECT account_code, is_control, is_active, account_type, account_subtype FROM gl_accounts WHERE company_id = ? AND account_code = ?");
                $st->execute([$companyId, $code]);
                $a = $st->fetch(PDO::FETCH_ASSOC);
                if (!$a || !(int)$a['is_active']) throw new FinUserError("Part $n: account $code is not an active account on your chart.");
                if ((int)$a['is_control']) throw new FinUserError("Part $n: $code is a control account (receivables, payables, VAT, bank control). Match the line to the payment in the books instead, or choose an income or expense account.");
                if ($a['account_code'] === $t['bank_code']) throw new FinUserError("Part $n: choose an account other than this bank account.");
                $taxId = (int)($l['tax_code_id'] ?? 0) ?: null;
                $vatC = 0;
                $vatCode = null;
                if ($taxId) {
                    $st = $db->prepare("SELECT rate_percent, type FROM gl_tax_codes WHERE tax_code_id = ? AND company_id = ? AND is_active = 1");
                    $st->execute([$taxId, $companyId]);
                    $tc = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$tc) throw new FinUserError("Part $n: choose a VAT code from the list.");
                    $rate = (float)$tc['rate_percent'];
                    if ($rate > 0.005 && in_array($tc['type'], ['output', 'input'], true)) {
                        $vatC = (int)round($grossC * $rate / (100 + $rate));
                        $vatCode = $tc['type'] === 'output' ? $vatOut : $vatIn;
                    }
                }
                $netC = $grossC - $vatC;
                $capital = !empty($l['capital']) && !$in ? 1 : 0;
                $ld = mb_substr(trim((string)($l['description'] ?? '')) ?: $desc, 0, 255);
                $jl[] = ['account_code' => $code, 'description' => $ld, 'debit' => $in ? 0 : $netC / 100, 'credit' => $in ? $netC / 100 : 0, 'tax_code_id' => $taxId, 'is_capital_goods' => $capital, 'reference' => $ref];
                if ($vatC > 0) $jl[] = ['account_code' => $vatCode, 'description' => 'VAT on ' . $ld, 'debit' => $in ? 0 : $vatC / 100, 'credit' => $in ? $vatC / 100 : 0, 'tax_code_id' => $taxId, 'is_capital_goods' => $capital, 'reference' => $ref];
            }
            if ($sum !== $absC) throw new FinUserError('The parts add up to ' . Fin::money($sum / 100) . ' but the line is ' . Fin::money($absC / 100) . '.');
            $jid = (new PostingService($db, $companyId, $userId))->postAdHocJournal([
                'entry_date' => $t['tx_date'], 'reference' => $ref, 'description' => $desc, 'module' => 'fin',
                'ref_type' => 'bank_tx', 'ref_id' => $txId, 'source_type' => $sourceType, 'source_id' => $txId,
            ], $jl);
            $st = $db->prepare("UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ? AND matched = 0");
            $st->execute([$jid, $txId, $companyId]);
            if ($st->rowCount() !== 1) throw new FinUserError('This line was matched by someone else just now.', 409);
            Fin::audit($db, $companyId, $userId, 'bank_tx_matched', 'bank_txn', $txId, ['bank_tx_id' => $txId, 'journal_id' => $jid, 'how' => $sourceType === 'bank_rule' ? 'rule' : 'categorised', 'parts' => count($lines)]);
            return $jid;
        });
    }

    /** Money moved to / from another of the company's bank accounts. Optionally matches the opposite line there too. */
    public static function transfer(PDO $db, int $companyId, int $userId, string $role, int $txId, int $otherAccountId, int $otherTxId = 0): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $txId, $otherAccountId, $otherTxId) {
            $t = self::tx($db, $companyId, $txId, true);
            if ((int)$t['matched']) throw new FinUserError('This line is already matched.', 409);
            self::assertChangeable($db, $companyId, $t);
            $other = self::account($db, $companyId, $otherAccountId);
            if ((int)$other['id'] === (int)$t['bank_account_id']) throw new FinUserError('Choose a different bank account.');
            if (empty($other['account_code'])) throw new FinUserError('That bank account has no ledger account yet.');
            $o = null;
            if ($otherTxId) {
                $o = self::tx($db, $companyId, $otherTxId, true);
                if ((int)$o['bank_account_id'] !== (int)$other['id'] || (int)$o['matched'] || (int)$o['amount_cents'] !== -(int)$t['amount_cents']) throw new FinUserError('That line on the other account does not mirror this one.');
                self::assertChangeable($db, $companyId, $o);
            }
            $c = (int)$t['amount_cents'];
            $abs = abs($c) / 100;
            $desc = 'Transfer ' . ($c > 0 ? 'from ' . $other['name'] : 'to ' . $other['name']);
            $ref = 'BANKTX' . $txId;
            $jid = (new PostingService($db, $companyId, $userId))->postAdHocJournal([
                'entry_date' => $t['tx_date'], 'reference' => $ref, 'description' => $desc, 'module' => 'fin',
                'ref_type' => 'bank_tx', 'ref_id' => $txId, 'source_type' => 'bank_transfer', 'source_id' => $txId,
            ], [
                ['account_code' => $t['bank_code'], 'description' => $desc, 'debit' => $c > 0 ? $abs : 0, 'credit' => $c > 0 ? 0 : $abs, 'reference' => $ref],
                ['account_code' => $other['account_code'], 'description' => $desc, 'debit' => $c > 0 ? 0 : $abs, 'credit' => $c > 0 ? $abs : 0, 'reference' => $ref],
            ]);
            $db->prepare("UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ?")->execute([$jid, $txId, $companyId]);
            if ($o) $db->prepare("UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ?")->execute([$jid, (int)$o['bank_tx_id'], $companyId]);
            Fin::audit($db, $companyId, $userId, 'bank_tx_matched', 'bank_txn', $txId, ['bank_tx_id' => $txId, 'journal_id' => $jid, 'how' => 'transfer', 'other_tx' => $o ? (int)$o['bank_tx_id'] : null]);
            return $jid;
        });
    }

    /** Release a match: reverse the journal the bank posted, or just let go of an existing posting. */
    public static function unmatch(PDO $db, int $companyId, int $userId, string $role, int $txId): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $txId) {
            $t = self::tx($db, $companyId, $txId, true);
            if (!(int)$t['matched'] || !$t['journal_id']) throw new FinUserError('This line is not matched.', 409);
            self::assertChangeable($db, $companyId, $t);
            $jid = (int)$t['journal_id'];
            $st = $db->prepare("SELECT entry_date, ref_type, ref_id, status, reversed_by_journal_id FROM journal_entries WHERE id = ? AND company_id = ?");
            $st->execute([$jid, $companyId]);
            $j = $st->fetch(PDO::FETCH_ASSOC);
            $owned = $j && $j['ref_type'] === 'bank_tx';
            $linked = [];
            $st = $db->prepare("SELECT bank_tx_id, tx_date, bank_account_id FROM gl_bank_transactions WHERE company_id = ? AND journal_id = ? FOR UPDATE");
            $st->execute([$companyId, $jid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) $linked[] = $x;
            $reversal = null;
            if ($owned && $j['status'] === 'posted' && !$j['reversed_by_journal_id']) {
                // every line sharing the journal (a transfer) must be changeable too
                foreach ($linked as $x) if ((int)$x['bank_tx_id'] !== $txId) self::assertChangeable($db, $companyId, self::tx($db, $companyId, (int)$x['bank_tx_id']));
                $periods = new PeriodService($db, $companyId);
                if ($periods->isLocked($j['entry_date'])) throw new FinUserError('The match was posted in a locked period (' . $j['entry_date'] . ').', 409);
                $reversal = (new ReversalService($db, $companyId))->reverseJournal($jid, $userId, 'Bank match undone', $j['entry_date']);
                $db->prepare("UPDATE gl_bank_transactions SET matched = 0, journal_id = NULL WHERE company_id = ? AND journal_id = ?")->execute([$companyId, $jid]);
            } else {
                $db->prepare("UPDATE gl_bank_transactions SET matched = 0, journal_id = NULL WHERE bank_tx_id = ? AND company_id = ?")->execute([$txId, $companyId]);
            }
            Fin::audit($db, $companyId, $userId, 'bank_tx_unmatched', 'bank_txn', $txId, ['bank_tx_id' => $txId, 'journal_id' => $jid, 'reversal_journal_id' => $reversal]);
            return ['reversal_id' => $reversal, 'released' => !$reversal];
        });
    }

    // ----------------------------------------------------------------- rules

    public static function rules(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT r.*, ga.account_code, ga.account_name FROM gl_bank_rules r LEFT JOIN gl_accounts ga ON ga.account_id = r.gl_account_id AND ga.company_id = r.company_id
                             WHERE r.company_id = ? ORDER BY r.priority, r.id");
        $st->execute([$companyId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['rule_name'], 'field' => (string)$r['match_field'], 'operator' => (string)$r['match_operator'],
            'value' => (string)$r['match_value'], 'account_id' => $r['gl_account_id'] ? (int)$r['gl_account_id'] : null, 'account_code' => (string)($r['account_code'] ?? ''),
            'account_name' => (string)($r['account_name'] ?? ''), 'tax_code_id' => $r['tax_code_id'] ? (int)$r['tax_code_id'] : null,
            'template' => (string)($r['description_template'] ?? ''), 'priority' => (int)$r['priority'], 'active' => (bool)$r['is_active']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function saveRule(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $id = (int)($in['id'] ?? 0);
        $name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 120);
        $field = in_array($in['field'] ?? '', ['description', 'reference'], true) ? $in['field'] : 'description';
        $op = in_array($in['operator'] ?? '', ['contains', 'starts_with', 'ends_with', 'equals'], true) ? $in['operator'] : 'contains';
        $value = mb_substr(trim((string)($in['value'] ?? '')), 0, 255);
        $acc = (int)($in['account_id'] ?? 0);
        $tax = (int)($in['tax_code_id'] ?? 0) ?: null;
        $tpl = mb_substr(trim(strip_tags((string)($in['template'] ?? ''))), 0, 255);
        if ($value === '') throw new FinUserError('Say what the bank line should contain.');
        if ($name === '') $name = mb_substr(ucfirst(str_replace('_', ' ', $op)) . ' “' . $value . '”', 0, 120);
        $st = $db->prepare("SELECT is_control, is_active, account_code FROM gl_accounts WHERE account_id = ? AND company_id = ?");
        $st->execute([$acc, $companyId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || !(int)$a['is_active']) throw new FinUserError('Choose an active account for the rule.');
        if ((int)$a['is_control']) throw new FinUserError('A rule cannot post to a control account (receivables, payables, VAT, bank control).');
        if ($tax) {
            $st = $db->prepare("SELECT 1 FROM gl_tax_codes WHERE tax_code_id = ? AND company_id = ? AND is_active = 1");
            $st->execute([$tax, $companyId]);
            if (!$st->fetchColumn()) throw new FinUserError('Choose a VAT code from the list.');
        }
        if ($id) {
            $st = $db->prepare("UPDATE gl_bank_rules SET rule_name = ?, match_field = ?, match_operator = ?, match_value = ?, gl_account_id = ?, tax_code_id = ?, description_template = ? WHERE id = ? AND company_id = ?");
            $st->execute([$name, $field, $op, $value, $acc, $tax, $tpl, $id, $companyId]);
            if ($st->rowCount() === 0) {
                $chk = $db->prepare("SELECT 1 FROM gl_bank_rules WHERE id = ? AND company_id = ?");
                $chk->execute([$id, $companyId]);
                if (!$chk->fetchColumn()) throw new FinUserError('That rule was not found.');
            }
            Fin::audit($db, $companyId, $userId, 'bank_rule_updated', 'bank_rule', $id, ['name' => $name]);
            return $id;
        }
        $st = $db->prepare("SELECT COALESCE(MAX(priority), 0) + 10 FROM gl_bank_rules WHERE company_id = ?");
        $st->execute([$companyId]);
        $prio = (int)$st->fetchColumn();
        $db->prepare("INSERT INTO gl_bank_rules (company_id, rule_name, match_field, match_operator, match_value, gl_account_id, tax_code_id, description_template, priority, is_active, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())")->execute([$companyId, $name, $field, $op, $value, $acc, $tax, $tpl, $prio]);
        $id = (int)$db->lastInsertId();
        Fin::audit($db, $companyId, $userId, 'bank_rule_created', 'bank_rule', $id, ['name' => $name]);
        return $id;
    }

    public static function ruleAction(PDO $db, int $companyId, int $userId, string $role, string $op, array $in): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $id = (int)($in['id'] ?? 0);
        if ($op === 'rule_toggle') {
            $db->prepare("UPDATE gl_bank_rules SET is_active = 1 - is_active WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        } elseif ($op === 'rule_delete') {
            $db->prepare("DELETE FROM gl_bank_rules WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
        } elseif ($op === 'rule_order') {
            $st = $db->prepare("UPDATE gl_bank_rules SET priority = ? WHERE id = ? AND company_id = ?");
            foreach (array_values((array)($in['ids'] ?? [])) as $i => $rid) $st->execute([($i + 1) * 10, (int)$rid, $companyId]);
        }
        Fin::audit($db, $companyId, $userId, 'bank_' . $op, 'bank_rule', $id ?: null, []);
    }

    private static function ruleMatches(array $rule, array $t): bool
    {
        $v = (string)($t[$rule['match_field']] ?? '');
        $m = (string)$rule['match_value'];
        if ($m === '') return false;
        switch ($rule['match_operator']) {
            case 'contains': return stripos($v, $m) !== false;
            case 'starts_with': return stripos($v, $m) === 0;
            case 'ends_with': return strlen($v) >= strlen($m) && strcasecmp(substr($v, -strlen($m)), $m) === 0;
            case 'equals': return strcasecmp(trim($v), trim($m)) === 0;
        }
        return false;
    }

    public static function ruleFor(PDO $db, int $companyId, array $t): ?array
    {
        foreach (self::activeRules($db, $companyId) as $r) if (self::ruleMatches($r, $t)) return ['id' => (int)$r['id'], 'name' => (string)$r['rule_name'], 'account_code' => (string)$r['account_code'], 'tax_code_id' => $r['tax_code_id'] ? (int)$r['tax_code_id'] : null];
        return null;
    }

    private static function activeRules(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT r.*, ga.account_code FROM gl_bank_rules r JOIN gl_accounts ga ON ga.account_id = r.gl_account_id AND ga.company_id = r.company_id AND ga.is_active = 1 AND ga.is_control = 0
                             WHERE r.company_id = ? AND r.is_active = 1 AND r.match_field IN ('description','reference') ORDER BY r.priority, r.id");
        $st->execute([$companyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Run the rules over open lines (one transaction per line). $dry only reports what would happen. */
    public static function applyRules(PDO $db, int $companyId, int $userId, string $role, int $accountId = 0, bool $dry = false): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $rules = self::activeRules($db, $companyId);
        $sql = "SELECT t.*, ba.last_reconciled_date FROM gl_bank_transactions t JOIN gl_bank_accounts ba ON ba.id = t.bank_account_id AND ba.company_id = t.company_id
                 WHERE t.company_id = ? AND t.matched = 0 AND t.amount_cents <> 0 AND (ba.last_reconciled_date IS NULL OR t.tx_date > ba.last_reconciled_date)";
        $args = [$companyId];
        if ($accountId) { $sql .= ' AND t.bank_account_id = ?'; $args[] = $accountId; }
        $st = $db->prepare($sql . ' ORDER BY t.tx_date LIMIT 2000');
        $st->execute($args);
        $periods = new PeriodService($db, $companyId);
        $done = 0; $skipped = 0; $plan = []; $errors = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if ($periods->isLocked($t['tx_date'])) { $skipped++; continue; }
            foreach ($rules as $r) {
                if (!self::ruleMatches($r, $t)) continue;
                if ($dry) { $plan[] = ['id' => (int)$t['bank_tx_id'], 'rule' => $r['rule_name'], 'account_code' => $r['account_code']]; break; }
                $tpl = trim((string)$r['description_template']);
                try {
                    self::categorise($db, $companyId, $userId, 'admin', (int)$t['bank_tx_id'],
                        [['account_code' => $r['account_code'], 'tax_code_id' => $r['tax_code_id'] ?: null]], 'bank_rule', $tpl !== '' ? $tpl : null);
                    $done++;
                } catch (Throwable $e) {
                    $skipped++;
                    if (count($errors) < 10) $errors[] = $t['tx_date'] . ' ' . $t['description'] . ': ' . ($e instanceof FinUserError || get_class($e) === 'Exception' ? $e->getMessage() : 'could not post');
                    if (!($e instanceof FinUserError) && get_class($e) !== 'Exception') error_log('[finance/bank] rule: ' . $e->getMessage());
                }
                break;
            }
        }
        if (!$dry && $done) Fin::audit($db, $companyId, $userId, 'bank_rules_applied', 'bank_account', $accountId ?: null, ['matched' => $done, 'skipped' => $skipped]);
        return ['matched' => $done, 'skipped' => $skipped, 'plan' => $plan, 'errors' => $errors];
    }

    // ---------------------------------------------------------------- import

    /**
     * Read a statement file into rows without saving. CSV (with a column map), OFX/QFX or PDF.
     * $map: {date, description, amount | debit + credit, reference, date_format?} column indexes.
     */
    public static function parse(PDO $db, int $companyId, int $accountId, string $path, string $name, array $map = []): array
    {
        $acc = self::account($db, $companyId, $accountId);
        $size = (int)@filesize($path);
        if ($size <= 0) throw new FinUserError('That file is empty.');
        if ($size > 10 * 1024 * 1024) throw new FinUserError('That file is too large (10 MB at most).');
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $warnings = [];
        $head = null;
        $columns = null;
        if ($mime === 'application/pdf') {
            require_once __DIR__ . '/BankStatementParser.php';
            $p = (new BankStatementParser())->parsePDF($path);
            if (empty($p['ok']) && empty($p['transactions'])) throw new FinUserError($p['error'] ?? 'No lines could be read from this PDF. Download a CSV or OFX statement instead.');
            $rows = [];
            foreach ((array)($p['transactions'] ?? []) as $r) $rows[] = ['date' => (string)$r['tx_date'], 'description' => (string)$r['description'], 'amount_cents' => (int)$r['amount_cents'], 'reference' => (string)($r['reference'] ?? '')];
            $warnings = array_merge($warnings, (array)($p['warnings'] ?? []));
            $kind = 'pdf';
        } elseif (in_array($ext, ['ofx', 'qfx'], true) || preg_match('/<OFX>|OFXHEADER/i', (string)file_get_contents($path, false, null, 0, 2048))) {
            $rows = self::parseOfx((string)file_get_contents($path));
            $kind = 'ofx';
        } elseif (preg_match('#^text/|application/(csv|vnd\.ms-excel)#', $mime)) {
            [$rows, $head, $columns, $w] = self::parseCsv((string)file_get_contents($path), $map);
            $warnings = array_merge($warnings, $w);
            $kind = 'csv';
        } else {
            throw new FinUserError('Upload the statement as CSV, OFX or PDF.');
        }
        // flag duplicates and lines inside a closed reconciliation
        $seen = [];
        $check = $db->prepare("SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ? AND import_hash = ?");
        foreach ($rows as $i => $r) {
            $key = $r['date'] . '|' . $r['description'] . '|' . $r['amount_cents'] . '|' . ($r['reference'] ?? '');
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $rows[$i]['hash'] = sha1($key . '|#' . $seen[$key]);
            $check->execute([$companyId, $accountId, $rows[$i]['hash']]);
            $rows[$i]['duplicate'] = (int)$check->fetchColumn() > 0;
            $rows[$i]['reconciled'] = $acc['last_reconciled_date'] && $r['date'] <= $acc['last_reconciled_date'];
        }
        return ['kind' => $kind, 'rows' => $rows, 'warnings' => $warnings, 'head' => $head, 'columns' => $columns,
                'new' => count(array_filter($rows, fn($r) => !$r['duplicate'] && !$r['reconciled'])), 'reconciled_to' => $acc['last_reconciled_date']];
    }

    private static function parseOfx(string $s): array
    {
        $rows = [];
        if (!preg_match_all('#<STMTTRN>(.*?)(?:</STMTTRN>|(?=<STMTTRN>)|</BANKTRANLIST>)#si', $s, $m)) throw new FinUserError('No transactions were found in this OFX file.');
        foreach ($m[1] as $blk) {
            $get = function ($tag) use ($blk) { return preg_match('#<' . $tag . '>([^<\r\n]*)#i', $blk, $x) ? trim(html_entity_decode($x[1], ENT_QUOTES | ENT_XML1)) : ''; };
            $d = substr($get('DTPOSTED'), 0, 8);
            $amt = Fin::cents($get('TRNAMT'));
            if (!preg_match('/^\d{8}$/', $d) || $amt === null) continue;
            $date = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
            if (!Fin::ymd($date)) continue;
            $desc = trim($get('NAME') . ' ' . $get('MEMO'));
            $rows[] = ['date' => $date, 'description' => mb_substr($desc ?: 'Bank transaction', 0, 255), 'amount_cents' => $amt, 'reference' => mb_substr($get('CHECKNUM') ?: $get('REFNUM'), 0, 100)];
        }
        if (!$rows) throw new FinUserError('No transactions could be read from this OFX file.');
        return $rows;
    }

    /** @return array{0: array, 1: array|null, 2: array, 3: array} rows, header, column map used, warnings */
    private static function parseCsv(string $s, array $map): array
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        $lines = preg_split('/\r\n|\n|\r/', trim($s));
        $first = $lines[0] ?? '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $grid = array_map(fn($l) => array_map('trim', str_getcsv($l, $delim)), array_filter($lines, fn($l) => trim($l) !== ''));
        if (!$grid) throw new FinUserError('That file has no lines.');
        // header: the first row with no parsable date in it
        $head = null;
        $start = 0;
        foreach (array_slice($grid, 0, 15) as $i => $row) {
            $hasDate = false;
            foreach ($row as $c) if (self::date($c)) { $hasDate = true; break; }
            if ($hasDate) { $start = $i; break; }
            $head = $row;
            $start = $i + 1;
        }
        $cols = count($grid[$start] ?? []);
        if (!$map && $head) {
            foreach ($head as $i => $h) {
                $h = strtolower($h);
                if (!isset($map['date']) && preg_match('/date/', $h)) $map['date'] = $i;
                elseif (!isset($map['description']) && preg_match('/desc|narrat|details|particular|transaction/', $h)) $map['description'] = $i;
                elseif (!isset($map['amount']) && preg_match('/^amount|value/', $h)) $map['amount'] = $i;
                elseif (!isset($map['debit']) && preg_match('/debit|withdraw|money out|paid out/', $h)) $map['debit'] = $i;
                elseif (!isset($map['credit']) && preg_match('/credit|deposit|money in|paid in/', $h)) $map['credit'] = $i;
                elseif (!isset($map['reference']) && preg_match('/ref/', $h)) $map['reference'] = $i;
            }
        }
        $map += ['date' => 0, 'description' => 1];
        if (!isset($map['amount']) && !isset($map['debit'])) $map['amount'] = 2;
        $map = array_map(fn($v) => is_numeric($v) ? max(0, min($cols - 1, (int)$v)) : $v, array_filter($map, fn($v) => $v !== '' && $v !== null));
        $rows = [];
        $bad = 0;
        foreach (array_slice($grid, $start) as $row) {
            $date = self::date($row[$map['date']] ?? '');
            if (isset($map['amount'])) $c = Fin::cents(self::amt($row[$map['amount']] ?? ''));
            else {
                $dr = Fin::cents(self::amt($row[$map['debit']] ?? '')) ?? 0;
                $cr = isset($map['credit']) ? (Fin::cents(self::amt($row[$map['credit']] ?? '')) ?? 0) : 0;
                $c = $cr - abs($dr);
            }
            if (!$date || $c === null || $c === 0) { $bad++; continue; }
            $rows[] = ['date' => $date, 'description' => mb_substr((string)($row[$map['description']] ?? ''), 0, 255) ?: 'Bank transaction', 'amount_cents' => $c,
                       'reference' => isset($map['reference']) ? mb_substr((string)($row[$map['reference']] ?? ''), 0, 100) : ''];
        }
        if (!$rows) throw new FinUserError('No lines with a date and an amount were found. Check which columns hold the date, description and amount.');
        return [$rows, $head, $map, $bad ? ["$bad line(s) without a date or amount were skipped."] : []];
    }

    private static function amt(string $s): string
    {
        $s = trim($s);
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
        if (preg_match('/^(.*?)\s*(-|DR|Dr)$/', $s, $m)) { $neg = true; $s = $m[1]; }
        if (preg_match('/^(.*?)\s*(CR|Cr)$/', $s, $m)) $s = $m[1];
        $s = preg_replace('/^(ZAR|R)\s*/i', '', $s);
        $s = str_replace(["\xC2\xA0", ' '], '', $s);
        return ($neg && !str_starts_with($s, '-') ? '-' : '') . $s;
    }

    private static function date(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd M Y', 'j M Y', 'd/m/y', 'Ymd'] as $f) {
            $d = DateTime::createFromFormat('!' . $f, $s);
            $e = DateTime::getLastErrors();
            if ($d && ($e === false || ($e['warning_count'] === 0 && $e['error_count'] === 0)) && (int)$d->format('Y') >= 1990 && (int)$d->format('Y') <= 2100) return $d->format('Y-m-d');
        }
        return null;
    }

    /** Save parsed rows. Duplicates and lines in a closed reconciliation are skipped. */
    public static function import(PDO $db, int $companyId, int $userId, string $role, int $accountId, array $rows, string $fileName): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        if (count($rows) > 5000) throw new FinUserError('Import up to 5000 lines at a time.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $accountId, $rows, $fileName) {
            $acc = self::account($db, $companyId, $accountId, true);
            $db->prepare("INSERT INTO bank_import_batches (company_id, bank_account_id, file_name, imported_by) VALUES (?, ?, ?, ?)")
               ->execute([$companyId, $accountId, mb_substr(preg_replace('/[^\x20-\x7E]/', '_', basename($fileName)) ?: 'statement', 0, 200), $userId]);
            $batch = (int)$db->lastInsertId();
            $check = $db->prepare("SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ? AND import_hash = ?");
            $ins = $db->prepare("INSERT INTO gl_bank_transactions (company_id, bank_account_id, tx_date, description, amount_cents, reference, matched, import_batch_id, import_hash, created_at)
                                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())");
            $seen = [];
            $n = 0; $dup = 0; $rec = 0; $bad = 0;
            foreach ($rows as $r) {
                if (!is_array($r)) { $bad++; continue; }
                $date = Fin::ymd((string)($r['date'] ?? ''));
                $c = is_numeric($r['amount_cents'] ?? null) ? (int)$r['amount_cents'] : 0;
                $desc = mb_substr(trim(strip_tags((string)($r['description'] ?? ''))), 0, 255) ?: 'Bank transaction';
                $ref = mb_substr(trim(strip_tags((string)($r['reference'] ?? ''))), 0, 100);
                if (!$date || $c === 0 || abs($c) > 99999999999) { $bad++; continue; }
                if ($acc['last_reconciled_date'] && $date <= $acc['last_reconciled_date']) { $rec++; continue; }
                $key = $date . '|' . $desc . '|' . $c . '|' . $ref;
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $hash = sha1($key . '|#' . $seen[$key]);
                $check->execute([$companyId, $accountId, $hash]);
                if ((int)$check->fetchColumn() > 0) { $dup++; continue; }
                $ins->execute([$companyId, $accountId, $date, $desc, $c, $ref !== '' ? $ref : null, $batch, $hash]);
                $n++;
            }
            self::recomputeBalance($db, $companyId, $accountId);
            Fin::audit($db, $companyId, $userId, 'bank_statement_imported', 'bank_account', $accountId, ['batch' => $batch, 'imported' => $n, 'duplicates' => $dup, 'reconciled' => $rec, 'invalid' => $bad]);
            return ['imported' => $n, 'duplicates' => $dup, 'reconciled' => $rec, 'invalid' => $bad, 'batch' => $batch];
        });
    }

    // -------------------------------------------------------- reconciliation

    public static function reconcileSummary(PDO $db, int $companyId, int $accountId, string $date, ?int $statementC): array
    {
        $acc = self::account($db, $companyId, $accountId);
        if (empty($acc['account_code'])) throw new FinUserError('This bank account has no ledger account yet.');
        $st = $db->prepare("SELECT COALESCE(SUM(amount_cents), 0), COALESCE(SUM(CASE WHEN matched = 0 THEN amount_cents END), 0), SUM(matched = 0)
                              FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ? AND tx_date <= ?");
        $st->execute([$companyId, $accountId, $date]);
        [$sumC, $unC, $unN] = $st->fetch(PDO::FETCH_NUM);
        $importedC = (int)$acc['opening_balance_cents'] + (int)$sumC;
        $booksC = self::booksBalance($db, $companyId, (string)$acc['account_code'], $date);
        // Postings in the books on this bank account that no statement line points at. Those dated
        // before the first imported line are what the opening balance stands for; the rest are
        // timing differences (in the books, not on the statement yet). A reversed journal and its
        // reversal that are both unmatched cancel out and are left out.
        $st = $db->prepare("SELECT MIN(tx_date) FROM gl_bank_transactions WHERE company_id = ? AND bank_account_id = ?");
        $st->execute([$companyId, $accountId]);
        $firstLine = $st->fetchColumn() ?: '2999-12-31';
        $unlinked = "FROM journal_entries je JOIN journal_lines jl ON jl.journal_id = je.id AND jl.account_code = ?
                    WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date <= ?
                      AND NOT EXISTS (SELECT 1 FROM gl_bank_transactions x WHERE x.company_id = je.company_id AND x.journal_id = je.id)
                      AND NOT (je.reversed_by_journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gl_bank_transactions y WHERE y.company_id = je.company_id AND y.journal_id = je.reversed_by_journal_id))
                      AND NOT (je.reverses_journal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM gl_bank_transactions y WHERE y.company_id = je.company_id AND y.journal_id = je.reverses_journal_id))";
        $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN je.entry_date < ? THEN ROUND(jl.debit * 100) - ROUND(jl.credit * 100) END), 0),
                                   COALESCE(SUM(CASE WHEN je.entry_date >= ? THEN ROUND(jl.debit * 100) - ROUND(jl.credit * 100) END), 0) $unlinked");
        $st->execute([$firstLine, $firstLine, $acc['account_code'], $companyId, $date]);
        [$beforeC, $unclearedC] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
        $st = $db->prepare("SELECT je.id, je.entry_date, je.reference, je.description, SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)) net $unlinked AND je.entry_date >= ?
                          GROUP BY je.id HAVING net <> 0 ORDER BY je.entry_date DESC LIMIT 50");
        $st->execute([$acc['account_code'], $companyId, $date, $firstLine]);
        $unclearedRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $explainedC = $booksC + (int)$unC - $unclearedC;
        $statement = $statementC ?? $importedC;
        return [
            'account' => ['id' => (int)$acc['id'], 'name' => (string)$acc['name'], 'code' => (string)$acc['account_code'], 'reconciled' => $acc['last_reconciled_date']],
            'date' => $date, 'opening' => ((int)$acc['opening_balance_cents']) / 100, 'imported' => $importedC / 100, 'statement' => $statement / 100,
            'statement_ok' => $statement === $importedC, 'books' => $booksC / 100, 'unmatched' => ((int)$unC) / 100, 'unmatched_count' => (int)$unN,
            'uncleared' => $unclearedC / 100, 'uncleared_rows' => array_map(fn($r) => ['journal_id' => (int)$r['id'], 'date' => $r['entry_date'], 'reference' => (string)$r['reference'],
                'description' => (string)$r['description'], 'amount' => ((int)$r['net']) / 100], $unclearedRows),
            'before_first_line' => $beforeC / 100, 'first_line' => $firstLine === '2999-12-31' ? null : $firstLine,
            'difference' => ($statement - $explainedC) / 100,
            'can_close' => $statement === $importedC && (int)$unN === 0 && $statement === $explainedC && (!$acc['last_reconciled_date'] || $date > $acc['last_reconciled_date']),
            'history' => self::history($db, $companyId, $accountId),
        ];
    }

    public static function history(PDO $db, int $companyId, int $accountId): array
    {
        try {
            $st = $db->prepare("SELECT id, statement_start, statement_end, closing_balance_cents, status, closed_by, closed_at FROM bank_reconciliations
                                 WHERE company_id = ? AND bank_account_id = ? ORDER BY statement_end DESC LIMIT 24");
            $st->execute([$companyId, $accountId]);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn($r) => ['id' => (int)$r['id'], 'from' => $r['statement_start'], 'to' => $r['statement_end'], 'closing' => ((int)$r['closing_balance_cents']) / 100,
                                    'status' => $r['status'] === 'closed' ? 'closed' : 'open', 'by' => Fin::personName($db, $companyId, $r['closed_by']), 'at' => $r['closed_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function closeReconciliation(PDO $db, int $companyId, int $userId, string $role, int $accountId, string $date, int $statementC): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $accountId, $date, $statementC) {
            $acc = self::account($db, $companyId, $accountId, true);
            if ($date > date('Y-m-d')) throw new FinUserError('The statement date cannot be in the future.');
            if ($acc['last_reconciled_date'] && $date <= $acc['last_reconciled_date']) throw new FinUserError('Choose a date after the last reconciliation (' . $acc['last_reconciled_date'] . ').');
            $s = self::reconcileSummary($db, $companyId, $accountId, $date, $statementC);
            if (!$s['statement_ok']) throw new FinUserError('The statement balance you entered (' . Fin::money($statementC / 100) . ') differs from the imported lines (' . Fin::money($s['imported']) . ' at ' . $date . '). Import the missing lines first.', 409);
            if ($s['unmatched_count'] > 0) throw new FinUserError($s['unmatched_count'] . ' line(s) up to ' . $date . ' are not matched yet.', 409);
            if (abs($s['difference']) >= 0.005) throw new FinUserError('The books do not agree with the statement: the difference is ' . Fin::money($s['difference']) . '.', 409);
            $from = $acc['last_reconciled_date'] ? (new DateTimeImmutable($acc['last_reconciled_date']))->modify('+1 day')->format('Y-m-d') : '2000-01-01';
            $db->prepare("UPDATE gl_bank_accounts SET last_reconciled_date = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$date, $accountId, $companyId]);
            try {
                $db->prepare("INSERT INTO bank_reconciliations (company_id, bank_account_id, statement_start, statement_end, opening_balance_cents, closing_balance_cents, status, created_by, closed_by, closed_at)
                              VALUES (?, ?, ?, ?, ?, ?, 'closed', ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE closing_balance_cents = VALUES(closing_balance_cents), status = 'closed', closed_by = VALUES(closed_by), closed_at = NOW()")
                   ->execute([$companyId, $accountId, $from, $date, (int)$acc['opening_balance_cents'], $statementC, $userId, $userId]);
            } catch (Throwable $e) {
                error_log('[finance/bank] reconciliation history: ' . $e->getMessage());
            }
            Fin::audit($db, $companyId, $userId, 'bank_reconcile_close', 'bank_account', $accountId, ['statement_date' => $date, 'closing_cents' => $statementC,
                       'books_cents' => (int)round($s['books'] * 100), 'uncleared_cents' => (int)round($s['uncleared'] * 100)]);
            return $s;
        });
    }

    /** Reopen the latest reconciliation (administrators). */
    public static function reopen(PDO $db, int $companyId, int $userId, string $role, int $accountId, string $reason): void
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can reopen a reconciliation.');
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') throw new FinUserError('Say why the reconciliation is reopened.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $accountId, $reason) {
            $acc = self::account($db, $companyId, $accountId, true);
            if (!$acc['last_reconciled_date']) throw new FinUserError('This account has no closed reconciliation.');
            if ((new PeriodService($db, $companyId))->isLocked($acc['last_reconciled_date'])) throw new FinUserError('The reconciled period is locked; unlock it first.', 409);
            $prev = null;
            try {
                $db->prepare("UPDATE bank_reconciliations SET status = 'open' WHERE company_id = ? AND bank_account_id = ? AND statement_end = ?")->execute([$companyId, $accountId, $acc['last_reconciled_date']]);
                $st = $db->prepare("SELECT MAX(statement_end) FROM bank_reconciliations WHERE company_id = ? AND bank_account_id = ? AND status = 'closed' AND statement_end < ?");
                $st->execute([$companyId, $accountId, $acc['last_reconciled_date']]);
                $prev = $st->fetchColumn() ?: null;
            } catch (Throwable $e) {
                $prev = null;
            }
            $db->prepare("UPDATE gl_bank_accounts SET last_reconciled_date = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$prev, $accountId, $companyId]);
            Fin::audit($db, $companyId, $userId, 'bank_reconcile_reopened', 'bank_account', $accountId, ['was' => $acc['last_reconciled_date'], 'now' => $prev, 'reason' => $reason]);
        });
    }
}
