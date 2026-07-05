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

require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/../lib/AccountsMap.php';

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

// Guard against CSV formula injection: prefix a single quote to any cell that
// starts with =, +, -, @, tab or carriage return so spreadsheet apps treat it
// as text. Plain numeric values (e.g. "-123.45") are left intact.
function csvGuardCell($value) {
    if (!is_string($value) || $value === '') {
        return $value;
    }
    $first = $value[0];
    if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
        if (($first === '-' || $first === '+') && is_numeric($value)) {
            return $value;
        }
        return "'" . $value;
    }
    return $value;
}

// Helper to send CSV
function sendCsv($filename, array $rows) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=' . $filename);
    $out = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csvGuardCell', $row));
    }
    fclose($out);
    exit;
}

try {
    switch ($report) {
        case 'tb':
            // Trial balance as of a date
            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $DB->prepare(
                "SELECT ga.account_id, ga.account_code, ga.account_name,
                       COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit ELSE 0 END),0) * 100 AS debit_cents,
                       COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit ELSE 0 END),0) * 100 AS credit_cents
                FROM gl_accounts ga
                LEFT JOIN journal_lines jl ON ga.account_code = jl.account_code
                LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
                WHERE ga.company_id = ?
                GROUP BY ga.account_id, ga.account_code, ga.account_name
                ORDER BY ga.account_code"
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
            // Profit & Loss for a period. Mirrors report_pl.php: end date from
            // ?date, start date from ?start_date, defaulting to the company's
            // fiscal year start; year-end closing journals are excluded.
            $date = $_GET['date'] ?? date('Y-m-d');
            $dtEnd = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dtEnd || $dtEnd->format('Y-m-d') !== $date) {
                $date = date('Y-m-d');
            }
            $startDate = $_GET['start_date'] ?? null;
            if ($startDate) {
                $dtStart = DateTime::createFromFormat('Y-m-d', $startDate);
                if (!$dtStart || $dtStart->format('Y-m-d') !== $startDate) {
                    $startDate = null;
                }
            }
            if (!$startDate) {
                $startDate = getFiscalYearStart($DB, (int)$companyId, $date);
            }
            // Revenue
            $stmtR = $DB->prepare(
                "SELECT ga.account_id, ga.account_code, ga.account_name,
                COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN (jl.credit - jl.debit) ELSE 0 END),0) * 100 AS balance
                FROM gl_accounts ga
                LEFT JOIN journal_lines jl ON ga.account_code = jl.account_code
                LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
                    AND (je.module IS NULL OR je.module <> 'year_end')
                WHERE ga.company_id = ? AND ga.account_type = 'revenue'
                GROUP BY ga.account_id, ga.account_code, ga.account_name
                ORDER BY ga.account_code"
            );
            $stmtR->execute([$startDate, $date, $companyId, $companyId]);
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
                "SELECT ga.account_id, ga.account_code, ga.account_name,
                COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN (jl.debit - jl.credit) ELSE 0 END),0) * 100 AS balance
                FROM gl_accounts ga
                LEFT JOIN journal_lines jl ON ga.account_code = jl.account_code
                LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
                    AND (je.module IS NULL OR je.module <> 'year_end')
                WHERE ga.company_id = ? AND ga.account_type = 'expense'
                GROUP BY ga.account_id, ga.account_code, ga.account_name
                ORDER BY ga.account_code"
            );
            $stmtE->execute([$startDate, $date, $companyId, $companyId]);
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
                sendCsv('profit_and_loss_' . $startDate . '_to_' . $date . '.csv', $rows);
            }
            echo json_encode(['ok' => true, 'data' => [
                'start_date' => $startDate,
                'end_date' => $date,
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
            $types = ['asset', 'liability', 'equity'];
            $results = [];
            foreach ($types as $type) {
                // Assets are normal-debit (debit - credit); liabilities and equity are
                // normal-credit (credit - debit). Use the correct expression per type
                // so that the balance sheet shows positive values on the natural side.
                $balanceExpr = ($type === 'asset') ? '(jl.debit - jl.credit)' : '(jl.credit - jl.debit)';
                $stmtBS = $DB->prepare(
                    "SELECT ga.account_id, ga.account_code, ga.account_name,
                    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN $balanceExpr ELSE 0 END),0) * 100 AS balance
                    FROM gl_accounts ga
                    LEFT JOIN journal_lines jl ON ga.account_code = jl.account_code
                    LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
                    WHERE ga.company_id = ? AND ga.account_type = ?
                    GROUP BY ga.account_id, ga.account_code, ga.account_name
                    ORDER BY ga.account_code"
                );
                $stmtBS->execute([$date, $companyId, $companyId, $type]);
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

            // Inject Current Year Earnings into equity, mirroring
            // report_balance_sheet.php, so Assets = Liabilities + Equity holds.
            // NOTE: unlike the P&L, this deliberately INCLUDES year-end closing
            // journals — after a close the closed year's P&L nets to zero here
            // and its profit lives in retained earnings instead.
            $stmtNI = $DB->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN ga.account_type = 'revenue' AND je.entry_date <= ? THEN jl.credit - jl.debit ELSE 0 END),0) AS revenue,
                    COALESCE(SUM(CASE WHEN ga.account_type = 'expense' AND je.entry_date <= ? THEN jl.debit - jl.credit ELSE 0 END),0) AS expenses
                FROM gl_accounts ga
                LEFT JOIN journal_lines jl ON ga.account_code = jl.account_code
                LEFT JOIN journal_entries je ON jl.journal_id = je.id AND je.company_id = ? AND je.status = 'posted'
                WHERE ga.company_id = ? AND ga.account_type IN ('revenue','expense')"
            );
            $stmtNI->execute([$date, $date, $companyId, $companyId]);
            $plData = $stmtNI->fetch(PDO::FETCH_ASSOC);
            $netIncomeCents = (int) round((floatval($plData['revenue']) - floatval($plData['expenses'])) * 100);
            if ($netIncomeCents != 0) {
                $cyeCode = '3300';
                try {
                    $accountsMap = new AccountsMap($DB, (int)$companyId);
                    $cyeCode = $accountsMap->get('finance_current_year_earnings_account_id', '3300');
                } catch (Exception $e) {
                    // keep fallback code
                }
                $results['equity'][] = [
                    'account_id'    => null,
                    'account_code'  => $cyeCode,
                    'account_name'  => 'Current Year Earnings',
                    'balance_cents' => $netIncomeCents
                ];
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
            $sql = "SELECT je.entry_date, je.id AS journal_id,
                   jl.account_code,
                   ga.account_name,
                   (jl.debit * 100) AS debit_cents, (jl.credit * 100) AS credit_cents,
                   je.description
                FROM journal_entries je
                JOIN journal_lines jl ON jl.journal_id = je.id
                LEFT JOIN gl_accounts ga ON ga.account_code = jl.account_code AND ga.company_id = je.company_id
                WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?";
            if ($accountCode) {
                $sql .= " AND jl.account_code = ?";
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
    if ($export) {
        header('Content-Type: text/plain');
        echo 'Error generating report. Please try again.';
    } else {
        echo json_encode(['ok' => false, 'error' => 'Failed to generate report']);
    }
}
