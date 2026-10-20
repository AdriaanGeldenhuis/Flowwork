<?php
// /admin/lib/AdminAudit.php — the audit trail's filters (shared by the page
// and its CSV export, so the export is exactly what is on screen) and the
// company's record counts for Data & privacy.

final class AdminAudit
{
    /** Kinds of activity: [label, SQL LIKE patterns on audit_log.action]. */
    public const KINDS = [
        'signin' => ['Sign-ins', ['user_login', 'user_logout', 'password_reset%', 'device_signed_out', 'user_signed_out']],
        'people' => ['People & invitations', ['user_created', 'user_updated', 'user_deleted', 'user_suspended', 'user_reactivated', 'user_registered', 'invite_%']],
        'settings' => ['Settings & billing', ['settings_updated', 'company_updated', 'company_create', 'plan_changed', 'subscription_%', 'finance_settings_%', 'yoco_%', 'qi_bank_details_changed', 'data_exported', 'audit_pruned']],
        'projects' => ['Projects & boards', ['project_%', 'board_%', 'item_%', 'create', 'update']],
        'money' => ['Quotes, invoices & finance', ['quote_%', 'invoice_%', 'credit_%', 'payment_%', 'qi_%', 'ar_%', 'ap_%', 'journal_%', 'finance_%', 'receipt_%']],
        'payroll' => ['Payroll', ['payrun_%', 'payslips_%', 'employee_%', 'payitem%', 'payroll_%', 'tax_table_%']],
        'crm' => ['CRM & suppliers', ['crm_%', 'ai_%', 'supplier_%']],
    ];

    /** Clean filters from the query string. */
    public static function filters(array $get): array
    {
        $date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : '';
        $kind = is_string($get['kind'] ?? null) && isset(self::KINDS[$get['kind']]) ? $get['kind'] : '';
        return [
            'user' => max(0, (int)($get['user_id'] ?? 0)),
            'kind' => $kind,
            'q' => is_string($get['q'] ?? null) ? mb_substr(trim($get['q']), 0, 100) : '',
            'from' => $date($get['from'] ?? ''),
            'to' => $date($get['to'] ?? ''),
        ];
    }

    /** @return array{0: string, 1: array} WHERE clause on audit_log al (joined users u) and its params */
    public static function where(int $companyId, array $f): array
    {
        $sql = 'al.company_id = ?';
        $p = [$companyId];
        if ($f['user']) { $sql .= ' AND al.user_id = ?'; $p[] = $f['user']; }
        if ($f['kind']) {
            $likes = self::KINDS[$f['kind']][1];
            $sql .= ' AND (' . implode(' OR ', array_fill(0, count($likes), 'al.action LIKE ?')) . ')';
            array_push($p, ...$likes);
        }
        if ($f['q'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $sql .= " AND (al.action LIKE ? OR al.details LIKE ? OR al.ip LIKE ? OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.email LIKE ?)";
            array_push($p, $like, $like, $like, $like, $like);
        }
        if ($f['from']) { $sql .= ' AND COALESCE(al.timestamp, al.created_at) >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
        if ($f['to']) { $sql .= ' AND COALESCE(al.timestamp, al.created_at) <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
        return [$sql, $p];
    }

    /** How many of each kind of record the company holds (null: that module is not in use on this database). */
    public static function counts(PDO $db, int $companyId): array
    {
        $q = [
            'People' => "SELECT COUNT(*) FROM user_companies WHERE company_id = ?",
            'Projects' => "SELECT COUNT(*) FROM projects WHERE company_id = ?",
            'Boards' => "SELECT COUNT(*) FROM project_boards WHERE company_id = ?",
            'Board items' => "SELECT COUNT(*) FROM board_items WHERE company_id = ?",
            'Customers' => "SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'customer' AND deleted_at IS NULL",
            'Suppliers' => "SELECT COUNT(*) FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND deleted_at IS NULL",
            'Quotes' => "SELECT COUNT(*) FROM quotes WHERE company_id = ?",
            'Invoices' => "SELECT COUNT(*) FROM invoices WHERE company_id = ? AND deleted_at IS NULL",
            'Journals' => "SELECT COUNT(*) FROM journal_entries WHERE company_id = ?",
            'Receipts' => "SELECT COUNT(*) FROM receipt_file WHERE company_id = ?",
            'Employees' => "SELECT COUNT(*) FROM employees WHERE company_id = ?",
            'Calendar events' => "SELECT COUNT(*) FROM calendar_events WHERE company_id = ?",
            'Emails' => "SELECT COUNT(*) FROM emails WHERE company_id = ?",
            'Audit trail entries' => "SELECT COUNT(*) FROM audit_log WHERE company_id = ?",
        ];
        $out = [];
        foreach ($q as $label => $sql) {
            try {
                $st = $db->prepare($sql);
                $st->execute([$companyId]);
                $out[$label] = (int)$st->fetchColumn();
            } catch (Throwable $e) {
                $out[$label] = null;
            }
        }
        return $out;
    }
}
