<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_trial_balance.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$date = $_GET['date'] ?? date('Y-m-d');

try {
    // Get company name
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn();

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
        LEFT JOIN journal_entries je ON jl.journal_id = je.id
        WHERE a.company_id = ? 
        AND a.is_active = 1
        GROUP BY a.account_id, a.account_code, a.account_name, a.account_type
        ORDER BY a.account_code ASC
    ");
    $stmt->execute([$date, $date, $companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format accounts based on normal balance
    $formattedAccounts = [];
    foreach ($accounts as $acc) {
        // Convert to cents and compute balance (debit - credit)
        $debitCents  = (int) round(floatval($acc['total_debit']) * 100);
        $creditCents = (int) round(floatval($acc['total_credit']) * 100);
        $balance     = $debitCents - $creditCents;

        // Only include accounts that have a non-zero balance
        if ($debitCents === 0 && $creditCents === 0) {
            continue;
        }

        // Determine normal balance side
        $normalDebit = in_array($acc['account_type'], ['asset', 'expense']);

        if ($normalDebit) {
            // Debit balance accounts: positive balance on debit side
            $formattedAccounts[] = [
                'account_code' => $acc['account_code'],
                'account_name' => $acc['account_name'],
                'debit_cents'  => max(0, $balance),
                'credit_cents' => max(0, -$balance)
            ];
        } else {
            // Credit balance accounts: positive balance on credit side
            $formattedAccounts[] = [
                'account_code' => $acc['account_code'],
                'account_name' => $acc['account_name'],
                'debit_cents'  => max(0, -$balance),
                'credit_cents' => max(0, $balance)
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
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