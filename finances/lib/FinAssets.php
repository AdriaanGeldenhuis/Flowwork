<?php
// finances/lib/FinAssets.php
//
// The fixed asset register: assets with their cost, life and accounts, the
// monthly depreciation run, disposals, and a forward schedule.
//
// The depreciation and disposal sums are the old screen's, unchanged:
//   - straight line: floor((cost − salvage) / life months) a month, never
//     more than what is left;
//   - declining balance: floor(book value × 2 / life months), never more than
//     what is left;
//   - a month is run once (posted on the first of the month, as before), for
//     assets bought on or before that day, never for a future month;
//   - disposal: Dr bank (proceeds), Dr accumulated depreciation, Cr asset
//     cost, the difference to gain / loss on disposal; output VAT extracted
//     from the proceeds when the sale carries VAT.
// They are now worked in integer cents (the disposal used floats) so a
// disposal journal can never be a cent out of balance. Registering an asset
// posts nothing: the purchase is in the books through its bill or journal.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/AccountsMap.php';
require_once __DIR__ . '/PostingService.php';
require_once __DIR__ . '/PeriodService.php';
require_once __DIR__ . '/TaxCodes.php';

final class FinAssets
{
    public const METHODS = ['straight_line' => 'Straight line', 'declining_balance' => 'Declining balance (double)'];
    public const TAX_METHODS = ['none' => 'No tax allowance', 's11e' => 's11(e) wear and tear', 's12c' => 's12C manufacturing (40/20/20/20)'];

    public static function list(PDO $db, int $companyId, array $f = []): array
    {
        $where = ['a.company_id = ?'];
        $args = [$companyId];
        $status = (string)($f['status'] ?? 'active');
        if ($status === 'active') $where[] = "a.status = 'active'";
        elseif ($status === 'disposed') $where[] = "a.status <> 'active'";
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $where[] = '(a.asset_name LIKE ? OR a.category LIKE ?)'; $args[] = '%' . Fin::likeEscape($q) . '%'; $args[] = '%' . Fin::likeEscape($q) . '%'; }
        if (($c = trim((string)($f['category'] ?? ''))) !== '') { $where[] = 'a.category = ?'; $args[] = $c; }
        $st = $db->prepare("SELECT a.*, ga.account_code asset_code, ga.account_name asset_account FROM gl_fixed_assets a
                              LEFT JOIN gl_accounts ga ON ga.account_id = a.asset_account_id AND ga.company_id = a.company_id
                             WHERE " . implode(' AND ', $where) . " ORDER BY a.status = 'active' DESC, a.purchase_date DESC, a.asset_id DESC LIMIT 500");
        $st->execute($args);
        $rows = array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $db->prepare("SELECT SUM(status = 'active'), SUM(status <> 'active'), COUNT(*) FROM gl_fixed_assets WHERE company_id = ?");
        $st->execute([$companyId]);
        [$a, $d, $n] = $st->fetch(PDO::FETCH_NUM);
        $st = $db->prepare("SELECT DISTINCT category FROM gl_fixed_assets WHERE company_id = ? AND category IS NOT NULL AND category <> '' ORDER BY category");
        $st->execute([$companyId]);
        $tot = ['cost' => 0, 'accum' => 0, 'book' => 0, 'month' => 0];
        foreach ($rows as $r) if ($r['status'] === 'active') { $tot['cost'] += $r['cost']; $tot['accum'] += $r['accumulated']; $tot['book'] += $r['book']; $tot['month'] += $r['monthly']; }
        return ['rows' => $rows, 'counts' => ['active' => (int)$a, 'disposed' => (int)$d, 'all' => (int)$n], 'categories' => $st->fetchAll(PDO::FETCH_COLUMN),
                'totals' => array_map(fn($v) => round($v, 2), $tot), 'runs' => self::runs($db, $companyId, 6), 'next_month' => self::nextMonth($db, $companyId)];
    }

    private static function shape(array $a): array
    {
        $cost = (int)$a['purchase_cost_cents'];
        $acc = (int)$a['accumulated_depreciation_cents'];
        return [
            'id' => (int)$a['asset_id'], 'name' => (string)$a['asset_name'], 'category' => (string)($a['category'] ?? ''), 'purchased' => $a['purchase_date'],
            'cost' => $cost / 100, 'salvage' => (int)$a['salvage_value_cents'] / 100, 'life' => (int)$a['useful_life_months'], 'method' => (string)$a['depreciation_method'],
            'tax_method' => (string)($a['tax_method'] ?? 'none'), 'tax_years' => $a['tax_writeoff_years'] !== null ? (int)$a['tax_writeoff_years'] : null,
            'asset_account_id' => (int)$a['asset_account_id'], 'expense_account_id' => (int)$a['depreciation_expense_account_id'], 'accum_account_id' => (int)$a['accumulated_depreciation_account_id'],
            'asset_code' => (string)($a['asset_code'] ?? ''), 'accumulated' => $acc / 100, 'book' => ($cost - $acc) / 100,
            'monthly' => $a['status'] === 'active' ? self::monthlyCents($a) / 100 : 0,
            'status' => $a['status'] === 'active' ? ((self::monthlyCents($a) === 0 && $cost - (int)$a['salvage_value_cents'] > 0) ? 'fully_depreciated' : 'active') : 'disposed',
            'raw_status' => (string)$a['status'], 'disposed' => $a['disposal_date'], 'proceeds' => $a['disposal_proceeds_cents'] !== null ? (int)$a['disposal_proceeds_cents'] / 100 : null,
            'disposal_journal_id' => $a['disposal_journal_id'] ? (int)$a['disposal_journal_id'] : null,
        ];
    }

    /** This month's charge for an asset with the given accumulated depreciation (the old run's exact sum). */
    public static function monthlyCents(array $a, ?int $accum = null): int
    {
        $cost = (int)$a['purchase_cost_cents'];
        $salvage = (int)$a['salvage_value_cents'];
        $life = (int)$a['useful_life_months'];
        $accum = $accum ?? (int)$a['accumulated_depreciation_cents'];
        if ($life <= 0) return 0;
        $depreciable = $cost - $salvage;
        if ($depreciable <= 0) return 0;
        $remaining = $depreciable - $accum;
        if ($remaining <= 0) return 0;
        if ($a['depreciation_method'] === 'declining_balance') {
            $calc = max(0, (int)floor(($cost - $accum) * (2.0 / $life)));
            return min($calc, $remaining);
        }
        $monthly = (int)floor($depreciable / $life);
        if ($monthly <= 0) $monthly = $remaining;
        return min($monthly, $remaining);
    }

    public static function get(PDO $db, int $companyId, int $id): array
    {
        $st = $db->prepare("SELECT * FROM gl_fixed_assets WHERE asset_id = ? AND company_id = ?");
        $st->execute([$id, $companyId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new FinUserError('That asset was not found.');
        $st = $db->prepare("SELECT r.run_month, l.amount_cents, r.journal_id FROM fa_depreciation_lines l JOIN fa_depreciation_runs r ON r.id = l.run_id
                             WHERE l.asset_id = ? AND r.company_id = ? AND r.status = 'posted' ORDER BY r.run_month DESC LIMIT 120");
        $st->execute([$id, $companyId]);
        $hist = array_map(fn($r) => ['month' => $r['run_month'], 'amount' => (int)$r['amount_cents'] / 100, 'journal_id' => $r['journal_id'] ? (int)$r['journal_id'] : null], $st->fetchAll(PDO::FETCH_ASSOC));
        // forward schedule: the next 24 charges with the same sums
        $sched = [];
        if ($a['status'] === 'active') {
            $acc = (int)$a['accumulated_depreciation_cents'];
            $m = new DateTimeImmutable(self::nextMonth($db, $companyId) ?: date('Y-m-01'));
            $buy = new DateTimeImmutable(substr((string)$a['purchase_date'], 0, 7) . '-01');
            if ((string)$a['purchase_date'] > $buy->format('Y-m-d')) $buy = $buy->modify('+1 month');
            if ($m < $buy) $m = $buy;
            for ($i = 0; $i < 24; $i++) {
                $c = self::monthlyCents($a, $acc);
                if ($c <= 0) break;
                $acc += $c;
                $sched[] = ['month' => $m->format('Y-m-d'), 'amount' => $c / 100, 'book' => ((int)$a['purchase_cost_cents'] - $acc) / 100];
                $m = $m->modify('+1 month');
            }
        }
        $codes = [];
        foreach (['asset_account_id', 'depreciation_expense_account_id', 'accumulated_depreciation_account_id'] as $k) {
            $st = $db->prepare("SELECT account_code, account_name FROM gl_accounts WHERE account_id = ? AND company_id = ?");
            $st->execute([(int)$a[$k], $companyId]);
            $codes[$k] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        require_once __DIR__ . '/FinFiles.php';
        return ['asset' => self::shape($a), 'history' => $hist, 'schedule' => $sched, 'accounts' => $codes,
                'editable_money' => (int)$a['accumulated_depreciation_cents'] === 0 && !$hist && $a['status'] === 'active',
                'files' => FinFiles::list($db, $companyId, 'asset', $id)];
    }

    /** Create or edit an asset. Cost, life, method and accounts are fixed once it has been depreciated. */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $id = (int)($in['id'] ?? 0);
        $name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 255);
        if ($name === '') throw new FinUserError('Name the asset.');
        $cat = mb_substr(trim(strip_tags((string)($in['category'] ?? ''))), 0, 100);
        $date = Fin::ymd($in['purchased'] ?? '');
        if (!$date) throw new FinUserError('Choose the purchase date.');
        $cost = Fin::cents($in['cost'] ?? '');
        $salvage = Fin::cents($in['salvage'] ?? '0') ?? 0;
        $life = (int)($in['life'] ?? 0);
        $method = array_key_exists($in['method'] ?? '', self::METHODS) ? $in['method'] : 'straight_line';
        $tax = array_key_exists($in['tax_method'] ?? '', self::TAX_METHODS) ? $in['tax_method'] : 'none';
        $taxYears = $tax === 'none' ? null : ($tax === 's12c' ? 4 : (int)($in['tax_years'] ?? 0));
        if ($tax === 's11e' && ($taxYears < 1 || $taxYears > 50)) throw new FinUserError('Enter the SARS write-off period in years (1 to 50).');
        if ($cost === null || $cost <= 0) throw new FinUserError('The cost must be more than nil.');
        if ($salvage < 0 || $salvage > $cost) throw new FinUserError('The residual value is between nil and the cost.');
        if ($life < 1 || $life > 1200) throw new FinUserError('The useful life is 1 to 1200 months.');
        $acc = ['asset' => (int)($in['asset_account_id'] ?? 0), 'expense' => (int)($in['expense_account_id'] ?? 0), 'accum' => (int)($in['accum_account_id'] ?? 0)];
        $chk = $db->prepare("SELECT account_type, account_subtype, is_active FROM gl_accounts WHERE account_id = ? AND company_id = ?");
        foreach (['asset' => 'asset account', 'expense' => 'depreciation expense account', 'accum' => 'accumulated depreciation account'] as $k => $label) {
            $chk->execute([$acc[$k], $companyId]);
            $r = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$r || !(int)$r['is_active']) throw new FinUserError("Choose the $label.");
            if ($k === 'expense' && $r['account_type'] !== 'expense') throw new FinUserError('The depreciation account must be an expense account.');
            if ($k !== 'expense' && $r['account_type'] !== 'asset') throw new FinUserError("The $label must be an asset account.");
        }
        if ($acc['asset'] === $acc['accum']) throw new FinUserError('Cost and accumulated depreciation need different accounts.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $name, $cat, $date, $cost, $salvage, $life, $method, $tax, $taxYears, $acc) {
            if ($id) {
                $st = $db->prepare("SELECT * FROM gl_fixed_assets WHERE asset_id = ? AND company_id = ? FOR UPDATE");
                $st->execute([$id, $companyId]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new FinUserError('That asset was not found.');
                if ($old['status'] !== 'active') throw new FinUserError('A disposed asset cannot change.', 409);
                $st = $db->prepare("SELECT COUNT(*) FROM fa_depreciation_lines l JOIN fa_depreciation_runs r ON r.id = l.run_id WHERE l.asset_id = ? AND r.company_id = ?");
                $st->execute([$id, $companyId]);
                $used = (int)$st->fetchColumn() > 0 || (int)$old['accumulated_depreciation_cents'] !== 0;
                $moneyChanged = (int)$old['purchase_cost_cents'] !== $cost || (int)$old['salvage_value_cents'] !== $salvage || (int)$old['useful_life_months'] !== $life
                    || $old['depreciation_method'] !== $method || (int)$old['asset_account_id'] !== $acc['asset'] || (int)$old['depreciation_expense_account_id'] !== $acc['expense']
                    || (int)$old['accumulated_depreciation_account_id'] !== $acc['accum'] || $old['purchase_date'] !== $date;
                if ($used && $moneyChanged) throw new FinUserError('This asset has been depreciated, so its cost, date, life, method and accounts stay as they are. Dispose of it and register it again to start over.', 409);
                $db->prepare("UPDATE gl_fixed_assets SET asset_name = ?, category = ?, purchase_date = ?, purchase_cost_cents = ?, salvage_value_cents = ?, useful_life_months = ?, depreciation_method = ?,
                                     tax_method = ?, tax_writeoff_years = ?, asset_account_id = ?, depreciation_expense_account_id = ?, accumulated_depreciation_account_id = ?, updated_at = NOW()
                               WHERE asset_id = ? AND company_id = ?")
                   ->execute([$name, $cat ?: null, $date, $cost, $salvage, $life, $method, $tax, $taxYears, $acc['asset'], $acc['expense'], $acc['accum'], $id, $companyId]);
                Fin::audit($db, $companyId, $userId, 'fa_asset_updated', 'asset', $id, ['name' => $name]);
                return $id;
            }
            $db->prepare("INSERT INTO gl_fixed_assets (company_id, asset_name, category, purchase_date, purchase_cost_cents, salvage_value_cents, useful_life_months, depreciation_method,
                                 tax_method, tax_writeoff_years, asset_account_id, depreciation_expense_account_id, accumulated_depreciation_account_id, accumulated_depreciation_cents, status, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active', NOW())")
               ->execute([$companyId, $name, $cat ?: null, $date, $cost, $salvage, $life, $method, $tax, $taxYears, $acc['asset'], $acc['expense'], $acc['accum']]);
            $id = (int)$db->lastInsertId();
            Fin::audit($db, $companyId, $userId, 'fa_asset_created', 'asset', $id, ['name' => $name, 'cost_cents' => $cost]);
            return $id;
        });
    }

    public static function runs(PDO $db, int $companyId, int $limit = 24): array
    {
        $st = $db->prepare("SELECT r.id, r.run_month, r.status, r.journal_id, r.created_at, COALESCE(SUM(l.amount_cents), 0) total, COUNT(l.id) n
                              FROM fa_depreciation_runs r LEFT JOIN fa_depreciation_lines l ON l.run_id = r.id
                             WHERE r.company_id = ? GROUP BY r.id ORDER BY r.run_month DESC LIMIT " . max(1, min(240, $limit)));
        $st->execute([$companyId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'month' => $r['run_month'], 'status' => (string)$r['status'], 'journal_id' => $r['journal_id'] ? (int)$r['journal_id'] : null,
                                    'total' => (int)$r['total'] / 100, 'assets' => (int)$r['n'], 'at' => $r['created_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The first month not yet run, from the last posted run (or the oldest asset). */
    public static function nextMonth(PDO $db, int $companyId): ?string
    {
        $st = $db->prepare("SELECT MAX(run_month) FROM fa_depreciation_runs WHERE company_id = ? AND status = 'posted'");
        $st->execute([$companyId]);
        $last = $st->fetchColumn();
        if ($last) return (new DateTimeImmutable($last))->modify('first day of next month')->format('Y-m-d');
        $st = $db->prepare("SELECT MIN(purchase_date) FROM gl_fixed_assets WHERE company_id = ? AND status = 'active'");
        $st->execute([$companyId]);
        $first = $st->fetchColumn();
        if (!$first) return null;
        $m = new DateTimeImmutable(substr($first, 0, 7) . '-01');
        if ($first > $m->format('Y-m-d')) $m = $m->modify('+1 month');
        return $m->format('Y-m-d');
    }

    /** What a run for the month would post (nothing is written). */
    public static function preview(PDO $db, int $companyId, string $month): array
    {
        $m = self::monthStart($month);
        $st = $db->prepare("SELECT * FROM gl_fixed_assets WHERE company_id = ? AND status = 'active' AND purchase_date <= ? ORDER BY asset_name");
        $st->execute([$companyId, $m]);
        $lines = [];
        $total = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $c = self::monthlyCents($a);
            if ($c <= 0) continue;
            $lines[] = ['asset_id' => (int)$a['asset_id'], 'name' => (string)$a['asset_name'], 'amount' => $c / 100];
            $total += $c;
        }
        $st = $db->prepare("SELECT status FROM fa_depreciation_runs WHERE company_id = ? AND run_month = ?");
        $st->execute([$companyId, $m]);
        return ['month' => $m, 'lines' => $lines, 'total' => $total / 100, 'done' => $st->fetchColumn() === 'posted',
                'locked' => (new PeriodService($db, $companyId))->isLocked($m), 'future' => $m > date('Y-m-01')];
    }

    private static function monthStart(string $month): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})/', $month, $mm) || (int)$mm[2] < 1 || (int)$mm[2] > 12) throw new FinUserError('Choose the month to depreciate.');
        return $mm[1] . '-' . $mm[2] . '-01';
    }

    /** Run and post depreciation for a month (the old run, in one transaction). */
    public static function run(PDO $db, int $companyId, int $userId, string $role, string $month): array
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $m = self::monthStart($month);
        if ($m > date('Y-m-01')) throw new FinUserError('Depreciation cannot run for a future month.');
        if ((new PeriodService($db, $companyId))->isLocked($m)) throw new FinUserError('The books are locked on ' . $m . '.', 409);
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $m) {
            $st = $db->prepare("SELECT id, status FROM fa_depreciation_runs WHERE company_id = ? AND run_month = ? LIMIT 1 FOR UPDATE");
            $st->execute([$companyId, $m]);
            if ($ex = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($ex['status'] === 'posted') throw new FinUserError('Depreciation for ' . substr($m, 0, 7) . ' is already posted.', 409);
                $db->prepare("DELETE FROM fa_depreciation_lines WHERE run_id = ?")->execute([(int)$ex['id']]);
                $db->prepare("DELETE FROM fa_depreciation_runs WHERE id = ?")->execute([(int)$ex['id']]);
            }
            $st = $db->prepare("SELECT * FROM gl_fixed_assets WHERE company_id = ? AND status = 'active' AND purchase_date <= ? FOR UPDATE");
            $st->execute([$companyId, $m]);
            $lines = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $c = self::monthlyCents($a);
                if ($c > 0) $lines[(int)$a['asset_id']] = $c;
            }
            if (!$lines) throw new FinUserError('Nothing to depreciate for ' . substr($m, 0, 7) . '.');
            $db->prepare("INSERT INTO fa_depreciation_runs (company_id, run_month, status, created_by, created_at) VALUES (?, ?, 'draft', ?, NOW())")->execute([$companyId, $m, $userId]);
            $runId = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO fa_depreciation_lines (run_id, asset_id, amount_cents) VALUES (?, ?, ?)");
            foreach ($lines as $aid => $c) $ins->execute([$runId, $aid, $c]);
            (new PostingService($db, $companyId, $userId))->postDepreciation($runId);
            $upd = $db->prepare("UPDATE gl_fixed_assets SET accumulated_depreciation_cents = accumulated_depreciation_cents + ? WHERE asset_id = ? AND company_id = ?");
            foreach ($lines as $aid => $c) $upd->execute([$c, $aid, $companyId]);
            $total = array_sum($lines);
            Fin::audit($db, $companyId, $userId, 'fa_depreciation_run_posted', 'depreciation_run', $runId, ['month' => $m, 'total_cents' => $total, 'assets' => count($lines)]);
            return ['run_id' => $runId, 'total' => $total / 100, 'assets' => count($lines)];
        });
    }

    /** Dispose of (sell or scrap) an asset. $in: date, proceeds (incl. VAT when vat), vat (bool), bank_account_id?, notes */
    public static function dispose(PDO $db, int $companyId, int $userId, string $role, int $id, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $date = Fin::ymd($in['date'] ?? '');
        if (!$date) throw new FinUserError('Choose the disposal date.');
        $procC = Fin::cents($in['proceeds'] ?? '0') ?? 0;
        if ($procC < 0) throw new FinUserError('The proceeds cannot be negative.');
        $vat = !empty($in['vat']);
        $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000);
        $bank = (int)($in['bank_account_id'] ?? 0) ?: null;
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $date, $procC, $vat, $notes, $bank) {
            $st = $db->prepare("SELECT * FROM gl_fixed_assets WHERE asset_id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) throw new FinUserError('That asset was not found.');
            if ($a['status'] !== 'active') throw new FinUserError('This asset is already disposed of.', 409);
            if ($a['purchase_date'] && $date < $a['purchase_date']) throw new FinUserError('The disposal date is before the purchase date (' . $a['purchase_date'] . ').');
            $st = $db->prepare("SELECT MAX(r.run_month) FROM fa_depreciation_lines l JOIN fa_depreciation_runs r ON r.id = l.run_id WHERE l.asset_id = ? AND r.company_id = ? AND r.status = 'posted'");
            $st->execute([$id, $companyId]);
            $last = $st->fetchColumn();
            if ($last && $date < $last) throw new FinUserError('The disposal date is before the last depreciation (' . $last . ').');
            if ((new PeriodService($db, $companyId))->isLocked($date)) throw new FinUserError('The books are locked on ' . $date . '.', 409);
            $map = new AccountsMap($db, $companyId);
            $assetCode = $map->getById((int)$a['asset_account_id']);
            $accumCode = $map->getById((int)$a['accumulated_depreciation_account_id']);
            if (!$assetCode || !$accumCode) throw new FinUserError("The asset's accounts are missing from the chart.");
            $bankCode = $map->code('finance_bank_account_id');
            if ($bank) {
                $st = $db->prepare("SELECT ga.account_code FROM gl_bank_accounts ba JOIN gl_accounts ga ON ga.account_id = ba.gl_account_id AND ga.company_id = ba.company_id WHERE ba.id = ? AND ba.company_id = ? AND ba.is_active = 1");
                $st->execute([$bank, $companyId]);
                $bankCode = $st->fetchColumn() ?: null;
                if (!$bankCode) throw new FinUserError('Choose one of your bank accounts.');
            }
            $gain = $map->code('finance_gain_on_disposal_account_id');
            $loss = $map->code('finance_loss_on_disposal_account_id');
            if (!$gain || !$loss) throw new FinUserError('Choose the gain and loss on disposal accounts in Finance settings first.');
            $vatC = 0; $vatTax = null; $rate = 0.0;
            if ($vat && $procC > 0) {
                $tc = new TaxCodes($db, $companyId);
                $rate = $tc->standardRatePercent();
                $vatC = (int)round($procC * $rate / (100 + $rate));
                $vatTax = $tc->resolveOutputForLine(null, 'STD', $rate);
            }
            $costC = (int)$a['purchase_cost_cents'];
            $accC = (int)$a['accumulated_depreciation_cents'];
            $diff = ($procC - $vatC) - ($costC - $accC);
            $L = [];
            if ($procC > 0) $L[] = ['account_code' => $bankCode, 'description' => 'Proceeds from asset disposal', 'debit' => $procC / 100, 'credit' => 0];
            if ($accC > 0) $L[] = ['account_code' => $accumCode, 'description' => 'Reverse accumulated depreciation', 'debit' => $accC / 100, 'credit' => 0];
            if ($diff < 0) $L[] = ['account_code' => $loss, 'description' => 'Loss on disposal', 'debit' => -$diff / 100, 'credit' => 0];
            if ($costC > 0) $L[] = ['account_code' => $assetCode, 'description' => 'Remove asset cost', 'debit' => 0, 'credit' => $costC / 100];
            if ($diff > 0) $L[] = ['account_code' => $gain, 'description' => 'Gain on disposal', 'debit' => 0, 'credit' => $diff / 100];
            if ($vatC > 0) $L[] = ['account_code' => $map->code('finance_vat_output_account_id'), 'description' => 'VAT on disposal proceeds (' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%)',
                                   'debit' => 0, 'credit' => $vatC / 100, 'tax_code_id' => $vatTax];
            $jid = (new PostingService($db, $companyId, $userId))->postAdHocJournal([
                'entry_date' => $date, 'reference' => 'DISP' . $id, 'description' => 'Disposal of asset ' . $a['asset_name'], 'module' => 'fin',
                'ref_type' => 'fa_disposal', 'ref_id' => $id, 'source_type' => 'fa_disposal', 'source_id' => $id,
            ], $L);
            $st = $db->prepare("UPDATE gl_fixed_assets SET status = 'disposed', disposal_date = ?, disposal_proceeds_cents = ?, disposal_journal_id = ?, updated_at = NOW() WHERE asset_id = ? AND company_id = ? AND status = 'active'");
            $st->execute([$date, $procC, $jid, $id, $companyId]);
            if ($st->rowCount() !== 1) throw new FinUserError('This asset was disposed of meanwhile.', 409);
            $db->prepare("INSERT INTO fa_disposals (company_id, asset_id, disposal_date, proceeds_cents, notes, journal_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$companyId, $id, $date, $procC, $notes ?: null, $jid, $userId]);
            Fin::audit($db, $companyId, $userId, 'fa_asset_disposed', 'asset', $id, ['journal_id' => $jid, 'proceeds_cents' => $procC, 'vat_cents' => $vatC, 'date' => $date]);
            return $jid;
        });
    }

    /** Accounts the asset form offers: asset (fixed asset / accumulated) and expense. */
    public static function defaults(PDO $db, int $companyId): array
    {
        $st = $db->prepare("SELECT account_id, account_code, account_name, account_type, account_subtype FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND account_type IN ('asset','expense') ORDER BY account_code");
        $st->execute([$companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $pick = function (callable $f) use ($rows) { foreach ($rows as $r) if ($f($r)) return (int)$r['account_id']; return null; };
        return [
            'asset' => $pick(fn($r) => $r['account_subtype'] === 'fixed_asset' && !preg_match('/accum|depreci/i', $r['account_name'])),
            'accum' => $pick(fn($r) => preg_match('/accum/i', $r['account_name']) && $r['account_type'] === 'asset'),
            'expense' => $pick(fn($r) => $r['account_type'] === 'expense' && preg_match('/depreci/i', $r['account_name'])),
        ];
    }
}
