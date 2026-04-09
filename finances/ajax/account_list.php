<?php
// /finances/ajax/account_list.php — SARS bookkeeping-grade
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/CoaSchema.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];

try {
    // Resolve fiscal year start date for YTD balance computation
    $fyStart = CoaSchema::fiscalYearStartDate($DB, $companyId);

    $stmt = $DB->prepare("
        SELECT
            a.account_id,
            a.account_code,
            a.account_name,
            a.description,
            a.account_type,
            a.normal_balance,
            a.account_subtype,
            a.parent_id,
            a.tax_code_id,
            a.is_system,
            a.is_control,
            a.is_locked,
            a.allow_manual_journal,
            a.afs_line_code,
            a.is_active,
            a.created_at,
            COALESCE(bal.ytd_debit, 0)  AS ytd_debit,
            COALESCE(bal.ytd_credit, 0) AS ytd_credit
        FROM gl_accounts a
        LEFT JOIN (
            SELECT
                jl.account_code,
                SUM(jl.debit)  AS ytd_debit,
                SUM(jl.credit) AS ytd_credit
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_id
            WHERE je.company_id = ?
              AND je.status = 'posted'
              AND je.entry_date >= ?
            GROUP BY jl.account_code
        ) bal ON bal.account_code = a.account_code
        WHERE a.company_id = ?
        ORDER BY a.account_code ASC
    ");
    $stmt->execute([$companyId, $fyStart, $companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute signed ytd_balance per account (positive = normal direction)
    foreach ($accounts as &$row) {
        $d = (float)$row['ytd_debit'];
        $c = (float)$row['ytd_credit'];
        $row['ytd_balance'] = $row['normal_balance'] === 'debit' ? ($d - $c) : ($c - $d);
        // Cast numerics for clean JSON
        $row['ytd_debit']   = round($d, 2);
        $row['ytd_credit']  = round($c, 2);
        $row['ytd_balance'] = round($row['ytd_balance'], 2);
    }
    unset($row);

    echo json_encode([
        'ok'   => true,
        'data' => $accounts,
        'meta' => [
            'fy_start' => $fyStart,
            'schema'   => CoaSchema::asJson(),
        ],
    ]);

} catch (Exception $e) {
    error_log("Account list error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load accounts']);
}
