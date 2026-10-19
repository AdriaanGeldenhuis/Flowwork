<?php
// finances/lib/FinAccounts.php
//
// The chart of accounts: the tree with real balances, add / edit / switch
// off / delete, and CSV import.
//
// Balances are honest: a balance-sheet account shows everything posted up to
// the date (not only this year's movement), an income or expense account
// shows the financial year to date; both show the movement in the chosen
// period. Rules that protect the books:
//   - codes are digits in the type's band (1xxx assets … 5xxx-9xxx expenses),
//     optionally with a short suffix (1010-A); names are plain text,
//   - code, type, subtype and normal balance freeze once any journal line
//     uses the account; system accounts never change them,
//   - an account is switched off only with a zero balance, no unposted
//     journals on it, no sub-accounts still on, and nothing mapped to it
//     (Finance settings, bank accounts) — one rule for edit and switch-off,
//   - delete only when nothing ever touched it,
//   - the tax code must be one of this company's.

require_once __DIR__ . '/Fin.php';
require_once __DIR__ . '/CoaSchema.php';

final class FinAccounts
{
    public const CODE_RE = '/^\d{3,5}(-[A-Za-z0-9]{1,6})?$/';

    /** Every account with balances. $from/$to: the period for the movement column. */
    public static function list(PDO $db, int $companyId, string $from, string $to): array
    {
        $fy = Fin::fyStart($db, $companyId, $to);
        $st = $db->prepare("
            SELECT ga.account_id, ga.account_code, ga.account_name, ga.description, ga.account_type, ga.account_subtype, ga.normal_balance,
                   ga.parent_id, ga.tax_code_id, ga.is_active, ga.is_system, ga.is_control, ga.is_locked, ga.allow_manual_journal,
                   COALESCE(b.upto, 0) upto, COALESCE(b.ytd, 0) ytd, COALESCE(b.mv_d, 0) mv_d, COALESCE(b.mv_c, 0) mv_c, COALESCE(b.n, 0) n, b.last_date
              FROM gl_accounts ga
         LEFT JOIN (SELECT jl.account_code,
                           SUM(CASE WHEN je.entry_date <= ? THEN ROUND(jl.debit * 100) - ROUND(jl.credit * 100) ELSE 0 END) upto,
                           SUM(CASE WHEN je.entry_date BETWEEN ? AND ? AND (je.module IS NULL OR je.module <> 'year_end') THEN ROUND(jl.debit * 100) - ROUND(jl.credit * 100) ELSE 0 END) ytd,
                           SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN ROUND(jl.debit * 100) ELSE 0 END) mv_d,
                           SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN ROUND(jl.credit * 100) ELSE 0 END) mv_c,
                           COUNT(*) n, MAX(je.entry_date) last_date
                      FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                     WHERE je.company_id = ? AND je.status = 'posted'
                  GROUP BY jl.account_code) b ON b.account_code = ga.account_code
             WHERE ga.company_id = ?
          ORDER BY ga.account_code");
        $st->execute([$to, $fy, $to, $from, $to, $from, $to, $companyId, $companyId]);
        $mapped = self::mappedIds($db, $companyId);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $pl = in_array($a['account_type'], ['revenue', 'expense'], true);
            $sign = $a['normal_balance'] === 'credit' ? -1 : 1;
            $rows[] = [
                'id' => (int)$a['account_id'], 'code' => (string)$a['account_code'], 'name' => (string)$a['account_name'], 'description' => (string)($a['description'] ?? ''),
                'type' => (string)$a['account_type'], 'subtype' => (string)($a['account_subtype'] ?? ''), 'normal' => (string)$a['normal_balance'],
                'parent' => $a['parent_id'] ? (int)$a['parent_id'] : null, 'tax_code_id' => $a['tax_code_id'] ? (int)$a['tax_code_id'] : null,
                'active' => (bool)$a['is_active'], 'system' => (bool)$a['is_system'], 'control' => (bool)$a['is_control'], 'locked' => (bool)$a['is_locked'],
                'manual' => (bool)$a['allow_manual_journal'], 'mapped' => $mapped[(int)$a['account_id']] ?? null,
                // natural sign: positive = the account's normal side
                'balance' => $sign * ((int)($pl ? $a['ytd'] : $a['upto'])) / 100,
                'debit' => ((int)$a['mv_d']) / 100, 'credit' => ((int)$a['mv_c']) / 100,
                'movement' => $sign * ((int)$a['mv_d'] - (int)$a['mv_c']) / 100,
                'lines' => (int)$a['n'], 'last' => $a['last_date'],
            ];
        }
        return ['rows' => $rows, 'fy_start' => $fy, 'subtypes' => CoaSchema::subtypesByType(), 'bands' => CoaSchema::codeBands()];
    }

    /** account_id => what it is mapped as (Finance settings and bank accounts). */
    public static function mappedIds(PDO $db, int $companyId): array
    {
        $out = [];
        $st = $db->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id = ? AND setting_key LIKE 'finance\\_%\\_account\\_id'");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if (ctype_digit((string)$v)) $out[(int)$v] = ucfirst(str_replace('_', ' ', substr($k, 8, -11)));
        }
        try {
            $st = $db->prepare("SELECT gl_account_id, name FROM gl_bank_accounts WHERE company_id = ? AND gl_account_id IS NOT NULL");
            $st->execute([$companyId]);
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $name) $out[(int)$id] = 'Bank: ' . $name;
        } catch (Throwable $e) {
            // no bank table
        }
        return $out;
    }

    /** Create or update. $in: id?, code, name, type, subtype?, normal?, description?, parent_id?, tax_code_id?, active?, manual? */
    public static function save(PDO $db, int $companyId, int $userId, string $role, array $in): int
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        $id = (int)($in['id'] ?? 0);
        $code = strtoupper(trim((string)($in['code'] ?? '')));
        $name = trim(preg_replace('/[\x00-\x1f\x7f<>]+/u', ' ', (string)($in['name'] ?? '')));
        $name = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 120);
        $type = (string)($in['type'] ?? '');
        $subtype = (string)($in['subtype'] ?? '');
        $normal = (string)($in['normal'] ?? '') ?: CoaSchema::defaultNormalBalance($type);
        $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 500);
        $parent = (int)($in['parent_id'] ?? 0) ?: null;
        $tax = (int)($in['tax_code_id'] ?? 0) ?: null;
        $active = array_key_exists('active', $in) ? (int)!empty($in['active']) : 1;
        $manual = array_key_exists('manual', $in) ? (int)!empty($in['manual']) : null;

        if ($code === '' || $name === '' || $type === '') throw new FinUserError('An account needs a code, a name and a type.');
        if (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) throw new FinUserError('Choose the kind of account.');
        if (!preg_match(self::CODE_RE, $code)) throw new FinUserError('Use 3 to 5 digits for the code, optionally with a short suffix like 1010-A.');
        if (!CoaSchema::isCodeInBand($type, $code)) {
            $bands = array_map(fn($b) => $b[0] . '–' . $b[1], CoaSchema::codeBands()[$type]);
            throw new FinUserError('A code for ' . $type . ' accounts must be in ' . implode(' or ', $bands) . '.');
        }
        if (!in_array($normal, ['debit', 'credit'], true)) throw new FinUserError('Choose debit or credit as the normal side.');
        if ($subtype !== '' && !CoaSchema::isValidSubtype($type, $subtype)) throw new FinUserError('That subtype does not fit this kind of account.');
        if ($tax) {
            $st = $db->prepare("SELECT 1 FROM gl_tax_codes WHERE tax_code_id = ? AND company_id = ?");
            $st->execute([$tax, $companyId]);
            if (!$st->fetchColumn()) throw new FinUserError('Choose a VAT code from the list.');
        }
        if ($parent) {
            $err = CoaSchema::validateParent($db, $companyId, $parent, $type, $id ?: null);
            if ($err) throw new FinUserError($err);
        }

        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id, $code, $name, $type, $subtype, $normal, $desc, $parent, $tax, $active, $manual) {
            if ($id > 0) {
                $st = $db->prepare("SELECT * FROM gl_accounts WHERE account_id = ? AND company_id = ? FOR UPDATE");
                $st->execute([$id, $companyId]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new FinUserError('That account was not found.');
                if ((int)$old['is_locked']) {
                    $db->prepare("UPDATE gl_accounts SET description = ?, tax_code_id = ?, updated_at = NOW(), updated_by = ? WHERE account_id = ? AND company_id = ?")
                       ->execute([$desc, $tax, $userId, $id, $companyId]);
                    Fin::audit($db, $companyId, $userId, 'account_updated', 'gl_account', $id, ['code' => $old['account_code'], 'locked' => true,
                        'changes' => array_filter(['description' => [$old['description'], $desc], 'tax_code_id' => [$old['tax_code_id'], $tax]], fn($c) => (string)$c[0] !== (string)$c[1])]);
                    return $id;
                }
                $structural = $old['account_code'] !== $code || $old['account_type'] !== $type || (string)$old['account_subtype'] !== $subtype || $old['normal_balance'] !== $normal;
                if ($structural && (int)$old['is_system']) throw new FinUserError('The code, type, subtype and normal side of a system account cannot change.');
                if ($structural) {
                    $n = CoaSchema::allLineCount($db, $companyId, $old['account_code']);
                    if ($n > 0) throw new FinUserError("The code, type, subtype and normal side are fixed once journals use the account ($n line(s) do).");
                }
                if ($old['account_code'] !== $code) self::assertFreeCode($db, $companyId, $code, $id);
                if ($active === 0 && (int)$old['is_active'] === 1) self::assertCanSwitchOff($db, $companyId, $old);
                $newManual = $manual === null ? (int)$old['allow_manual_journal'] : ((int)$old['is_control'] ? (int)$old['allow_manual_journal'] : $manual);
                $new = ['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'account_subtype' => $subtype,
                        'description' => $desc, 'parent_id' => $parent, 'tax_code_id' => $tax, 'is_active' => $active, 'allow_manual_journal' => $newManual];
                $changes = [];
                foreach ($new as $k => $v) if ((string)($old[$k] ?? '') !== (string)($v ?? '')) $changes[$k] = [$old[$k], $v];
                $db->prepare("UPDATE gl_accounts SET account_code = ?, account_name = ?, description = ?, account_type = ?, normal_balance = ?, account_subtype = ?,
                                     parent_id = ?, tax_code_id = ?, is_active = ?, allow_manual_journal = ?, updated_at = NOW(), updated_by = ?
                               WHERE account_id = ? AND company_id = ?")
                   ->execute([$code, $name, $desc, $type, $normal, $subtype, $parent, $tax, $active, $newManual, $userId, $id, $companyId]);
                Fin::audit($db, $companyId, $userId, 'account_updated', 'gl_account', $id, ['code' => $code, 'changes' => $changes]);
                return $id;
            }
            self::assertFreeCode($db, $companyId, $code, 0);
            $db->prepare("INSERT INTO gl_accounts (company_id, account_code, account_name, description, account_type, normal_balance, account_subtype,
                                 parent_id, tax_code_id, is_system, is_control, is_locked, allow_manual_journal, is_active, currency, created_by, updated_by, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, 'ZAR', ?, ?, NOW(), NOW())")
               ->execute([$companyId, $code, $name, $desc, $type, $normal, $subtype, $parent, $tax, $manual === null ? 1 : $manual, $active, $userId, $userId]);
            $id = (int)$db->lastInsertId();
            Fin::audit($db, $companyId, $userId, 'account_created', 'gl_account', $id, ['code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype]);
            return $id;
        });
    }

    private static function assertFreeCode(PDO $db, int $companyId, string $code, int $selfId): void
    {
        $st = $db->prepare("SELECT 1 FROM gl_accounts WHERE company_id = ? AND account_code = ? AND account_id <> ?");
        $st->execute([$companyId, $code, $selfId]);
        if ($st->fetchColumn()) throw new FinUserError("Account code $code is already used.");
    }

    /** One rule for switching an account off. */
    private static function assertCanSwitchOff(PDO $db, int $companyId, array $a): void
    {
        if ((int)$a['is_system']) throw new FinUserError('System accounts stay on.');
        if ((int)$a['is_locked']) throw new FinUserError('SARS-statutory accounts stay on.');
        $st = $db->prepare("SELECT COALESCE(SUM(ROUND(jl.debit * 100) - ROUND(jl.credit * 100)), 0) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status = 'posted' AND jl.account_code = ?");
        $st->execute([$companyId, $a['account_code']]);
        if ((int)$st->fetchColumn() !== 0) throw new FinUserError('This account still has a balance. Move the balance to another account with a journal first.');
        $st = $db->prepare("SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_id
                             WHERE je.company_id = ? AND je.status <> 'posted' AND jl.account_code = ?");
        $st->execute([$companyId, $a['account_code']]);
        if ((int)$st->fetchColumn() > 0) throw new FinUserError('Draft or approved journals still use this account. Post or change them first.');
        $st = $db->prepare("SELECT COUNT(*) FROM gl_accounts WHERE company_id = ? AND parent_id = ? AND is_active = 1");
        $st->execute([$companyId, (int)$a['account_id']]);
        if ((int)$st->fetchColumn() > 0) throw new FinUserError('Switch off its sub-accounts first.');
        $m = self::mappedIds($db, $companyId)[(int)$a['account_id']] ?? null;
        if ($m) throw new FinUserError("This account is in use as “{$m}”. Change that mapping in Make it yours first.");
    }

    public static function toggle(PDO $db, int $companyId, int $userId, string $role, int $id): bool
    {
        if (!Fin::canWrite($role)) throw new FinForbidden('You have read-only access to Finance.');
        return Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $st = $db->prepare("SELECT * FROM gl_accounts WHERE account_id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) throw new FinUserError('That account was not found.');
            $on = !(int)$a['is_active'];
            if (!$on) self::assertCanSwitchOff($db, $companyId, $a);
            $db->prepare("UPDATE gl_accounts SET is_active = ?, updated_at = NOW(), updated_by = ? WHERE account_id = ? AND company_id = ?")->execute([(int)$on, $userId, $id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'account_status_changed', 'gl_account', $id, ['code' => $a['account_code'], 'old_status' => (int)$a['is_active'], 'new_status' => (int)$on]);
            return $on;
        });
    }

    public static function delete(PDO $db, int $companyId, int $userId, string $role, int $id): void
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can delete accounts.');
        Fin::atomic($db, function () use ($db, $companyId, $userId, $id) {
            $st = $db->prepare("SELECT * FROM gl_accounts WHERE account_id = ? AND company_id = ? FOR UPDATE");
            $st->execute([$id, $companyId]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) throw new FinUserError('That account was not found.');
            if ((int)$a['is_system'] || (int)$a['is_locked']) throw new FinUserError('System and SARS-statutory accounts cannot be deleted. Switch it off instead.');
            $n = CoaSchema::allLineCount($db, $companyId, $a['account_code']);
            if ($n > 0) throw new FinUserError("Journals use this account ($n line(s)), so it stays. Switch it off instead.");
            $st = $db->prepare("SELECT COUNT(*) FROM gl_accounts WHERE parent_id = ? AND company_id = ?");
            $st->execute([$id, $companyId]);
            if ((int)$st->fetchColumn() > 0) throw new FinUserError('It has sub-accounts. Move or delete them first.');
            $refs = [];
            $checks = [
                'bank accounts' => ["SELECT EXISTS(SELECT 1 FROM gl_bank_accounts WHERE company_id = ? AND gl_account_id = ?)", [$companyId, $id]],
                'bank rules' => ["SELECT EXISTS(SELECT 1 FROM gl_bank_rules WHERE company_id = ? AND gl_account_id = ?)", [$companyId, $id]],
                'budgets' => ["SELECT EXISTS(SELECT 1 FROM gl_budgets WHERE company_id = ? AND gl_account_id = ?)", [$companyId, $id]],
                'fixed assets' => ["SELECT EXISTS(SELECT 1 FROM gl_fixed_assets WHERE company_id = ? AND (asset_account_id = ? OR depreciation_expense_account_id = ? OR accumulated_depreciation_account_id = ?))", [$companyId, $id, $id, $id]],
                'supplier bill lines' => ["SELECT EXISTS(SELECT 1 FROM ap_bill_lines l JOIN ap_bills b ON b.id = l.bill_id WHERE b.company_id = ? AND l.gl_account_id = ?)", [$companyId, $id]],
            ];
            foreach ($checks as $label => [$sql, $args]) {
                try { $s = $db->prepare($sql); $s->execute($args); if ((int)$s->fetchColumn()) $refs[] = $label; } catch (Throwable $e) { /* table missing */ }
            }
            if (isset(self::mappedIds($db, $companyId)[$id])) $refs[] = 'Finance settings';
            if ($refs) throw new FinUserError('Still used by ' . implode(', ', $refs) . '. Change those first, or switch the account off.');
            $db->prepare("DELETE FROM gl_accounts WHERE account_id = ? AND company_id = ?")->execute([$id, $companyId]);
            Fin::audit($db, $companyId, $userId, 'account_deleted', 'gl_account', $id, ['code' => $a['account_code'], 'name' => $a['account_name']]);
        });
    }

    /**
     * Import accounts from CSV text (columns: code, name, type, subtype?, normal_balance?, parent_code?, tax_code?, description?).
     * All rows are checked first; nothing is written unless every row is valid. $dryRun only checks.
     */
    public static function import(PDO $db, int $companyId, int $userId, string $role, string $csv, bool $dryRun): array
    {
        if (!Fin::isAdmin($role)) throw new FinForbidden('Only an administrator can import accounts.');
        if (strlen($csv) > 2 * 1024 * 1024) throw new FinUserError('That file is too large (2 MB at most).');
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (count($lines) < 2) throw new FinUserError('The file needs a header row and at least one account.');
        $head = array_map(fn($h) => str_replace(' ', '_', strtolower(trim($h))), str_getcsv(array_shift($lines)));
        foreach (['code', 'name', 'type'] as $col) if (!in_array($col, $head, true)) throw new FinUserError("The header needs a “{$col}” column.");
        $existing = [];
        $st = $db->prepare("SELECT account_code, account_type FROM gl_accounts WHERE company_id = ?");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $c => $t) $existing[strtoupper($c)] = $t;
        $taxes = [];
        $st = $db->prepare("SELECT code, tax_code_id FROM gl_tax_codes WHERE company_id = ? AND is_active = 1");
        $st->execute([$companyId]);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $c => $tid) $taxes[strtoupper($c)] = (int)$tid;
        $rows = [];
        $errors = [];
        $seen = [];
        foreach ($lines as $n => $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line);
            $r = [];
            foreach ($head as $i => $h) $r[$h] = trim((string)($cells[$i] ?? ''));
            $row = $n + 2;
            $code = strtoupper($r['code']);
            $type = strtolower($r['type']);
            $subtype = strtolower($r['subtype'] ?? '');
            $normal = strtolower($r['normal_balance'] ?? '') ?: CoaSchema::defaultNormalBalance($type);
            $name = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f<>]+/u', ' ', $r['name'])), 0, 120);
            if ($code === '' || $name === '' || $type === '') { $errors[] = "Row $row: code, name and type are needed."; continue; }
            if (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) { $errors[] = "Row $row: “{$r['type']}” is not an account type."; continue; }
            if (!preg_match(self::CODE_RE, $code) || !CoaSchema::isCodeInBand($type, $code)) { $errors[] = "Row $row: code $code does not fit a $type account."; continue; }
            if (isset($existing[$code]) || isset($seen[$code])) { $errors[] = "Row $row: code $code is already used."; continue; }
            if ($subtype !== '' && !CoaSchema::isValidSubtype($type, $subtype)) { $errors[] = "Row $row: subtype “{$subtype}” does not fit."; continue; }
            if (!in_array($normal, ['debit', 'credit'], true)) { $errors[] = "Row $row: normal balance must be debit or credit."; continue; }
            $tax = null;
            if (($r['tax_code'] ?? '') !== '') {
                $tax = $taxes[strtoupper($r['tax_code'])] ?? null;
                if (!$tax) { $errors[] = "Row $row: VAT code “{$r['tax_code']}” is not set up."; continue; }
            }
            $parent = strtoupper($r['parent_code'] ?? '');
            if ($parent !== '' && !isset($existing[$parent]) && !isset($seen[$parent])) { $errors[] = "Row $row: parent $parent is not on the chart (or later in the file)."; continue; }
            $pt = $existing[$parent] ?? ($seen[$parent] ?? null);
            if ($parent !== '' && $pt !== $type) { $errors[] = "Row $row: the parent $parent is a different kind of account."; continue; }
            $seen[$code] = $type;
            $rows[] = ['code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype, 'normal' => $normal, 'parent' => $parent, 'tax' => $tax,
                       'desc' => mb_substr((string)($r['description'] ?? ''), 0, 500)];
        }
        if (count($rows) > 1000) throw new FinUserError('Import up to 1000 accounts at a time.');
        if ($errors || $dryRun) return ['ok' => !$errors, 'rows' => count($rows), 'errors' => array_slice($errors, 0, 50), 'preview' => array_slice($rows, 0, 20)];
        Fin::atomic($db, function () use ($db, $companyId, $userId, $rows) {
            $find = $db->prepare("SELECT account_id FROM gl_accounts WHERE company_id = ? AND account_code = ?");
            $ins = $db->prepare("INSERT INTO gl_accounts (company_id, account_code, account_name, description, account_type, normal_balance, account_subtype, parent_id, tax_code_id,
                                        is_system, is_control, is_locked, allow_manual_journal, is_active, currency, created_by, updated_by, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 1, 1, 'ZAR', ?, ?, NOW(), NOW())");
            foreach ($rows as $r) {
                $pid = null;
                if ($r['parent'] !== '') { $find->execute([$companyId, $r['parent']]); $pid = (int)$find->fetchColumn() ?: null; }
                $ins->execute([$companyId, $r['code'], $r['name'], $r['desc'], $r['type'], $r['normal'], $r['subtype'], $pid, $r['tax'], $userId, $userId]);
            }
            Fin::audit($db, $companyId, $userId, 'accounts_imported', 'gl_account', null, ['count' => count($rows), 'codes' => array_column($rows, 'code')]);
        });
        return ['ok' => true, 'rows' => count($rows), 'errors' => [], 'imported' => count($rows)];
    }
}
