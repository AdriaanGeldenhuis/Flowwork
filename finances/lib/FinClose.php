<?php
// finances/lib/FinClose.php
//
// Closing the books: period locks, the year-end close and a health check.
//
//   - A lock keeps every date up to and including it closed for posting
//     (PeriodService: the latest active lock wins). Bookkeepers and admins
//     lock; only an admin removes a lock, with a reason, and never a lock that
//     VAT filing or a year-end close placed (those reasons are reserved, so
//     nobody can type them on a hand-made lock either).
//   - The year-end close moves the year's income and expenses to retained
//     earnings in one journal (module 'year_end', dated the last day of the
//     year) and locks the year — the old close's sums, refusals and
//     reference. The balances are now read inside the transaction that posts
//     them (they were read before it), and the journal goes through the
//     posting engine's balance and account checks. A closed year can be
//     reopened by an admin: the closing journal is reversed and its lock lifted.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/AccountsMap.php';
require_once __DIR__ . '/PostingService.php';
require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/ReversalService.php';

final class FinClose
{
    public static function isSystemReason(string $reason): bool
    {
        $r = strtolower(trim($reason));
        return $r === 'vat_period_filed' || str_starts_with($r, 'year_end_close_');
    }

    public static function locks(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT l.*, u.first_name, u.last_name, d.first_name dfn, d.last_name dln FROM gl_period_locks l
                              LEFT JOIN users u ON u.id = l.locked_by LEFT JOIN users d ON d.id = l.deleted_by
                             WHERE l.company_id = ? ORDER BY l.is_active DESC, l.lock_date DESC, l.lock_id DESC LIMIT 200");
        $st->execute([$companyId]);
        $rows = array_map(function ($r) {
            $sys = self::isSystemReason((string)$r['lock_reason']);
            $why = (string)$r['lock_reason'];
            if ($why === 'vat_period_filed') $why = 'VAT return filed';
            elseif (str_starts_with($why, 'year_end_close_')) $why = 'Year-end close ' . substr($why, 15);
            return ['id' => (int)$r['lock_id'], 'date' => $r['lock_date'], 'reason' => $why, 'system' => $sys, 'active' => (bool)$r['is_active'],
                    'by' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), 'at' => $r['locked_at'], 'removed_at' => $r['deleted_at'],
                    'removed_by' => trim(($r['dfn'] ?? '') . ' ' . ($r['dln'] ?? ''))];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $through = null;
        foreach ($rows as $r) if ($r['active'] && ($through === null || $r['date'] > $through)) $through = $r['date'];
        return ['rows' => $rows, 'locked_through' => $through, 'suggest' => date('Y-m-d', strtotime('last day of last month'))];
    }

    public static function lock(PDO $db, int $companyId, int $userId, string $role, string $date, string $reason): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $d = Fin::ymd($date);
        if (!$d) throw new FinUserError('Choose the last day to lock.');
        if ($d > date('Y-m-d', strtotime('+366 days'))) throw new FinUserError('That is more than a year ahead.');
        $reason = mb_substr(trim(strip_tags($reason)), 0, 200);
        if ($reason === '') $reason = 'Month closed';
        if (self::isSystemReason($reason)) throw new FinUserError('That reason is reserved for VAT filing and the year-end close. Describe why you are locking.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $d, $reason) {
            $st = $db->prepare("SELECT lock_id, lock_reason, is_active FROM gl_period_locks WHERE company_id = ? AND lock_date = ? FOR UPDATE");
            $st->execute([$companyId, $d]);
            $ex = $st->fetch(PDO::FETCH_ASSOC);
            if ($ex && self::isSystemReason((string)$ex['lock_reason'])) throw new FinUserError('This date is already locked by VAT filing or a year-end close.', 409);
            if ($ex) {
                $db->prepare("UPDATE gl_period_locks SET lock_reason = ?, locked_by = ?, locked_at = NOW(), is_active = 1, deleted_at = NULL, deleted_by = NULL WHERE lock_id = ? AND company_id = ?")
                   ->execute([$reason, $userId, (int)$ex['lock_id'], $companyId]);
                $id = (int)$ex['lock_id'];
            } else {
                $db->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active) VALUES (?, ?, ?, ?, NOW(), 1)")->execute([$companyId, $d, $reason, $userId]);
                $id = (int)$db->lastInsertId();
            }
            Fin::audit($db, $companyId, $userId, $ex ? 'period_lock_updated' : 'period_lock_added', 'period_lock', $id, ['lock_date' => $d, 'reason' => $reason]);
            return $id;
        });
    }

    public static function unlock(PDO $db, int $companyId, int $userId, string $role, int $id, string $why): void
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can remove a lock.');
        $why = mb_substr(trim(strip_tags($why)), 0, 255);
        if (mb_strlen($why) < 3) throw new FinUserError('Say why the lock is removed (it is kept on the audit trail).');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $why) {
            $st = $db->prepare("SELECT lock_date, lock_reason, is_active FROM gl_period_locks WHERE lock_id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $l = $st->fetch(PDO::FETCH_ASSOC);
            if (!$l) throw new FinUserError('That lock was not found.');
            if (!(int)$l['is_active']) throw new FinUserError('That lock is already removed.', 409);
            if (self::isSystemReason((string)$l['lock_reason'])) throw new FinUserError('VAT filing and the year-end close placed this lock. Reopen the year from the year-end section instead.', 409);
            $db->prepare("UPDATE gl_period_locks SET is_active = 0, deleted_at = NOW(), deleted_by = ? WHERE lock_id = ? AND company_id = ?")->execute([$userId, $id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'period_lock_deleted', 'period_lock', $id, ['lock_date' => $l['lock_date'], 'reason' => $l['lock_reason'], 'why' => $why]);
        });
    }

    // --------------------------------------------------------- year end

    private static function retainedCode(PDO $db, int $companyId): array
    {
        $notDebit = "(normal_balance IS NULL OR normal_balance <> 'debit')";
        $map = new AccountsMap($db, $companyId);
        $code = null;
        if ($id = $map->getAccountId('finance_retained_earnings_account_id')) {
            $st = $db->prepare("SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ? AND is_active = 1 AND $notDebit LIMIT 1");
            $st->execute([$id, $companyId]);
            $code = $st->fetchColumn() ?: null;
        }
        foreach (["account_subtype = 'retained_earnings' AND $notDebit", "account_name LIKE '%Retained Earnings%'"] as $w) {
            if ($code) break;
            $st = $db->prepare("SELECT account_code FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND $w ORDER BY account_code LIMIT 1");
            $st->execute([$companyId]);
            $code = $st->fetchColumn() ?: null;
        }
        if (!$code) throw new FinUserError('Add a Retained Earnings account (kind: retained earnings) to the chart before closing a year.');
        $st = $db->prepare("SELECT account_name FROM gl_accounts WHERE company_id = ? AND account_code = ? LIMIT 1");
        $st->execute([$companyId, $code]);
        return [(string)$code, (string)($st->fetchColumn() ?: 'Retained Earnings')];
    }

    /** Financial years from the first posting to the last one that has ended, newest first. */
    public static function years(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT MIN(entry_date) FROM journal_entries WHERE company_id = ? AND status = 'posted'");
        $st->execute([$companyId]);
        $first = $st->fetchColumn() ?: date('Y-m-d');
        $fy = new DateTimeImmutable(Fin::fyStart($db, $companyId, $first));
        $out = [];
        $closed = $db->prepare("SELECT id, reference, posted_at FROM journal_entries WHERE company_id = ? AND module = 'year_end' AND status = 'posted' AND reversed_by_journal_id IS NULL AND reverses_journal_id IS NULL AND entry_date BETWEEN ? AND ? ORDER BY id DESC LIMIT 1");
        $pl = $db->prepare("SELECT ROUND(SUM(jl.credit - jl.debit) * 100) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND ga.account_type IN ('revenue','expense') AND COALESCE(je.module,'') <> 'year_end' AND je.entry_date BETWEEN ? AND ?");
        for ($i = 0; $i < 30; $i++) {
            $start = $fy->format('Y-m-d');
            $end = $fy->modify('+1 year -1 day')->format('Y-m-d');
            $closed->execute([$companyId, $start, $end]);
            $c = $closed->fetch(PDO::FETCH_ASSOC);
            $pl->execute([$companyId, $start, $end]);
            $out[] = ['start' => $start, 'end' => $end, 'ended' => $end < date('Y-m-d'), 'closed' => (bool)$c, 'journal_id' => $c ? (int)$c['id'] : null,
                      'closed_at' => $c['posted_at'] ?? null, 'result' => (int)$pl->fetchColumn() / 100];
            $fy = $fy->modify('+1 year');
            if ($fy->format('Y-m-d') > date('Y-m-d')) break;
        }
        return array_reverse($out);
    }

    /** The closing journal's lines (cents) for a year, read from the posted books. */
    private static function closingLines(PDO $db, int $companyId, string $start, string $end, string $reCode, string $reName): array
    {
        $st = $db->prepare("SELECT ga.account_code, ga.account_name, ga.account_type, ROUND(SUM(jl.debit) * 100) d, ROUND(SUM(jl.credit) * 100) c
                              FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ? AND ga.account_type IN ('revenue','expense')
                          GROUP BY ga.account_code, ga.account_name, ga.account_type HAVING SUM(jl.debit) - SUM(jl.credit) <> 0 ORDER BY ga.account_code");
        $st->execute([$companyId, $start, $end]);
        $lines = []; $net = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $bal = (int)$b['d'] - (int)$b['c'];
            if ($bal === 0) continue;
            $net -= $bal;
            $lines[] = ['account_code' => $b['account_code'], 'account_name' => $b['account_name'], 'debit_cents' => $bal < 0 ? -$bal : 0, 'credit_cents' => $bal > 0 ? $bal : 0];
        }
        if ($net > 0) $lines[] = ['account_code' => $reCode, 'account_name' => $reName, 'debit_cents' => 0, 'credit_cents' => $net];
        elseif ($net < 0) $lines[] = ['account_code' => $reCode, 'account_name' => $reName, 'debit_cents' => -$net, 'credit_cents' => 0];
        return [$lines, $net];
    }

    private static function checkYear(PDO $db, int $companyId, string $start, string $end): void
    {
        if ($start >= $end) throw new FinUserError('Choose a financial year.');
        if ($end >= date('Y-m-d')) throw new FinUserError('That year has not ended yet — only a year that ended before today can be closed.');
        $exp = (new DateTimeImmutable($start))->modify('+1 year -1 day')->format('Y-m-d');
        if ($exp !== $end) throw new FinUserError('A financial year runs twelve months.');
    }

    public static function preview(PDO $db, int $companyId, string $start): array
    {
        $s = Fin::ymd($start);
        if (!$s) throw new FinUserError('Choose a financial year.');
        $e = (new DateTimeImmutable($s))->modify('+1 year -1 day')->format('Y-m-d');
        [$re, $reName] = self::retainedCode($db, $companyId);
        [$lines, $net] = self::closingLines($db, $companyId, $s, $e, $re, $reName);
        $blockers = [];
        if ($e >= date('Y-m-d')) $blockers[] = 'The year has not ended yet.';
        if ((new PeriodService($db, $companyId))->isLocked($e)) $blockers[] = 'The books are locked on ' . $e . '. The closing journal is dated that day, so remove the lock first (or reopen VAT periods after it).';
        if (self::earlierUnclosed($db, $companyId, $s)) $blockers[] = 'An earlier year has income or expenses that were never closed. Close earlier years first.';
        $st = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND status IN ('draft','prepared','approved') AND entry_date BETWEEN ? AND ?");
        $st->execute([$companyId, $s, $e]);
        $drafts = (int)$st->fetchColumn();
        return ['start' => $s, 'end' => $e, 'retained' => ['code' => $re, 'name' => $reName], 'net' => $net / 100,
                'lines' => array_map(fn($l) => ['code' => $l['account_code'], 'name' => $l['account_name'], 'debit' => $l['debit_cents'] / 100, 'credit' => $l['credit_cents'] / 100], $lines),
                'blockers' => $blockers, 'drafts' => $drafts];
    }

    private static function earlierUnclosed(PDO $db, int $companyId, string $start): bool
    {
        $st = $db->prepare("SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date < ? AND COALESCE(je.module,'') <> 'year_end' AND ga.account_type IN ('revenue','expense')
                               AND NOT EXISTS (SELECT 1 FROM journal_entries pc WHERE pc.company_id = je.company_id AND pc.module = 'year_end' AND pc.status = 'posted' AND pc.reversed_by_journal_id IS NULL
                                                  AND pc.reverses_journal_id IS NULL AND pc.entry_date >= je.entry_date AND pc.entry_date < ?)");
        $st->execute([$companyId, $start, $start]);
        return (int)$st->fetchColumn() > 0;
    }

    public static function closeYear(PDO $db, int $companyId, int $userId, string $role, string $start): int
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can close a year.');
        $s = Fin::ymd($start);
        if (!$s) throw new FinUserError('Choose a financial year.');
        $e = (new DateTimeImmutable($s))->modify('+1 year -1 day')->format('Y-m-d');
        self::checkYear($db, $companyId, $s, $e);
        [$re, $reName] = self::retainedCode($db, $companyId);
        $ref = 'YE-CLOSE-' . substr($s, 0, 4) . '-' . substr($e, 0, 4);
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $s, $e, $re, $reName, $ref) {
            $db->prepare("SELECT id FROM companies WHERE id = ? FOR UPDATE")->execute([$companyId]);
            $st = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND module = 'year_end' AND status = 'posted' AND reversed_by_journal_id IS NULL AND reverses_journal_id IS NULL AND entry_date BETWEEN ? AND ?");
            $st->execute([$companyId, $s, $e]);
            if ((int)$st->fetchColumn()) throw new FinUserError('This year is already closed (or overlaps a closed year — check the financial year start in settings).', 409);
            if ((new PeriodService($db, $companyId))->isLocked($e)) throw new FinUserError('The books are locked on ' . $e . '. Remove the lock first.', 409);
            if (self::earlierUnclosed($db, $companyId, $s)) throw new FinUserError('An earlier year has income or expenses that were never closed. Close earlier years first.', 409);
            [$lines, $net] = self::closingLines($db, $companyId, $s, $e, $re, $reName);
            if (!$lines) throw new FinUserError('There is no income or expense in this year to close.');
            $jl = array_map(fn($l) => ['account_code' => $l['account_code'], 'description' => $l['account_code'] === $re ? 'Net income transfer to Retained Earnings' : 'Close ' . $l['account_code'] . ' – ' . $l['account_name'],
                                       'debit' => $l['debit_cents'] / 100, 'credit' => $l['credit_cents'] / 100], $lines);
            $jid = (new PostingService($db, $companyId, $userId))->postAdHocJournal(['entry_date' => $e, 'reference' => $ref, 'description' => 'Year-end closing – ' . $s . ' to ' . $e, 'module' => 'year_end'], $jl);
            $db->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at, is_active) VALUES (?, ?, ?, ?, NOW(), 1)")
               ->execute([$companyId, $e, 'year_end_close_' . substr($e, 0, 4), $userId]);
            Fin::audit($db, $companyId, $userId, 'year_end_close', 'journal', $jid, ['fy_start' => $s, 'fy_end' => $e, 'net_income_cents' => $net, 'accounts_closed' => count($lines) - 1]);
            return $jid;
        });
    }

    /** Reopen a closed year: reverse its closing journal on the same date and lift the close's lock. */
    public static function reopenYear(PDO $db, int $companyId, int $userId, string $role, string $start, string $why): int
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can reopen a year.');
        $why = mb_substr(trim(strip_tags($why)), 0, 255);
        if (mb_strlen($why) < 3) throw new FinUserError('Say why the year is reopened.');
        $s = Fin::ymd($start);
        if (!$s) throw new FinUserError('Choose a financial year.');
        $e = (new DateTimeImmutable($s))->modify('+1 year -1 day')->format('Y-m-d');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $s, $e, $why) {
            $st = $db->prepare("SELECT id FROM journal_entries WHERE company_id = ? AND module = 'year_end' AND status = 'posted' AND reversed_by_journal_id IS NULL AND reverses_journal_id IS NULL AND entry_date BETWEEN ? AND ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $st->execute([$companyId, $s, $e]);
            $jid = (int)$st->fetchColumn();
            if (!$jid) throw new FinUserError('This year is not closed.', 409);
            $st = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND module = 'year_end' AND status = 'posted' AND reversed_by_journal_id IS NULL AND reverses_journal_id IS NULL AND entry_date > ?");
            $st->execute([$companyId, $e]);
            if ((int)$st->fetchColumn()) throw new FinUserError('A later year is closed too. Reopen the latest year first.', 409);
            $db->prepare("UPDATE gl_period_locks SET is_active = 0, deleted_at = NOW(), deleted_by = ? WHERE company_id = ? AND lock_reason = ? AND is_active = 1")
               ->execute([$userId, $companyId, 'year_end_close_' . substr($e, 0, 4)]);
            if ((new PeriodService($db, $companyId))->isLocked($e)) throw new FinUserError('Other locks (a later lock or a filed VAT return) still cover ' . $e . '. Remove those first.', 409);
            $rev = (new ReversalService($db, $companyId))->reverseJournal($jid, $userId, 'Year reopened: ' . $why, $e);
            Fin::audit($db, $companyId, $userId, 'year_end_reopened', 'journal', $jid, ['fy_start' => $s, 'fy_end' => $e, 'reversal_id' => $rev, 'why' => $why]);
            return $rev;
        });
    }

    // ------------------------------------------------------------ health

    /** Quick integrity checks; each says what is wrong and where to fix it. */
    public static function health(PDO $db, int $companyId): array
    {
        $one = function (string $sql, array $args) use ($db) { try { $st = $db->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); } catch (Throwable $e) { return -1; } };
        $c = $companyId;
        $out = [];
        $add = function (string $key, string $label, int $n, string $fix, string $href = '', string $tone = 'warn') use (&$out) {
            if ($n < 0) return;
            $out[] = ['key' => $key, 'label' => $label, 'count' => $n, 'ok' => $n === 0, 'fix' => $fix, 'href' => $href, 'tone' => $n === 0 ? 'ok' : $tone];
        };
        $add('unbalanced', 'Posted journals out of balance', $one("SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_id = je.id WHERE je.company_id = ? AND je.status = 'posted' GROUP BY je.id HAVING ROUND(SUM(jl.debit) * 100) <> ROUND(SUM(jl.credit) * 100)) x", [$c]),
             'Reverse and re-enter them; contact support if they came from a document.', '#journals?status=posted', 'danger');
        $add('orphan_codes', 'Journal lines on accounts missing from the chart', $one("SELECT COUNT(DISTINCT jl.account_code) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id LEFT JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id WHERE je.company_id = ? AND ga.account_id IS NULL", [$c]),
             'Add the missing accounts to the chart (with the same codes).', '#accounts', 'danger');
        $add('inv_unposted', 'Issued invoices not in the books', $one("SELECT COUNT(*) FROM invoices WHERE company_id = ? AND deleted_at IS NULL AND status NOT IN ('draft','cancelled') AND journal_id IS NULL", [$c]),
             'Open them in Quotes & Invoices; issuing posts them.', '/qi/#invoices', 'danger');
        $add('bill_unposted', 'Bills marked posted without a journal', $one("SELECT COUNT(*) FROM ap_bills WHERE company_id = ? AND status IN ('posted','paid') AND journal_id IS NULL", [$c]),
             'Re-post them from Payables.', '#payables?status=all', 'danger');
        $add('pay_unposted', 'Customer payments without a journal', $one("SELECT COUNT(*) FROM payments WHERE company_id = ? AND journal_id IS NULL AND refunded_at IS NULL", [$c]), 'Record them again from the invoice.', '#receivables');
        $add('appay_unposted', 'Supplier payments without a journal', $one("SELECT COUNT(*) FROM ap_payments WHERE company_id = ? AND journal_id IS NULL", [$c]), 'Record them again from Payables.', '#payables?view=payments');
        $add('bank_broken', 'Matched bank lines whose journal is missing', $one("SELECT COUNT(*) FROM gl_bank_transactions t LEFT JOIN journal_entries je ON je.id = t.journal_id AND je.company_id = t.company_id WHERE t.company_id = ? AND t.matched = 1 AND (t.journal_id IS NULL OR je.id IS NULL)", [$c]),
             'Undo the match and match the line again.', '#bank?status=matched', 'danger');
        $add('drafts_old', 'Draft journals older than 30 days', $one("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND status IN ('draft','prepared','approved') AND created_at < NOW() - INTERVAL 30 DAY", [$c]), 'Post or delete them.', '#journals?status=draft');
        $add('vat_late', 'VAT returns past their due date', $one("SELECT COUNT(*) FROM gl_vat_periods WHERE company_id = ? AND status IN ('open','prepared','adjusted') AND LAST_DAY(period_end + INTERVAL 1 MONTH) < CURDATE()", [$c]), 'Prepare and file them.', '#vat', 'danger');
        $add('untagged', 'Revenue lines without a VAT code (last 12 months)', $one("SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
                 WHERE je.company_id = ? AND je.status = 'posted' AND ga.account_type = 'revenue' AND jl.tax_code_id IS NULL AND COALESCE(je.module,'') <> 'year_end' AND je.entry_date >= CURDATE() - INTERVAL 12 MONTH", [$c]),
             'Check whether they were standard-rated, zero-rated or exempt; correct with a journal if needed.', '#reports?r=vat_summary');
        require_once __DIR__ . '/Tieout.php';
        try {
            $t = new Tieout($db, $companyId);
            $today = date('Y-m-d');
            $ar = round($t->glBalance('AR', $today) - $t->arSubledger($today), 2);
            $ap = round($t->glBalance('AP', $today) - $t->apSubledger($today), 2);
            $out[] = ['key' => 'tie_ar', 'label' => 'Receivables ledger against open invoices', 'count' => abs($ar) < 0.005 ? 0 : 1, 'ok' => abs($ar) < 0.005, 'fix' => abs($ar) < 0.005 ? '' : 'Differs by ' . Fin::money(abs($ar)) . '. A journal posted straight to the receivables account usually explains it.', 'href' => '#reports?r=tieouts', 'tone' => abs($ar) < 0.005 ? 'ok' : 'warn'];
            $out[] = ['key' => 'tie_ap', 'label' => 'Payables ledger against open bills', 'count' => abs($ap) < 0.005 ? 0 : 1, 'ok' => abs($ap) < 0.005, 'fix' => abs($ap) < 0.005 ? '' : 'Differs by ' . Fin::money(abs($ap)) . '.', 'href' => '#reports?r=tieouts', 'tone' => abs($ap) < 0.005 ? 'ok' : 'warn'];
        } catch (Throwable $e) {
            // tie-outs need the mappings
        }
        return ['checks' => $out, 'ok' => count(array_filter($out, fn($x) => !$x['ok'])) === 0];
    }
}
