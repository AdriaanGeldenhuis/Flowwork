<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_budget_vs_actual.php
// Returns budgets vs actual amounts per account for a given year (optionally filtered by account type)

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Input: year (YYYY), type (income|expense|all)
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = intval(date('Y'));
}

$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
if (!in_array($type, ['income','expense','all',''])) {
    $type = '';
}

try {
    // Fetch company name
    $stmt = $DB->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $companyName = $stmt->fetchColumn() ?: 'Company';

    // Build list of accounts to include
    $accSql = "SELECT account_id, account_code, account_name, account_type FROM gl_accounts WHERE company_id = ? AND is_active = 1";
    $params = [$companyId];
    if ($type === 'income' || $type === 'expense') {
        $accSql .= " AND account_type = ?";
        $params[] = $type;
    } else {
        // Only include income and expense accounts for budgets vs actual
        $accSql .= " AND account_type IN ('income','expense')";
    }
    $accSql .= " ORDER BY account_code";
    $stmt = $DB->prepare($accSql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare budgets array keyed by account_id and month
    $budgets = [];
    if (!empty($accounts)) {
        $accIds = array_column($accounts, 'account_id');
        $inIds  = implode(',', array_fill(0, count($accIds), '?'));
        $budgetSql = "SELECT gl_account_id, period_month, amount_cents FROM gl_budgets WHERE company_id = ? AND period_year = ? AND project_id IS NULL AND gl_account_id IN ($inIds)";
        $budgetParams = [$companyId, $year];
        foreach ($accIds as $id) $budgetParams[] = $id;
        $stmt = $DB->prepare($budgetSql);
        $stmt->execute($budgetParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $aid  = (int)$row['gl_account_id'];
            $month = (int)$row['period_month'];
            $budgets[$aid][$month] = (int)$row['amount_cents'];
        }
    }

    // Prepare actuals array keyed by account_id and month
    $actuals = [];
    if (!empty($accounts)) {
        // Build account code list for join
        // We'll join journal_lines on account_code, using gl_accounts for account code and type
        // Query calculates positive actuals for both income and expense using case expressions
        $accCodes = [];
        foreach ($accounts as $acc) {
            $accCodes[] = $acc['account_code'];
        }
        $placeholders = implode(',', array_fill(0, count($accCodes), '?'));
        $params = [];
        // Build SQL
        $sql = "SELECT ga.account_id, MONTH(je.entry_date) AS m,
                SUM(
                    CASE
                        WHEN ga.account_type = 'income' THEN COALESCE(jl.credit_cents, jl.credit*100) - COALESCE(jl.debit_cents, jl.debit*100)
                        ELSE COALESCE(jl.debit_cents, jl.debit*100) - COALESCE(jl.credit_cents, jl.credit*100)
                    END
                ) AS actual_cents
                FROM journal_lines jl
                JOIN journal_entries je ON je.id = jl.journal_id
                JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = ?
                WHERE je.company_id = ? AND YEAR(je.entry_date) = ? AND ga.account_code IN ($placeholders)
                GROUP BY ga.account_id, m";
        $params[] = $companyId;
        $params[] = $companyId;
        $params[] = $year;
        foreach ($accCodes as $code) {
            $params[] = $code;
        }
        $stmt = $DB->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $aid  = (int)$row['account_id'];
            $month = (int)$row['m'];
            $actuals[$aid][$month] = (int) $row['actual_cents'];
        }
    }

    // Build response accounts array
    $respAccounts = [];
    foreach ($accounts as $acc) {
        $aid = (int) $acc['account_id'];
        $acctRow = [
            'account_id'   => $aid,
            'account_code' => $acc['account_code'],
            'account_name' => $acc['account_name'],
            'account_type' => $acc['account_type'],
            'budget_cents' => [],
            'actual_cents' => []
        ];
        for ($m=1; $m<=12; $m++) {
            $acctRow['budget_cents'][$m] = isset($budgets[$aid][$m]) ? (int)$budgets[$aid][$m] : 0;
            $acctRow['actual_cents'][$m] = isset($actuals[$aid][$m]) ? (int)$actuals[$aid][$m] : 0;
        }
        $respAccounts[] = $acctRow;
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'company_name' => $companyName,
            'year' => $year,
            'accounts' => $respAccounts
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

?>