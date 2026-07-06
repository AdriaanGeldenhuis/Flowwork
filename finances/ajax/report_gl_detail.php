<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_gl_detail.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/report_helpers.php';

header('Content-Type: application/json');


$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$date      = $_GET['date'] ?? date('Y-m-d');
$startDate = $_GET['start_date'] ?? '';
$accountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// Validate date formats
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt || $dt->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}
$sdt = DateTime::createFromFormat('Y-m-d', $startDate);
if (!$sdt || $sdt->format('Y-m-d') !== $startDate) {
    $startDate = ''; // no lower bound: ledger from inception, opening balance 0
}

if ($accountId <= 0) {
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

    // Opening balance: everything posted STRICTLY BEFORE the period start.
    // Without a start date the ledger runs from inception and opens at 0.
    // (Previously this summed everything before the END date while the
    // transaction list ALSO included those older lines, so the closing
    // balance double-counted every pre-period movement.)
    $openingBalance = 0.0;
    if ($startDate !== '') {
        $obSql = "
            SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
            FROM journal_lines jl
            JOIN journal_entries je ON jl.journal_id = je.id
            WHERE jl.account_code = ? AND je.company_id = ? AND je.status = 'posted'
              AND je.entry_date < ?";
        $obParams = [$accountCode, $companyId, $startDate];
        if ($projectId > 0) {
            $obSql .= " AND jl.project_id = ?";
            $obParams[] = $projectId;
        }
        $stmt = $DB->prepare($obSql);
        $stmt->execute($obParams);
        $openingBalance = (float)$stmt->fetchColumn();
    }

    // Fetch transactions within the period (posted only, company scoped)
    $sql = "SELECT
            je.id as journal_id,
            je.entry_date,
            je.description AS memo,
            je.reference,
            je.module,
            jl.description,
            jl.debit,
            jl.credit,
            jl.project_id
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_id = je.id
        WHERE jl.account_code = ?
        AND je.company_id = ?
        AND je.status = 'posted'
        AND je.entry_date <= ?";
    $params = [$accountCode, $companyId, $date];
    if ($startDate !== '') {
        $sql .= " AND je.entry_date >= ?";
        $params[] = $startDate;
    }
    if ($projectId > 0) {
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

    $meta = getReportMeta($DB, $companyId, $userId);

    echo json_encode([
        'ok' => true,
        'data' => [
            'report_meta'  => $meta,
            'company_name' => $companyName,
            'date'         => $date,
            'start_date'   => $startDate !== '' ? $startDate : null,
            'account'      => $account,
            'transactions' => $transactions,
            'opening_balance_cents' => intval(round($openingBalance * 100)),
            'closing_balance_cents' => intval(round($closingBalance * 100))
        ]
    ]);

} catch (Exception $e) {
    error_log('GL detail error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to generate GL detail']);
}