<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/budget_variance.php
// Returns budgets vs actual amounts per account for a given year and optional project.
// The response includes, for each account, budgeted and actual amounts per month.

// Dynamically load init, auth, and permissions depending on project structure.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}

// Allow admin, bookkeeper or viewer to access
requireRoles(['admin','bookkeeper','viewer']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Input: year, optional type (income|expense|all), optional project_id
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = intval(date('Y'));
}
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
if (!in_array($type, ['income','expense','all',''])) {
    $type = '';
}
$projectId = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? intval($_GET['project_id']) : null;

try {
    // Fetch accounts based on type
    $accSql = "SELECT account_id, account_code, account_name, account_type\n" .
              "FROM gl_accounts WHERE company_id = ? AND is_active = 1";
    $params = [$companyId];
    if ($type === 'income' || $type === 'expense') {
        $accSql .= " AND account_type = ?";
        $params[] = $type;
    } else {
        // Limit to income and expense accounts when type empty or 'all'
        $accSql .= " AND account_type IN ('income','expense')";
    }
    $accSql .= " ORDER BY account_code";
    $stmtAcc = $DB->prepare($accSql);
    $stmtAcc->execute($params);
    $accounts = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);
    // Prepare budgets keyed by account_id and month
    $budgets = [];
    if (!empty($accounts)) {
        $accIds = array_column($accounts, 'account_id');
        $inIds  = implode(',', array_fill(0, count($accIds), '?'));
        $budgetSql = "SELECT gl_account_id, period_month, amount_cents FROM gl_budgets\n" .
                     "WHERE company_id = ? AND period_year = ?";
        $budgetParams = [$companyId, $year];
        if ($projectId === null) {
            $budgetSql .= " AND project_id IS NULL";
        } else {
            $budgetSql .= " AND project_id = ?";
            $budgetParams[] = $projectId;
        }
        if (!empty($accIds)) {
            $budgetSql .= " AND gl_account_id IN ($inIds)";
            foreach ($accIds as $id) $budgetParams[] = $id;
        }
        $stmtBud = $DB->prepare($budgetSql);
        $stmtBud->execute($budgetParams);
        $rows = $stmtBud->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $aid  = (int)$row['gl_account_id'];
            $month = (int)$row['period_month'];
            $budgets[$aid][$month] = (int)$row['amount_cents'];
        }
    }
    // Prepare actuals keyed by account_id and month
    $actuals = [];
    if (!empty($accounts)) {
        $accCodes = [];
        foreach ($accounts as $acc) {
            $accCodes[] = $acc['account_code'];
        }
        $placeholders = implode(',', array_fill(0, count($accCodes), '?'));
        // Determine project filtering for journal lines
        $sql = "SELECT ga.account_id, MONTH(je.entry_date) AS m,\n" .
               "SUM( CASE\n" .
               "      WHEN ga.account_type = 'income' THEN COALESCE(jl.credit_cents, jl.credit*100) - COALESCE(jl.debit_cents, jl.debit*100)\n" .
               "      WHEN ga.account_type = 'expense' THEN COALESCE(jl.debit_cents, jl.debit*100) - COALESCE(jl.credit_cents, jl.credit*100)\n" .
               "      ELSE COALESCE(jl.debit_cents, jl.debit*100) - COALESCE(jl.credit_cents, jl.credit*100)\n" .
               "    END ) AS actual_cents\n" .
               "FROM journal_lines jl\n" .
               "JOIN journal_entries je ON je.id = jl.journal_id\n" .
               "JOIN gl_accounts ga ON (ga.account_code = jl.account_code OR ga.account_id = jl.account_id) AND ga.company_id = ?\n" .
               "WHERE je.company_id = ? AND YEAR(je.entry_date) = ?";
        $params = [$companyId, $companyId, $year];
        if ($projectId !== null) {
            // Only include lines for matching project_id; if journal_lines.project_id exists
            $sql .= " AND jl.project_id = ?";
            $params[] = $projectId;
        }
        if (!empty($accCodes)) {
            $sql .= " AND ga.account_code IN ($placeholders)";
            foreach ($accCodes as $c) $params[] = $c;
        }
        $sql .= " GROUP BY ga.account_id, m";
        $stmtAct = $DB->prepare($sql);
        $stmtAct->execute($params);
        $rows = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $aid  = (int)$row['account_id'];
            $m = (int)$row['m'];
            $actuals[$aid][$m] = (int)$row['actual_cents'];
        }
    }
    // Build response array
    $resp = [];
    foreach ($accounts as $acc) {
        $aid = (int)$acc['account_id'];
        $row = [
            'account_id'   => $aid,
            'account_code' => $acc['account_code'],
            'account_name' => $acc['account_name'],
            'account_type' => $acc['account_type'],
            'budget_cents' => [],
            'actual_cents' => []
        ];
        for ($m = 1; $m <= 12; $m++) {
            $row['budget_cents'][$m] = $budgets[$aid][$m] ?? 0;
            $row['actual_cents'][$m] = $actuals[$aid][$m] ?? 0;
        }
        $resp[] = $row;
    }
    echo json_encode(['ok' => true, 'data' => [
        'year' => $year,
        'project_id' => $projectId,
        'accounts' => $resp
    ]]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
