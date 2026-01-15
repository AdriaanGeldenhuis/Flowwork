<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_export.php
// Handles generation of various finance reports (trial balance, P&L, balance sheet,
// general ledger detail) and optionally exports them as CSV. For JSON
// responses, returns structured data; for CSV export, sets appropriate
// headers and outputs CSV directly.

// Dynamically load init, auth and permissions. Supports both /app and root-level structures.
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

// Allow admin, bookkeeper and viewer roles to fetch reports
requireRoles(['admin','bookkeeper','viewer']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get parameters
$report = $_GET['report'] ?? '';
$export = isset($_GET['export']);

// Helper to send CSV
function sendCsv($filename, array $rows) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=' . $filename);
    $out = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

try {
    switch ($report) {
        case 'tb':
            // Trial balance as of a date
            $date = $_GET['date'] ?? date('Y-m-d');
            // Fetch account balances
            $stmt = $DB->prepare(
                "SELECT ga.account_id, ga.account_code, ga.account_name,\n" .
                "       COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit_cents ELSE 0 END),0) AS debit_cents,\n" .
                "       COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit_cents ELSE 0 END),0) AS credit_cents\n" .
                "FROM gl_accounts ga\n" .
                "LEFT JOIN journal_lines jl ON (ga.account_id = jl.account_id OR ga.account_code = jl.account_code)\n" .
                "LEFT JOIN journal_entries je ON jl.journal_id = je.id\n" .
                "WHERE ga.company_id = ? AND (je.company_id IS NULL OR je.company_id = ?)\n" .
                "GROUP BY ga.account_id, ga.account_code, ga.account_name\n" .
                "ORDER BY ga.account_code"
            );
            $stmt->execute([$date, $date, $companyId, $companyId]);
            $accounts = [];
            $totalDebit = 0;
            $totalCredit = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $debit = (int)$row['debit_cents'];
                $credit = (int)$row['credit_cents'];
                $accounts[] = [
                    'account_id' => $row['account_id'],
                    'account_code' => $row['account_code'],
                    'account_name' => $row['account_name'],
                    'debit_cents' => $debit,
                    'credit_cents' => $credit
                ];
                $totalDebit += $debit;
                $totalCredit += $credit;
            }
            if ($export) {
                $rows = [];
                $rows[] = ['Account Code','Account Name','Debit','Credit'];
                foreach ($accounts as $acc) {
                    $rows[] = [
                        $acc['account_code'],
                        $acc['account_name'],
                        number_format($acc['debit_cents']/100, 2, '.', ''),
                        number_format($acc['credit_cents']/100, 2, '.', '')
                    ];
                }
                $rows[] = ['Total','', number_format($totalDebit/100, 2, '.', ''), number_format($totalCredit/100, 2, '.', '')];
                sendCsv('trial_balance_' . $date . '.csv', $rows);
            }
            echo json_encode(['ok' => true, 'data' => ['accounts' => $accounts, 'total_debit_cents' => $totalDebit, 'total_credit_cents' => $totalCredit]]);
            break;

        case 'pl':
            // Profit & Loss up to date
            $date = $_GET['date'] ?? date('Y-m-d');
            // Revenue
            $stmtR = $DB->prepare(
                "SELECT ga.account_id, ga.account_code, ga.account_name,\n" .
                "COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit_cents - jl.debit_cents ELSE 0 END),0) AS balance\n" .
                "FROM gl_accounts ga\n" .
                "LEFT JOIN journal_lines jl ON (ga.account_id = jl.account_id OR ga.account_code = jl.account_code)\n" .
                "LEFT JOIN journal_entries je ON jl.journal_id = je.id\n" .
                "WHERE ga.company_id = ? AND ga.account_type = 'revenue' AND (je.company_id IS NULL OR je.company_id = ?)\n" .
                "GROUP BY ga.account_id, ga.account_code, ga.account_name\n" .
                "ORDER BY ga.account_code"
            );
            $stmtR->execute([$date, $companyId, $companyId]);
            $revenue = [];
            $totalRevenue = 0;
            while ($row = $stmtR->fetch(PDO::FETCH_ASSOC)) {
                $balance = (int)$row['balance'];
                if ($balance != 0) {
                    $revenue[] = [
                        'account_id' => $row['account_id'],
                        'account_code' => $row['account_code'],
                        'account_name' => $row['account_name'],
                        'balance_cents' => $balance
                    ];
                    $totalRevenue += $balance;
                }
            }
            // Expenses
            $stmtE = $DB->prepare(
                "SELECT ga.account_id, ga.account_code, ga.account_name,\n" .
                "COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit_cents - jl.credit_cents ELSE 0 END),0) AS balance\n" .
                "FROM gl_accounts ga\n" .
                "LEFT JOIN journal_lines jl ON (ga.account_id = jl.account_id OR ga.account_code = jl.account_code)\n" .
                "LEFT JOIN journal_entries je ON jl.journal_id = je.id\n" .
                "WHERE ga.company_id = ? AND ga.account_type = 'expense' AND (je.company_id IS NULL OR je.company_id = ?)\n" .
                "GROUP BY ga.account_id, ga.account_code, ga.account_name\n" .
                "ORDER BY ga.account_code"
            );
            $stmtE->execute([$date, $companyId, $companyId]);
            $expenses = [];
            $totalExpenses = 0;
            while ($row = $stmtE->fetch(PDO::FETCH_ASSOC)) {
                $balance = (int)$row['balance'];
                if ($balance != 0) {
                    $expenses[] = [
                        'account_id' => $row['account_id'],
                        'account_code' => $row['account_code'],
                        'account_name' => $row['account_name'],
                        'balance_cents' => $balance
                    ];
                    $totalExpenses += $balance;
                }
            }
            $netIncome = $totalRevenue - $totalExpenses;
            if ($export) {
                $rows = [];
                $rows[] = ['Section','Account Code','Account Name','Amount'];
                foreach ($revenue as $row) {
                    $rows[] = ['Revenue', $row['account_code'], $row['account_name'], number_format($row['balance_cents']/100, 2, '.', '')];
                }
                $rows[] = ['Revenue','','Total Revenue', number_format($totalRevenue/100, 2, '.', '')];
                foreach ($expenses as $row) {
                    $rows[] = ['Expenses', $row['account_code'], $row['account_name'], number_format($row['balance_cents']/100, 2, '.', '')];
                }
                $rows[] = ['Expenses','','Total Expenses', number_format($totalExpenses/100, 2, '.', '')];
                $rows[] = ['','Net Income','', number_format($netIncome/100, 2, '.', '')];
                sendCsv('profit_and_loss_' . $date . '.csv', $rows);
            }
            echo json_encode(['ok' => true, 'data' => [
                'revenue' => $revenue,
                'expenses' => $expenses,
                'total_revenue_cents' => $totalRevenue,
                'total_expenses_cents' => $totalExpenses,
                'net_income_cents' => $netIncome
            ]]);
            break;

        case 'bs':
            // Balance sheet as of date
            $date = $_GET['date'] ?? date('Y-m-d');
            // Query per account type
            $types = ['asset', 'liability', 'equity'];
            $results = [];
            foreach ($types as $type) {
                $stmtBS = $DB->prepare(
                    "SELECT ga.account_id, ga.account_code, ga.account_name,\n" .
                    "COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit_cents - jl.credit_cents ELSE 0 END),0) AS balance\n" .
                    "FROM gl_accounts ga\n" .
                    "LEFT JOIN journal_lines jl ON (ga.account_id = jl.account_id OR ga.account_code = jl.account_code)\n" .
                    "LEFT JOIN journal_entries je ON jl.journal_id = je.id\n" .
                    "WHERE ga.company_id = ? AND ga.account_type = ? AND (je.company_id IS NULL OR je.company_id = ?)\n" .
                    "GROUP BY ga.account_id, ga.account_code, ga.account_name\n" .
                    "ORDER BY ga.account_code"
                );
                $stmtBS->execute([$date, $companyId, $type, $companyId]);
                $list = [];
                while ($row = $stmtBS->fetch(PDO::FETCH_ASSOC)) {
                    $bal = (int)$row['balance'];
                    if ($bal != 0) {
                        $list[] = [
                            'account_id' => $row['account_id'],
                            'account_code' => $row['account_code'],
                            'account_name' => $row['account_name'],
                            'balance_cents' => $bal
                        ];
                    }
                }
                $results[$type] = $list;
            }
            if ($export) {
                $rows = [];
                $rows[] = ['Section','Account Code','Account Name','Balance'];
                foreach (['asset','liability','equity'] as $type) {
                    $section = ucfirst($type) . 's';
                    $total = 0;
                    foreach ($results[$type] as $row) {
                        $rows[] = [$section, $row['account_code'], $row['account_name'], number_format($row['balance_cents']/100,2,'.','')];
                        $total += $row['balance_cents'];
                    }
                    $rows[] = [$section,'Total', '', number_format($total/100,2,'.','')];
                }
                sendCsv('balance_sheet_' . $date . '.csv', $rows);
            }
            echo json_encode(['ok' => true, 'data' => [
                'assets' => $results['asset'] ?? [],
                'liabilities' => $results['liability'] ?? [],
                'equity' => $results['equity'] ?? []
            ]]);
            break;

        case 'gl_detail':
            // General ledger detail between start_date and end_date, optional account_code
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $accountCode = $_GET['account_code'] ?? '';
            if (!$startDate || !$endDate) {
                echo json_encode(['ok' => false, 'error' => 'start_date and end_date are required']);
                exit;
            }
            $params = [$companyId, $startDate, $endDate];
            $sql = "SELECT je.entry_date, je.id AS journal_id,\n" .
                   "       COALESCE(jl.account_code, ga.account_code) AS account_code,\n" .
                   "       ga.account_name,\n" .
                   "       jl.debit_cents, jl.credit_cents,\n" .
                   "       je.memo AS description\n" .
                   "FROM journal_entries je\n" .
                   "JOIN journal_lines jl ON jl.journal_id = je.id\n" .
                   "LEFT JOIN gl_accounts ga ON (ga.account_id = jl.account_id OR ga.account_code = jl.account_code) AND ga.company_id = je.company_id\n" .
                   "WHERE je.company_id = ? AND je.entry_date BETWEEN ? AND ?";
            if ($accountCode) {
                $sql .= " AND (jl.account_code = ? OR ga.account_code = ?)";
                $params[] = $accountCode;
                $params[] = $accountCode;
            }
            $sql .= " ORDER BY je.entry_date, je.id";
            $stmt = $DB->prepare($sql);
            $stmt->execute($params);
            $lines = [];
            $totalDebit = 0;
            $totalCredit = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $d = (int)$row['debit_cents'];
                $c = (int)$row['credit_cents'];
                $lines[] = [
                    'entry_date' => $row['entry_date'],
                    'journal_id' => $row['journal_id'],
                    'account_code' => $row['account_code'],
                    'account_name' => $row['account_name'],
                    'description' => $row['description'],
                    'debit_cents' => $d,
                    'credit_cents' => $c
                ];
                $totalDebit += $d;
                $totalCredit += $c;
            }
            if ($export) {
                $rows = [];
                $rows[] = ['Date','Journal ID','Account Code','Account Name','Description','Debit','Credit'];
                foreach ($lines as $line) {
                    $rows[] = [
                        $line['entry_date'],
                        $line['journal_id'],
                        $line['account_code'],
                        $line['account_name'],
                        $line['description'],
                        number_format($line['debit_cents']/100, 2, '.', ''),
                        number_format($line['credit_cents']/100, 2, '.', '')
                    ];
                }
                $rows[] = ['Totals','','','','', number_format($totalDebit/100,2,'.',''), number_format($totalCredit/100,2,'.','')];
                $filename = 'gl_detail_' . $startDate . '_to_' . $endDate . '.csv';
                sendCsv($filename, $rows);
            }
            echo json_encode(['ok' => true, 'data' => ['lines' => $lines, 'total_debit_cents' => $totalDebit, 'total_credit_cents' => $totalCredit]]);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown report type']);
    }
} catch (Exception $e) {
    error_log('Report error: ' . $e->getMessage());
    // If export, we cannot output JSON properly; just show error.
    if ($export) {
        header('Content-Type: text/plain');
        echo 'Error generating report: ' . $e->getMessage();
    } else {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
