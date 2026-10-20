<?php
// /payroll/lib/PaySchema.php — the columns Payroll adds and the official SARS
// tax tables, provisioned on first use (mirrored in
// Migrations/2026-10-21-payroll-rebuild.sql).
//
//   employees.job_title          shown on payslips
//   employees.birth_date         decides the age rebates (65+, 75+); filled from the SA ID number
//   employees.medical_members    people on the employee's medical scheme (for the medical tax credit)
//   employees.bank_account_type  cheque / savings / transmission, for the bank file
//   payitems.pre_tax             a deduction that lowers taxable income (pension, provident, RA)
//   payitems.irregular           a once-off earning taxed as an annual payment (bonus)
//   payroll_settings.sdl_liable  the company pays SDL (payroll over R500 000 a year)
//   payroll_settings.hours_per_week  normal hours, for working out overtime rates
//   payroll_settings.paye_reference  the employer's SARS PAYE reference (7…), on payslips and returns
//
// Flowwork's own tax_tables_za rows (company_id IS NULL) are brought in line
// with PayTax::OFFICIAL. A company's own rows are never touched.

require_once __DIR__ . '/PayTax.php';

final class PaySchema
{
    private static bool $done = false;

    public static function ensure(PDO $db): void
    {
        if (self::$done) return;
        self::$done = true;
        try {
            if (self::upToDate($db)) return;
            self::columns($db, 'employees', [
                'job_title' => "ADD COLUMN `job_title` varchar(120) DEFAULT NULL AFTER `last_name`",
                'birth_date' => "ADD COLUMN `birth_date` date DEFAULT NULL AFTER `id_number`",
                'medical_members' => "ADD COLUMN `medical_members` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `sdl_included`",
                'bank_account_type' => "ADD COLUMN `bank_account_type` varchar(20) DEFAULT NULL AFTER `bank_account_no`",
            ]);
            self::columns($db, 'payitems', [
                'pre_tax' => "ADD COLUMN `pre_tax` tinyint(1) NOT NULL DEFAULT 0 AFTER `sdl_subject`",
                'irregular' => "ADD COLUMN `irregular` tinyint(1) NOT NULL DEFAULT 0 AFTER `pre_tax`",
            ]);
            self::columns($db, 'payroll_settings', [
                'sdl_liable' => "ADD COLUMN `sdl_liable` tinyint(1) NOT NULL DEFAULT 1 AFTER `auto_post_to_finance`",
                'hours_per_week' => "ADD COLUMN `hours_per_week` decimal(4,1) NOT NULL DEFAULT 45.0 AFTER `sdl_liable`",
                'paye_reference' => "ADD COLUMN `paye_reference` varchar(20) DEFAULT NULL AFTER `hours_per_week`",
            ]);
            self::syncTaxTables($db);
            self::backfillBirthDates($db);
        } catch (Throwable $e) {
            error_log('[payroll] schema: ' . $e->getMessage());
        }
    }

    /** One cheap look: all nine columns there, and the newest official table current. */
    private static function upToDate(PDO $db): bool
    {
        $cols = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (
                (TABLE_NAME = 'employees' AND COLUMN_NAME IN ('job_title','birth_date','medical_members','bank_account_type'))
             OR (TABLE_NAME = 'payitems' AND COLUMN_NAME IN ('pre_tax','irregular'))
             OR (TABLE_NAME = 'payroll_settings' AND COLUMN_NAME IN ('sdl_liable','hours_per_week','paye_reference')))")->fetchColumn();
        if ($cols < 9) return false;
        $from = array_key_last(PayTax::OFFICIAL);
        $st = $db->prepare("SELECT rebate_primary_cents, uif_cap_annual_cents, medical_credit_main_cents FROM tax_tables_za WHERE company_id IS NULL AND effective_from = ? LIMIT 1");
        $st->execute([$from]);
        $r = $st->fetch(PDO::FETCH_NUM);
        $t = PayTax::OFFICIAL[$from];
        return $r && (int)$r[0] === $t['rebates'][0] && (int)$r[1] === PayTax::UIF_CEILING_YEAR_CENTS && (int)$r[2] === $t['medical'][0];
    }

    private static function columns(PDO $db, string $table, array $want): void
    {
        $st = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$table]);
        $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        if (!$have) return; // table missing in this install — nothing to extend
        $add = [];
        foreach ($want as $col => $sql) if (!isset($have[$col])) $add[] = $sql;
        if ($add) $db->exec("ALTER TABLE `$table` " . implode(', ', $add));
    }

    /** Dates of birth from valid SA ID numbers, for people added before the column existed. */
    public static function backfillBirthDates(PDO $db): int
    {
        $rows = $db->query("SELECT id, id_number FROM employees WHERE birth_date IS NULL AND id_number IS NOT NULL AND id_number <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $db->prepare("UPDATE employees SET birth_date = ? WHERE id = ?");
        $n = 0;
        foreach ($rows as $r) {
            if ($d = PayTax::birthDateFromId((string)$r['id_number'])) { $upd->execute([$d, $r['id']]); $n++; }
        }
        return $n;
    }

    /** Make Flowwork's shared tax tables match the official figures in PayTax. */
    public static function syncTaxTables(PDO $db): void
    {
        foreach (PayTax::OFFICIAL as $from => $t) {
            $json = json_encode(array_map(fn($b) => ['threshold_cents' => $b[0], 'rate_bp' => $b[1], 'cumulative_cents' => $b[2]], PayTax::withBases($t['brackets'])));
            $vals = [$t['to'], $json, $t['rebates'][0], $t['rebates'][1], $t['rebates'][2], PayTax::UIF_CEILING_YEAR_CENTS, $t['medical'][0], $t['medical'][1]];
            $st = $db->prepare("SELECT id FROM tax_tables_za WHERE company_id IS NULL AND effective_from = ? ORDER BY id LIMIT 1");
            $st->execute([$from]);
            $id = $st->fetchColumn();
            if ($id) {
                $db->prepare("UPDATE tax_tables_za SET effective_to = ?, bracket_json = ?, rebate_primary_cents = ?, rebate_secondary_cents = ?, rebate_tertiary_cents = ?,
                                     uif_cap_annual_cents = ?, medical_credit_main_cents = ?, medical_credit_dep_cents = ? WHERE id = ?")
                   ->execute(array_merge($vals, [$id]));
            } else {
                $db->prepare("INSERT INTO tax_tables_za (company_id, effective_to, bracket_json, rebate_primary_cents, rebate_secondary_cents, rebate_tertiary_cents,
                                     uif_cap_annual_cents, medical_credit_main_cents, medical_credit_dep_cents, effective_from)
                              VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute(array_merge($vals, [$from]));
            }
        }
    }
}
