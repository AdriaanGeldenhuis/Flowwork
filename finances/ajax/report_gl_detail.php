<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_gl_detail.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');


$companyId = $_SESSION['company_id'];
$date      = $_GET['date'] ?? date('Y-m-d');
$accountId = $_GET['account_id'] ?? null;
$projectId = $_GET['project_id'] ?? null;

if (!$accountId) {
    echo json_encode(['ok' => false, 'error' => 'Account ID required']);
    exit;
}

try {
    // Fetch company name
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn();

    // Fetch account details
    $stmt = $DB->prepare("SELECT account_id, account_code, account_name, account_type FROM gl_accounts WHERE account_id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new Exception('Account not found');
    }

    $accountCode = $account['account_code'];

    // Calculate opening balance up to but excluding the date
    $stmt = $DB->prepare("\
        SELECT COALESCE(SUM(CASE WHEN je.entry_date < ? THEN (jl.debit - jl.credit) ELSE 0 END), 0) AS balance\
        FROM journal_lines jl\
        JOIN journal_entries je ON jl.journal_id = je.id\
        WHERE (jl.account_code = ? OR jl.account_id = ?) AND je.company_id = ?\
    ");
    $stmt->execute([$date, $accountCode, $accountId, $companyId]);
    $openingBalance = (float)$stmt->fetchColumn();

    // Fetch transactions up to and including the date
    $sql = "SELECT \n            je.id as journal_id,\n            je.entry_date,\n            je.memo,\n            je.reference,\n            je.module,\n            jl.description,\n            jl.debit,\n            jl.credit,\n            jl.project_id\n        FROM journal_lines jl\n        JOIN journal_entries je ON jl.journal_id = je.id\n        WHERE (jl.account_code = ? OR jl.account_id = ?) \n        AND je.company_id = ?\n        AND je.entry_date <= ?";
    $params = [$accountCode, $accountId, $companyId, $date];
    if ($projectId) {
        $sql .= " AND jl.project_id = ?";
        $params[] = $projectId;
    }
    $sql .= " ORDER BY je.entry_date ASC, je.id ASC";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare transactions with cents values
    $transactions = [];
    $closingBalance = $openingBalance;
    foreach ($rows as $tx) {
        $debit  = isset($tx['debit']) ? (float)$tx['debit'] : 0;
        $credit = isset($tx['credit']) ? (float)$tx['credit'] : 0;
        $debitCents  = intval(round($debit * 100));
        $creditCents = intval(round($credit * 100));
        $closingBalance += ($debit - $credit);
        $transactions[] = [
            'journal_id'    => $tx['journal_id'],
            'entry_date'    => $tx['entry_date'],
            'memo'          => $tx['memo'],
            'reference'     => $tx['reference'],
            'module'        => $tx['module'],
            'description'   => $tx['description'],
            'debit_cents'   => $debitCents,
            'credit_cents'  => $creditCents,
            'project_id'    => $tx['project_id'],
            'debit'         => $debit,
            'credit'        => $credit
        ];
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'company_name' => $companyName,
            'date'         => $date,
            'account'      => $account,
            'transactions' => $transactions,
            'opening_balance_cents' => intval(round($openingBalance * 100)),
            'closing_balance_cents' => intval(round($closingBalance * 100))
        ]
    ]);

} catch (Exception $e) {
    error_log('GL detail error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}