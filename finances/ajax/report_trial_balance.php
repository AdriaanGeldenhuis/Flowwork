<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_trial_balance.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/report_helpers.php';

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$date = $_GET['date'] ?? date('Y-m-d');
// Validate date format
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}

try {
    $meta = getReportMeta($DB, $companyId, $userId);
    $companyName = $meta['company_name'];

    // Get all accounts with balances. Since journal_lines stores account_code rather than account_id, we join on account_code.
    // We sum debit and credit amounts up to the given date. Multiply by 100 to convert to cents for the frontend.
    $stmt = $DB->prepare("
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            a.account_type,
            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit ELSE 0 END), 0) AS total_credit
        FROM gl_accounts a
        LEFT JOIN journal_lines jl ON a.account_code = jl.account_code
        LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
        WHERE a.company_id = ?
        AND a.is_active = 1
        GROUP BY a.account_id, a.account_code, a.account_name, a.account_type
        ORDER BY a.account_code ASC
    ");
    $stmt->execute([$date, $date, $companyId, $companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format accounts based on normal balance
    $formattedAccounts = [];
    foreach ($accounts as $acc) {
        // Convert to cents and compute balance (debit - credit)
        $debitCents  = (int) round(floatval($acc['total_debit']) * 100);
        $creditCents = (int) round(floatval($acc['total_credit']) * 100);
        $balance     = $debitCents - $creditCents;

        // Only include accounts with a non-zero NET balance (a fully-reversed
        // account nets to zero and doesn't belong on the trial balance).
        if ($balance === 0) {
            continue;
        }

        // A trial balance places each account's net balance on its natural side
        // by SIGN, not by account type: a net debit ($balance > 0) in the debit
        // column, a net credit in the credit column. The previous per-type split
        // inverted credit-normal accounts (liabilities/equity/revenue) — pushing
        // their credit balances into the debit column — so the TB never balanced.
        // Because the ledger nets to zero, the two columns now sum equal.
        $formattedAccounts[] = [
            'account_code' => $acc['account_code'],
            'account_name' => $acc['account_name'],
            'debit_cents'  => max(0, $balance),
            'credit_cents' => max(0, -$balance)
        ];
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'report_meta' => $meta,
            'company_name' => $companyName,
            'date' => $date,
            'accounts' => $formattedAccounts
        ]
    ]);

} catch (Exception $e) {
    error_log("Trial balance error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate trial balance'
    ]);
}