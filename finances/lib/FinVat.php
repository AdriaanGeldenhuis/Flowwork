<?php
// finances/lib/FinVat.php
//
// VAT periods and the VAT201 return: create a period, prepare it (snapshot
// the boxes), adjust it, file it (admin: settle output / input to VAT
// Control and lock the period), and record the payment to — or refund from —
// SARS. Every figure comes from VatCalculator::vat201Boxes, the one shared
// computation the old screen, the CSV audit file and the tests use.
//
// What changed from the old screen (shown and tested in
// tests/finance/test_fin_vat.php):
//   - A VAT adjustment that is not balanced by itself (e.g. +R100 output tax
//     for a change in use) was balanced to VAT Control. Filing then cleared
//     Box 5 / Box 9 into VAT Control as well, so VAT Control ended R100 away
//     from the Box 10 owed to SARS. The other side of an adjustment now goes
//     to an account the person chooses (the expense or asset the adjustment
//     is about) — never a VAT or other control account — so after filing VAT
//     Control equals Box 10 exactly. Old adjustments stay as they were posted;
//     the period screen shows how much of them went to VAT Control.
//   - Preparing no longer sets journal_entries.is_locked (a flag nothing
//     reads); the period lock is still placed when the return is filed.
//   - Paying SARS (or receiving a refund) is recorded against the filed
//     period (Dr VAT Control / Cr bank, module 'vat_pay'); the period then
//     reads 'paid' and the bank line matches the posting under "In the books".

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/AccountsMap.php';
require_once __DIR__ . '/VatCalculator.php';
require_once __DIR__ . '/PostingService.php';
require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/ReversalService.php';
require_once __DIR__ . '/FinOverview.php';

final class FinVat
{
    /** @return array{0:string,1:string,2:string} output, input, control account codes */
    public static function codes(PDO $db, int $companyId): array
    {
        $m = new AccountsMap($db, $companyId);
        $out = (string)$m->code('finance_vat_output_account_id');
        $in = (string)$m->code('finance_vat_input_account_id');
        $ctl = (string)$m->code('finance_vat_control_account_id');
        if ($out === '' || $in === '') throw new FinUserError('Choose the VAT output and input accounts in Finance settings first.');
        return [$out, $in, $ctl];
    }

    public static function boxes(PDO $db, int $companyId, string $start, string $end, string $basis): array
    {
        [$out, $in] = self::codes($db, $companyId);
        return VatCalculator::vat201Boxes($db, $companyId, $start, $end, $out, $in, $basis);
    }

    private static function row(PDO $db, int $companyId, int $id, bool $lock = false): array
    {
        $st = $db->prepare("SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id, $companyId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new FinUserError('That VAT period was not found.');
        return $p;
    }

    /** Every period, newest first, with live figures for those not filed yet. */
    public static function periods(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT p.*, u.first_name, u.last_name FROM gl_vat_periods p LEFT JOIN users u ON u.id = p.filed_by
                             WHERE p.company_id = ? ORDER BY p.period_end DESC LIMIT 120");
        $st->execute([$companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $paid = self::paidPerPeriod($db, $companyId);
        $out = [];
        foreach ($rows as $p) {
            $live = null;
            if (!in_array($p['status'], ['filed', 'paid'], true)) {
                try {
                    $b = self::boxes($db, $companyId, $p['period_start'], $p['period_end'], VatCalculator::periodBasis($db, $companyId, $p));
                    $live = ['output' => $b['box5_total_output_cents'] / 100, 'input' => $b['box9_total_input_cents'] / 100, 'net' => $b['box10_net_cents'] / 100];
                } catch (FinUserError $e) {
                    $live = null;
                }
            }
            $out[] = self::shape($p, $live, $paid[(int)$p['id']] ?? 0);
        }
        return ['rows' => $out, 'suggest' => self::suggest($rows), 'basis' => VatCalculator::companyBasis($db, $companyId), 'today' => date('Y-m-d')];
    }

    private static function shape(array $p, ?array $live, int $paidC): array
    {
        $stored = ['output' => (int)$p['output_vat_cents'] / 100, 'input' => (int)$p['input_vat_cents'] / 100, 'net' => (int)$p['net_vat_cents'] / 100];
        $prepared = in_array($p['status'], ['prepared', 'adjusted'], true);
        $netC = (int)$p['net_vat_cents'];
        return [
            'id' => (int)$p['id'], 'start' => $p['period_start'], 'end' => $p['period_end'], 'status' => (string)$p['status'], 'basis' => (string)($p['basis'] ?: 'invoice'),
            'stored' => $stored, 'live' => $live,
            'changed' => $prepared && $live !== null && abs($live['net'] - $stored['net']) >= 0.005,
            'figures' => $live ?? $stored,
            'due' => FinOverview::vatDueDate($p['period_end']),
            'late' => in_array($p['status'], ['open', 'prepared', 'adjusted'], true) && FinOverview::vatDueDate($p['period_end']) < date('Y-m-d'),
            'filed_at' => $p['filed_at'], 'filed_by' => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
            'prepared_at' => $p['prepared_at'],
            'paid' => $paidC / 100, 'to_settle' => in_array($p['status'], ['filed', 'paid'], true) ? (abs($netC) - $paidC) / 100 : null,
            'notes' => (string)($p['notes'] ?? ''),
        ];
    }

    /** Settled to / from SARS per period (posted, not reversed), cents. */
    private static function paidPerPeriod(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT je.ref_id, SUM(jl.debit + jl.credit) / 2 amt FROM journal_entries je JOIN journal_lines jl ON jl.journal_id = je.id
                             WHERE je.company_id = ? AND je.module = 'vat_pay' AND je.ref_type = 'vat_period' AND je.status = 'posted' AND je.reversed_by_journal_id IS NULL
                          GROUP BY je.ref_id");
        $st->execute([$companyId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['ref_id']] = (int)round((float)$r['amt'] * 100);
        return $out;
    }

    /** The next period: right after the latest one, the same number of months. */
    private static function suggest(array $rows): array
    {
        if (!$rows) {
            $start = new DateTimeImmutable(date('Y-m-01', strtotime('first day of last month')));
            return ['start' => $start->format('Y-m-d'), 'end' => $start->modify('last day of this month')->format('Y-m-d'), 'months' => 1];
        }
        $last = $rows[0];
        $s = new DateTimeImmutable($last['period_start']);
        $e = new DateTimeImmutable($last['period_end']);
        $months = max(1, ((int)$e->format('Y') - (int)$s->format('Y')) * 12 + (int)$e->format('n') - (int)$s->format('n') + 1);
        $ns = $e->modify('+1 day');
        $ne = $ns->modify('+' . ($months - 1) . ' months')->modify('last day of this month');
        return ['start' => $ns->format('Y-m-d'), 'end' => $ne->format('Y-m-d'), 'months' => $months];
    }

    /** One period: the VAT201 boxes, adjustments, settlement and payments. */
    public static function period(PDO $db, int $companyId, int $id): array
    {
        $p = self::row($db, $companyId, $id);
        $basis = VatCalculator::periodBasis($db, $companyId, $p);
        [$outCode, $inCode, $ctl] = self::codes($db, $companyId);
        $b = VatCalculator::vat201Boxes($db, $companyId, $p['period_start'], $p['period_end'], $outCode, $inCode, $basis);
        $j = $db->prepare("SELECT je.id, je.entry_date, je.reference, je.description, je.module, je.status, je.reversed_by_journal_id, je.reverses_journal_id,
                                  (SELECT SUM(debit) FROM journal_lines WHERE journal_id = je.id) amt
                             FROM journal_entries je
                            WHERE je.company_id = ? AND ((je.module IN ('vat_adjust','vat_pay') AND je.ref_type = 'vat_period' AND je.ref_id = ?) OR (je.module = 'vat_settle' AND je.reference = ?))
                            ORDER BY je.id");
        $j->execute([$companyId, $id, 'VAT-' . $id]);
        $journals = array_map(fn($r) => ['id' => (int)$r['id'], 'date' => $r['entry_date'], 'reference' => (string)$r['reference'], 'description' => (string)$r['description'],
                                         'kind' => ['vat_adjust' => 'adjustment', 'vat_settle' => 'settlement', 'vat_pay' => 'payment'][$r['module']] ?? 'other',
                                         'amount' => round((float)$r['amt'], 2), 'reversed' => !empty($r['reversed_by_journal_id']), 'reversal' => !empty($r['reverses_journal_id'])],
                                $j->fetchAll(PDO::FETCH_ASSOC));
        // Old adjustments whose other side went to VAT Control (they make VAT Control differ from Box 10).
        $legacy = 0;
        if ($ctl !== '') {
            $st = $db->prepare("SELECT COALESCE(SUM(jl.debit - jl.credit), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                                 WHERE je.company_id = ? AND je.status = 'posted' AND je.module = 'vat_adjust' AND je.ref_type = 'vat_period' AND je.ref_id = ? AND jl.account_code = ?");
            $st->execute([$companyId, $id, $ctl]);
            $legacy = (int)round((float)$st->fetchColumn() * 100);
        }
        $st = $db->prepare("SELECT name, vat_number, reg_number FROM companies WHERE id = ?");
        $st->execute([$companyId]);
        $co = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $paid = self::paidPerPeriod($db, $companyId)[$id] ?? 0;
        $live = ['output' => $b['box5_total_output_cents'] / 100, 'input' => $b['box9_total_input_cents'] / 100, 'net' => $b['box10_net_cents'] / 100];
        $filed = in_array($p['status'], ['filed', 'paid'], true);
        return [
            'period' => self::shape($p, $filed ? $live : $live, $paid) + ['filed_differs' => $filed && abs($live['net'] - (int)$p['net_vat_cents'] / 100) >= 0.005],
            'boxes' => self::boxList($b), 'raw' => $b, 'basis' => $basis,
            'company' => ['name' => (string)($co['name'] ?? ''), 'vat_number' => (string)($co['vat_number'] ?? ''), 'reg_number' => (string)($co['reg_number'] ?? '')],
            'journals' => $journals, 'legacy_control_cents' => $legacy,
            'locked' => (new PeriodService($db, $companyId))->isLocked($p['period_end']),
            'earlier_open' => self::earlierOpen($db, $companyId, $p['period_end']),
        ];
    }

    /** The VAT201 as SARS lays it out (rand). */
    public static function boxList(array $b): array
    {
        $r = fn($c) => round(((int)$c) / 100, 2);
        return [
            ['box' => '1', 'label' => 'Standard-rated supplies (excl. VAT)', 'value' => $r($b['output_standard_base_cents'] ?? 0), 'side' => 'output'],
            ['box' => '1A', 'label' => 'Output tax on standard-rated supplies', 'value' => $r($b['output_standard_vat_cents'] ?? 0), 'side' => 'output', 'vat' => true],
            ['box' => '2', 'label' => 'Zero-rated supplies', 'value' => $r($b['output_zero_base_cents'] ?? 0), 'side' => 'output'],
            ['box' => '3', 'label' => 'Exempt and non-supplies', 'value' => $r($b['output_exempt_base_cents'] ?? 0), 'side' => 'output'],
            ['box' => '4', 'label' => 'Change in use and adjustments (output)', 'value' => $r($b['change_in_use_output_cents'] ?? 0), 'side' => 'output', 'vat' => true],
            ['box' => '5', 'label' => 'Total output tax', 'value' => $r($b['box5_total_output_cents'] ?? 0), 'side' => 'output', 'vat' => true, 'total' => true],
            ['box' => '6', 'label' => 'Change in use and adjustments (input)', 'value' => $r($b['change_in_use_input_cents'] ?? 0), 'side' => 'input', 'vat' => true],
            ['box' => '7', 'label' => 'Capital goods and services', 'value' => $r($b['input_capital_cents'] ?? 0), 'side' => 'input', 'vat' => true],
            ['box' => '8', 'label' => 'Other goods and services', 'value' => $r($b['input_other_cents'] ?? 0), 'side' => 'input', 'vat' => true],
            ['box' => '8A', 'label' => 'Bad debts (s22 relief)', 'value' => $r($b['bad_debt_relief_input_cents'] ?? 0), 'side' => 'input', 'vat' => true],
            ['box' => '9', 'label' => 'Total input tax', 'value' => $r($b['box9_total_input_cents'] ?? 0), 'side' => 'input', 'vat' => true, 'total' => true],
            ['box' => '10', 'label' => ($b['box10_net_cents'] ?? 0) < 0 ? 'Refundable by SARS' : 'Payable to SARS', 'value' => $r($b['box10_net_cents'] ?? 0), 'side' => 'net', 'vat' => true, 'total' => true],
            ['box' => '—', 'label' => 'Revenue without a VAT code (check it)', 'value' => $r($b['untagged_revenue_base_cents'] ?? 0), 'side' => 'check'],
        ];
    }

    private static function earlierOpen(PDO $db, int $companyId, string $end): ?string
    {
        $st = $db->prepare("SELECT period_end FROM gl_vat_periods WHERE company_id = ? AND period_end < ? AND status NOT IN ('filed','paid') ORDER BY period_end LIMIT 1");
        $st->execute([$companyId, $end]);
        return $st->fetchColumn() ?: null;
    }

    // ------------------------------------------------------------- writes

    public static function create(PDO $db, int $companyId, int $userId, string $role, string $start, string $end): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $s = Fin::ymd($start);
        $e = Fin::ymd($end);
        if (!$s || !$e) throw new FinUserError('Choose the first and last day of the period.');
        if ($e < $s) throw new FinUserError('The period ends before it starts.');
        if ((new DateTimeImmutable($s))->modify('+12 months') <= new DateTimeImmutable($e)) throw new FinUserError('A VAT period is at most twelve months.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $s, $e) {
            $st = $db->prepare("SELECT period_start, period_end FROM gl_vat_periods WHERE company_id = ? AND period_start <= ? AND period_end >= ? LIMIT 1 FOR UPDATE");
            $st->execute([$companyId, $e, $s]);
            if ($o = $st->fetch(PDO::FETCH_ASSOC)) throw new FinUserError('This overlaps the VAT period ' . $o['period_start'] . ' to ' . $o['period_end'] . '.');
            $db->prepare("INSERT INTO gl_vat_periods (company_id, period_start, period_end, status, basis, output_vat_cents, input_vat_cents, net_vat_cents, created_by, created_at)
                          VALUES (?, ?, ?, 'open', ?, 0, 0, 0, ?, NOW())")
               ->execute([$companyId, $s, $e, VatCalculator::companyBasis($db, $companyId), $userId]);
            $id = (int)$db->lastInsertId();
            Fin::audit($db, $companyId, $userId, 'vat_period_created', 'vat_period', $id, ['start' => $s, 'end' => $e]);
            return $id;
        });
    }

    /** An open period with nothing posted against it can be removed (a wrong date range). */
    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $p = self::row($db, $companyId, $id, true);
            if ($p['status'] !== 'open') throw new FinUserError('Only an open period can be removed.', 409);
            $st = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND ref_type = 'vat_period' AND ref_id = ?");
            $st->execute([$companyId, $id]);
            if ((int)$st->fetchColumn()) throw new FinUserError('Something is posted against this period, so it stays.', 409);
            $db->prepare("DELETE FROM gl_vat_periods WHERE id = ? AND company_id = ? AND status = 'open'")->execute([$id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'vat_period_deleted', 'vat_period', $id, ['start' => $p['period_start'], 'end' => $p['period_end']]);
        });
    }

    /** open → prepared: snapshot Box 5 / 9 / 10 on the company's basis. */
    public static function prepare(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $p = self::row($db, $companyId, $id, true);
            if ($p['status'] !== 'open') throw new FinUserError('This period is already ' . $p['status'] . '.', 409);
            if ((new PeriodService($db, $companyId))->isLocked($p['period_end'])) throw new FinUserError('The books are locked on ' . $p['period_end'] . ', so this period cannot be prepared.', 409);
            $basis = VatCalculator::companyBasis($db, $companyId);
            $b = self::boxes($db, $companyId, $p['period_start'], $p['period_end'], $basis);
            $st = $db->prepare("UPDATE gl_vat_periods SET output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?, basis = ?, status = 'prepared', prepared_by = ?, prepared_at = NOW()
                                 WHERE id = ? AND company_id = ? AND status = 'open'");
            $st->execute([$b['box5_total_output_cents'], $b['box9_total_input_cents'], $b['box10_net_cents'], $basis, $userId, $id, $companyId]);
            if ($st->rowCount() !== 1) throw new FinUserError('The period is no longer open — refresh and try again.', 409);
            Fin::audit($db, $companyId, $userId, 'vat_period_prepared', 'vat_period', $id,
                ['output_vat_cents' => $b['box5_total_output_cents'], 'input_vat_cents' => $b['box9_total_input_cents'], 'net_vat_cents' => $b['box10_net_cents'], 'basis' => $basis]);
        });
    }

    /** prepared → open (before any adjustment), e.g. to pick up late documents on a new basis. */
    public static function reopen(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $p = self::row($db, $companyId, $id, true);
            if ($p['status'] !== 'prepared') throw new FinUserError($p['status'] === 'adjusted' ? 'This period has adjustments; it stays prepared. Undo the adjustments first.' : 'Only a prepared period can go back to open.', 409);
            $st = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND module = 'vat_adjust' AND ref_type = 'vat_period' AND ref_id = ? AND status = 'posted' AND reversed_by_journal_id IS NULL AND reverses_journal_id IS NULL");
            $st->execute([$companyId, $id]);
            if ((int)$st->fetchColumn()) throw new FinUserError('This period has adjustments. Undo them first.', 409);
            $db->prepare("UPDATE gl_vat_periods SET status = 'open', prepared_by = NULL, prepared_at = NULL WHERE id = ? AND company_id = ? AND status = 'prepared'")->execute([$id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'vat_period_reopened', 'vat_period', $id, []);
        });
    }

    /**
     * Post a VAT201 adjustment on the period's last day. $lines: [{side: output|input, amount (+ raises that side), memo}].
     * When the lines do not balance each other, the difference goes to $contraAccountId: an active, non-control,
     * non-VAT account (the expense, asset or income the adjustment is about) — never VAT Control.
     */
    public static function adjust(PDO $db, int $companyId, int $userId, string $role, int $id, array $lines, int $contraAccountId, string $key = '', string $memo = ''): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        [$outCode, $inCode, $ctlCode] = self::codes($db, $companyId);
        $key = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $key), 0, 40);
        $memo = mb_substr(trim(strip_tags($memo)), 0, 200);
        $jl = [];
        $dC = 0; $cC = 0;
        foreach (array_values($lines) as $i => $l) {
            if (!is_array($l)) continue;
            $side = strtolower((string)($l['side'] ?? $l['account'] ?? ''));
            $amtC = Fin::cents($l['amount'] ?? '');
            if ($amtC === null || $amtC === 0) continue;
            if (!in_array($side, ['output', 'input'], true)) throw new FinUserError('Line ' . ($i + 1) . ': choose output or input tax.');
            if (abs($amtC) > 100000000000) throw new FinUserError('Line ' . ($i + 1) . ': that amount is too large.');
            $text = mb_substr(trim(strip_tags((string)($l['memo'] ?? ''))), 0, 200) ?: ($side === 'output' ? 'Output tax adjustment' : 'Input tax adjustment');
            // output increase = credit VAT output; input increase = debit VAT input
            $debit = $side === 'output' ? $amtC < 0 : $amtC > 0;
            $abs = abs($amtC);
            $jl[] = ['account_code' => $side === 'output' ? $outCode : $inCode, 'description' => $text, 'debit' => $debit ? $abs / 100 : 0, 'credit' => $debit ? 0 : $abs / 100];
            if ($debit) $dC += $abs; else $cC += $abs;
        }
        if (!$jl) throw new FinUserError('Enter at least one adjustment amount.');
        $diff = $dC - $cC;
        if ($diff !== 0) {
            if (!$contraAccountId) throw new FinUserError('Choose the account for the other side of the adjustment (the expense, asset or income it relates to).');
            $st = $db->prepare("SELECT account_code, account_name, is_active, is_control, account_subtype FROM gl_accounts WHERE account_id = ? AND company_id = ?");
            $st->execute([$contraAccountId, $companyId]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a || !(int)$a['is_active']) throw new FinUserError('Choose an active account for the other side.');
            if ((int)$a['is_control'] || in_array($a['account_code'], [$outCode, $inCode, $ctlCode], true) || $a['account_subtype'] === 'vat_control') {
                throw new FinUserError('The other side cannot be a VAT or control account — otherwise VAT Control no longer equals what is owed to SARS. Choose the expense, asset or income the adjustment is about.');
            }
            $jl[] = ['account_code' => $a['account_code'], 'description' => $memo ?: 'VAT adjustment', 'debit' => $diff < 0 ? -$diff / 100 : 0, 'credit' => $diff > 0 ? $diff / 100 : 0];
        }
        if (count($jl) < 2) throw new FinUserError('An adjustment needs two sides — add the other account.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $jl, $key, $memo, $outCode, $inCode) {
            $p = self::row($db, $companyId, $id, true);
            if (!in_array($p['status'], ['prepared', 'adjusted'], true)) throw new FinUserError('Prepare the return before adjusting it.', 409);
            $reference = $key !== '' ? 'VAT Adj ' . $key : 'VAT Adjustment';
            if ($key !== '') {
                $st = $db->prepare("SELECT id FROM journal_entries WHERE company_id = ? AND module = 'vat_adjust' AND ref_type = 'vat_period' AND ref_id = ? AND reference = ? AND reversed_by_journal_id IS NULL LIMIT 1");
                $st->execute([$companyId, $id, $reference]);
                if ($dup = $st->fetchColumn()) return (int)$dup;
            }
            $jid = (new PostingService($db, $companyId, $userId))->postAdHocJournal([
                'entry_date' => $p['period_end'], 'reference' => $reference,
                'description' => $memo ?: 'VAT adjustment for ' . $p['period_start'] . ' to ' . $p['period_end'],
                'module' => 'vat_adjust', 'ref_type' => 'vat_period', 'ref_id' => $id, 'source_type' => 'vat_adjustment', 'source_id' => $id,
            ], $jl);
            self::refreshTotals($db, $companyId, $p, 'adjusted');
            Fin::audit($db, $companyId, $userId, 'vat_adjustment', 'vat_period', $id, ['journal_id' => $jid, 'lines' => $jl]);
            return $jid;
        });
    }

    /** Undo an adjustment before the return is filed (posts its reversal on the same date). */
    public static function undoAdjustment(PDO $db, int $companyId, int $userId, string $role, int $id, int $journalId): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $journalId) {
            $p = self::row($db, $companyId, $id, true);
            if (!in_array($p['status'], ['prepared', 'adjusted'], true)) throw new FinUserError('A filed return cannot change. Adjust the next period instead.', 409);
            $st = $db->prepare("SELECT id FROM journal_entries WHERE id = ? AND company_id = ? AND module = 'vat_adjust' AND ref_type = 'vat_period' AND ref_id = ? AND status = 'posted' AND reversed_by_journal_id IS NULL AND reverses_journal_id IS NULL");
            $st->execute([$journalId, $companyId, $id]);
            if (!$st->fetchColumn()) throw new FinUserError('That adjustment was not found or is already undone.');
            (new ReversalService($db, $companyId))->reverseJournal($journalId, $userId, 'VAT adjustment undone', $p['period_end']);
            self::refreshTotals($db, $companyId, $p, 'adjusted');
            Fin::audit($db, $companyId, $userId, 'vat_adjustment_undone', 'vat_period', $id, ['journal_id' => $journalId]);
        });
    }

    private static function refreshTotals(PDO $db, int $companyId, array $p, string $status): void
    {
        $b = self::boxes($db, $companyId, $p['period_start'], $p['period_end'], VatCalculator::periodBasis($db, $companyId, $p));
        $db->prepare("UPDATE gl_vat_periods SET status = ?, output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
           ->execute([$status, $b['box5_total_output_cents'], $b['box9_total_input_cents'], $b['box10_net_cents'], (int)$p['id'], $companyId]);
    }

    /**
     * File the return (administrators): periods in date order, totals recomputed, output and input VAT
     * cleared to VAT Control (module vat_settle), and the books locked through the period end.
     */
    public static function file(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can file a VAT return.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $p = self::row($db, $companyId, $id, true);
            if (!in_array($p['status'], ['prepared', 'adjusted'], true)) throw new FinUserError($p['status'] === 'open' ? 'Prepare the return before filing it.' : 'This return is already filed.', 409);
            if ($p['period_end'] >= date('Y-m-d')) throw new FinUserError('This period ends on ' . $p['period_end'] . '; file the return once it has ended.', 409);
            if ($earlier = self::earlierOpen($db, $companyId, $p['period_end'])) {
                throw new FinUserError('File the VAT period ending ' . $earlier . ' first — returns are filed in date order.', 409);
            }
            $b = self::boxes($db, $companyId, $p['period_start'], $p['period_end'], VatCalculator::periodBasis($db, $companyId, $p));
            $st = $db->prepare("UPDATE gl_vat_periods SET status = 'filed', filed_by = ?, filed_at = NOW(), output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?
                                 WHERE id = ? AND company_id = ? AND status IN ('prepared','adjusted')");
            $st->execute([$userId, $b['box5_total_output_cents'], $b['box9_total_input_cents'], $b['box10_net_cents'], $id, $companyId]);
            if ($st->rowCount() !== 1) throw new FinUserError('This return was filed meanwhile — refresh.', 409);
            if ($b['box5_total_output_cents'] !== 0 || $b['box9_total_input_cents'] !== 0) {
                (new PostingService($db, $companyId, $userId))->postVatAdjustment($b['box5_total_output_cents'] / 100, $b['box9_total_input_cents'] / 100, $p['period_end'], 'VAT-' . $id);
            }
            $st = $db->prepare("SELECT 1 FROM gl_period_locks WHERE company_id = ? AND lock_date >= ? AND is_active = 1 LIMIT 1");
            $st->execute([$companyId, $p['period_end']]);
            if (!$st->fetchColumn()) {
                $db->prepare("INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at) VALUES (?, ?, 'vat_period_filed', ?, NOW())")
                   ->execute([$companyId, $p['period_end'], $userId]);
            }
            Fin::audit($db, $companyId, $userId, 'vat_period_filed', 'vat_period', $id,
                ['output_vat_cents' => $b['box5_total_output_cents'], 'input_vat_cents' => $b['box9_total_input_cents'], 'net_vat_cents' => $b['box10_net_cents']]);
        });
    }

    /**
     * Record the payment to SARS (Box 10 payable) or the refund from SARS (Box 10 refundable) for a filed period.
     * $in: bank_account_id, date, amount? (default: what is left), reference?, key
     */
    public static function pay(PDO $db, int $companyId, int $userId, string $role, int $id, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $date = Fin::ymd($in['date'] ?? '');
        if (!$date) throw new FinUserError('Choose the date of the payment.');
        if ($date > date('Y-m-d', strtotime('+1 day'))) throw new FinUserError('A payment cannot be dated in the future.');
        $bank = (int)($in['bank_account_id'] ?? 0);
        $ref = mb_substr(trim(strip_tags((string)($in['reference'] ?? ''))), 0, 100);
        $key = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['key'] ?? '')), 0, 40);
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $date, $bank, $ref, $key, $in) {
            $p = self::row($db, $companyId, $id, true);
            if (!in_array($p['status'], ['filed', 'paid'], true)) throw new FinUserError('File the return before recording the payment.', 409);
            if ($key !== '') {
                $st = $db->prepare("SELECT id FROM journal_entries WHERE company_id = ? AND module = 'vat_pay' AND ref_type = 'vat_period' AND ref_id = ? AND source_id = ? LIMIT 1");
                $st->execute([$companyId, $id, crc32($key)]);
                if ($dup = $st->fetchColumn()) return (int)$dup;
            }
            $netC = (int)$p['net_vat_cents'];
            if ($netC === 0) throw new FinUserError('Nothing is payable or refundable for this period.');
            $leftC = abs($netC) - (self::paidPerPeriod($db, $companyId)[$id] ?? 0);
            if ($leftC <= 0) throw new FinUserError('This period is already settled.', 409);
            $amtC = ($in['amount'] ?? '') === '' ? $leftC : Fin::cents($in['amount']);
            if ($amtC === null || $amtC <= 0) throw new FinUserError('Enter the amount.');
            if ($amtC > $leftC) throw new FinUserError('That is more than the ' . Fin::money($leftC / 100) . ' left on this period. Penalties and interest are separate expenses.');
            $st = $db->prepare("SELECT ga.account_code, ba.name FROM gl_bank_accounts ba JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id
                                 WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1");
            $st->execute([$bank, $companyId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) throw new FinUserError('Choose the bank account the money went out of (or came into).');
            [, , $ctl] = self::codes($db, $companyId);
            if ($ctl === '') throw new FinUserError('Choose the VAT Control account in Finance settings first.');
            $refund = $netC < 0;
            $lines = [
                ['account_code' => $ctl, 'description' => $refund ? 'VAT refund from SARS' : 'VAT paid to SARS', 'debit' => $refund ? 0 : $amtC / 100, 'credit' => $refund ? $amtC / 100 : 0],
                ['account_code' => $b['account_code'], 'description' => $refund ? 'VAT refund from SARS' : 'VAT paid to SARS', 'debit' => $refund ? $amtC / 100 : 0, 'credit' => $refund ? 0 : $amtC / 100],
            ];
            $jid = (new PostingService($db, $companyId, $userId))->postAdHocJournal([
                'entry_date' => $date, 'reference' => $ref !== '' ? $ref : 'SARS VAT ' . substr($p['period_end'], 0, 7),
                'description' => ($refund ? 'VAT refund' : 'VAT payment') . ' for ' . $p['period_start'] . ' to ' . $p['period_end'],
                'module' => 'vat_pay', 'ref_type' => 'vat_period', 'ref_id' => $id, 'source_type' => 'vat_payment', 'source_id' => $key !== '' ? crc32($key) : null,
            ], $lines);
            if ($amtC === $leftC) $db->prepare("UPDATE gl_vat_periods SET status = 'paid', updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
            Fin::audit($db, $companyId, $userId, $refund ? 'vat_refund_recorded' : 'vat_payment_recorded', 'vat_period', $id, ['journal_id' => $jid, 'amount_cents' => $amtC, 'bank' => $b['name']]);
            return $jid;
        });
    }

    /** Undo a payment / refund recorded in error (reversed, period back to filed). */
    public static function undoPayment(PDO $db, int $companyId, int $userId, string $role, int $id, int $journalId): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $journalId) {
            self::row($db, $companyId, $id, true);
            $st = $db->prepare("SELECT entry_date FROM journal_entries WHERE id = ? AND company_id = ? AND module = 'vat_pay' AND ref_type = 'vat_period' AND ref_id = ? AND status = 'posted' AND reversed_by_journal_id IS NULL");
            $st->execute([$journalId, $companyId, $id]);
            $d = $st->fetchColumn();
            if (!$d) throw new FinUserError('That payment was not found or is already undone.');
            $st = $db->prepare("SELECT COUNT(*) FROM gl_bank_transactions WHERE company_id = ? AND journal_id = ?");
            $st->execute([$companyId, $journalId]);
            if ((int)$st->fetchColumn()) throw new FinUserError('A bank line is matched to this payment. Undo that match in Bank first.', 409);
            $date = (new PeriodService($db, $companyId))->isLocked($d) ? date('Y-m-d') : $d;
            (new ReversalService($db, $companyId))->reverseJournal($journalId, $userId, 'VAT payment undone', $date);
            $db->prepare("UPDATE gl_vat_periods SET status = 'filed', updated_at = NOW() WHERE id = ? AND company_id = ? AND status = 'paid'")->execute([$id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'vat_payment_undone', 'vat_period', $id, ['journal_id' => $journalId]);
        });
    }

    public static function saveNotes(PDO $db, int $companyId, int $userId, string $role, int $id, string $notes): void
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        self::row($db, $companyId, $id);
        $db->prepare("UPDATE gl_vat_periods SET notes = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")->execute([mb_substr(trim($notes), 0, 2000) ?: null, $id, $companyId]);
    }
}
