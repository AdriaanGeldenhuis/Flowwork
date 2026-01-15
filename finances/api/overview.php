<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/api/overview.php
// Provides aggregated data for the finance overview dashboard.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure user is authenticated and company context is available
$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// Use the existing database connection
$db = $DB;

// Extract query parameters
$widget = isset($_GET['w']) ? trim($_GET['w']) : '';
$period = isset($_GET['period']) ? strtolower(trim($_GET['period'])) : '';
$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : null;
$to = isset($_GET['to']) && $_GET['to'] !== '' ? $_GET['to'] : null;

/**
 * Compute the date boundaries for a given period based on the company's fiscal year start.
 * If explicit from/to parameters are provided, those take precedence.
 *
 * @param PDO $db
 * @param int $companyId
 * @param string|null $period
 * @param string|null $fromParam
 * @param string|null $toParam
 * @return array
 */
function fy_bounds(PDO $db, int $companyId, ?string $period, ?string $fromParam, ?string $toParam): array {
    // Always work in Africa/Johannesburg timezone
    date_default_timezone_set('Africa/Johannesburg');
    $today = new DateTimeImmutable('today');

    // Default fiscal year start month (January)
    $fyStartMonth = 1;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'finance_fiscal_year_start' LIMIT 1");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['setting_value'])) {
            $monthName = $row['setting_value'];
            $monthNumber = DateTimeImmutable::createFromFormat('!F', $monthName);
            if ($monthNumber) {
                $fyStartMonth = (int)$monthNumber->format('n');
            }
        }
    } catch (Exception $e) {
        // Ignore failures and fall back to January
    }

    // Honour explicit date range if provided
    if ($fromParam && $toParam) {
        return ['from' => $fromParam, 'to' => $toParam];
    }

    // Determine the current fiscal year start and end
    $currentMonth = (int)$today->format('n');
    $currentYear = (int)$today->format('Y');

    // If the fiscal year starts after the current month, then the fiscal year started last year
    $fyYearStart = ($currentMonth < $fyStartMonth) ? ($currentYear - 1) : $currentYear;
    $fyStart = DateTimeImmutable::createFromFormat('Y-n-j', $fyYearStart . '-' . $fyStartMonth . '-1');
    $fyEnd = $fyStart->modify('+1 year')->modify('-1 day');

    // Default start and end
    $start = $fyStart;
    $end = $today;

    $period = $period ?: 'mtd';
    switch ($period) {
        case 'fy':
            $start = $fyStart;
            $end = $fyEnd;
            break;
        case 'ytd':
            $start = $fyStart;
            $end = $today;
            break;
        case 'qtd':
            // Months difference from fiscal year start
            $monthsDiff = (($currentYear - (int)$fyStart->format('Y')) * 12) + ($currentMonth - $fyStartMonth);
            $quarterIndex = intdiv($monthsDiff, 3);
            $quarterStart = $fyStart->modify('+' . ($quarterIndex * 3) . ' months');
            $start = $quarterStart;
            $end = $today;
            break;
        case 'mtd':
        default:
            $start = new DateTimeImmutable($today->format('Y-m-01'));
            $end = $today;
            break;
    }

    return [
        'from' => $start->format('Y-m-d'),
        'to'   => $end->format('Y-m-d')
    ];
}

try {
    $bounds = fy_bounds($db, $companyId, $period, $from, $to);
    $dateFrom = $bounds['from'];
    $dateTo = $bounds['to'];

    switch ($widget) {
        case 'kpis':
            $data = [];
            // Cash on hand from active bank accounts
            $stmt = $db->prepare("SELECT COALESCE(SUM(current_balance_cents),0) AS cash_cents FROM gl_bank_accounts WHERE company_id = ? AND is_active = 1");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data['cash_cents'] = isset($row['cash_cents']) ? (int)$row['cash_cents'] : 0;

            // AR open
            $stmt = $db->prepare("SELECT COALESCE(SUM(balance_due),0) AS ar_open FROM invoices WHERE company_id = ? AND status IN ('sent','viewed','overdue')");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data['ar_open_cents'] = isset($row['ar_open']) ? (int)round($row['ar_open'] * 100) : 0;

            // Overdue invoices count and sum
            $stmt = $db->prepare("SELECT COUNT(*) AS overdue_count, COALESCE(SUM(balance_due),0) AS overdue_sum FROM invoices WHERE company_id = ? AND status = 'overdue'");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data['ar_overdue_count'] = isset($row['overdue_count']) ? (int)$row['overdue_count'] : 0;
            $data['ar_overdue_sum_cents'] = isset($row['overdue_sum']) ? (int)round($row['overdue_sum'] * 100) : 0;

            // Sales within the selected period
            $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS sales_period FROM invoices WHERE company_id = ? AND issue_date BETWEEN ? AND ? AND status IN ('sent','viewed','paid','overdue')");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data['sales_cents'] = isset($row['sales_period']) ? (int)round($row['sales_period'] * 100) : 0;

            // AP captured (OCR) not yet posted
            $stmt = $db->prepare("SELECT COALESCE(SUM(ro.total),0) AS ap_unposted FROM receipt_ocr ro JOIN receipt_file rf ON rf.file_id = ro.file_id WHERE rf.company_id = ? AND rf.ocr_status = 'parsed' AND ro.invoice_date BETWEEN ? AND ?");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data['ap_unposted_cents'] = isset($row['ap_unposted']) ? (int)round($row['ap_unposted'] * 100) : 0;

            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'revexp':
            $data = ['revenue' => [], 'expenses' => []];
            // Revenue by month
            $stmt = $db->prepare("SELECT DATE_FORMAT(issue_date, '%Y-%m-01') AS m, COALESCE(SUM(total),0) AS amt FROM invoices WHERE company_id = ? AND issue_date BETWEEN ? AND ? AND status IN ('sent','viewed','paid','overdue') GROUP BY m ORDER BY m");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data['revenue'][] = [
                    'month' => $row['m'],
                    'cents' => (int)round($row['amt'] * 100)
                ];
            }
            // Expenses by month from OCR
            $stmt = $db->prepare("SELECT DATE_FORMAT(ro.invoice_date, '%Y-%m-01') AS m, COALESCE(SUM(ro.total),0) AS amt FROM receipt_ocr ro JOIN receipt_file rf ON rf.file_id = ro.file_id WHERE rf.company_id = ? AND ro.invoice_date BETWEEN ? AND ? GROUP BY m ORDER BY m");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data['expenses'][] = [
                    'month' => $row['m'],
                    'cents' => (int)round($row['amt'] * 100)
                ];
            }
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'ar_aging':
            // Age accounts receivable buckets
            $stmt = $db->prepare("SELECT
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 0 THEN balance_due ELSE 0 END),0) AS bucket_current,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN balance_due ELSE 0 END),0) AS bucket_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN balance_due ELSE 0 END),0) AS bucket_60,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN balance_due ELSE 0 END),0) AS bucket_90,
                COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN balance_due ELSE 0 END),0) AS bucket_120
                FROM invoices
                WHERE company_id = ? AND status IN ('sent','viewed','overdue')");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data = [
                'bucket_current_cents' => (int)round(($row['bucket_current'] ?? 0) * 100),
                'bucket_30_cents'     => (int)round(($row['bucket_30'] ?? 0) * 100),
                'bucket_60_cents'     => (int)round(($row['bucket_60'] ?? 0) * 100),
                'bucket_90_cents'     => (int)round(($row['bucket_90'] ?? 0) * 100),
                'bucket_120_cents'    => (int)round(($row['bucket_120'] ?? 0) * 100),
            ];
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'banks':
            $stmt = $db->prepare("SELECT name, bank_name, account_no, current_balance_cents, last_reconciled_date FROM gl_bank_accounts WHERE company_id = ? AND is_active = 1 ORDER BY name");
            $stmt->execute([$companyId]);
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = [
                    'name' => $row['name'],
                    'bank_name' => $row['bank_name'],
                    'account_no' => $row['account_no'],
                    'balance_cents' => isset($row['current_balance_cents']) ? (int)$row['current_balance_cents'] : 0,
                    'last_reconciled_date' => $row['last_reconciled_date']
                ];
            }
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'vat':
            // Output VAT (invoice tax)
            $stmt = $db->prepare("SELECT COALESCE(SUM(tax),0) AS output_vat FROM invoices WHERE company_id = ? AND issue_date BETWEEN ? AND ? AND status IN ('sent','viewed','paid','overdue')");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $outputVat = isset($row['output_vat']) ? (float)$row['output_vat'] : 0.0;
            // Input VAT (OCR tax)
            $stmt = $db->prepare("SELECT COALESCE(SUM(ro.tax),0) AS input_vat FROM receipt_ocr ro JOIN receipt_file rf ON rf.file_id = ro.file_id WHERE rf.company_id = ? AND ro.invoice_date BETWEEN ? AND ?");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            $row2 = $stmt->fetch(PDO::FETCH_ASSOC);
            $inputVat = isset($row2['input_vat']) ? (float)$row2['input_vat'] : 0.0;
            $data = [
                'output_vat_cents' => (int)round($outputVat * 100),
                'input_vat_cents' => (int)round($inputVat * 100)
            ];
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'invoices_due':
            $stmt = $db->prepare("SELECT i.id, i.invoice_number, c.name AS customer, i.due_date, i.balance_due FROM invoices i JOIN crm_accounts c ON c.id = i.customer_id WHERE i.company_id = ? AND i.status IN ('sent','viewed') AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY i.due_date ASC LIMIT 5");
            $stmt->execute([$companyId]);
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = [
                    'id' => (int)$row['id'],
                    'invoice_number' => $row['invoice_number'],
                    'customer' => $row['customer'],
                    'due_date' => $row['due_date'],
                    'balance_due_cents' => isset($row['balance_due']) ? (int)round($row['balance_due'] * 100) : 0
                ];
            }
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'invoices_overdue':
            $stmt = $db->prepare("SELECT i.id, i.invoice_number, c.name AS customer, i.due_date, i.balance_due FROM invoices i JOIN crm_accounts c ON c.id = i.customer_id WHERE i.company_id = ? AND i.status = 'overdue' ORDER BY i.due_date ASC LIMIT 5");
            $stmt->execute([$companyId]);
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = [
                    'id' => (int)$row['id'],
                    'invoice_number' => $row['invoice_number'],
                    'customer' => $row['customer'],
                    'due_date' => $row['due_date'],
                    'balance_due_cents' => isset($row['balance_due']) ? (int)round($row['balance_due'] * 100) : 0
                ];
            }
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'recurring_upcoming':
            $stmt = $db->prepare("SELECT ri.customer_id, c.name AS customer, ri.next_run_date FROM recurring_invoices ri JOIN crm_accounts c ON c.id = ri.customer_id WHERE ri.company_id = ? AND ri.active = 1 AND ri.next_run_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) ORDER BY ri.next_run_date ASC LIMIT 5");
            $stmt->execute([$companyId]);
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = [
                    'customer_id' => (int)$row['customer_id'],
                    'customer' => $row['customer'],
                    'next_run_date' => $row['next_run_date']
                ];
            }
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        case 'receipts_unposted':
            $stmt = $db->prepare("SELECT ro.vendor_name, ro.invoice_number, ro.invoice_date, ro.total FROM receipt_ocr ro JOIN receipt_file rf ON rf.file_id = ro.file_id WHERE rf.company_id = ? AND rf.ocr_status = 'parsed' AND ro.invoice_date BETWEEN ? AND ? ORDER BY ro.invoice_date DESC LIMIT 5");
            $stmt->execute([$companyId, $dateFrom, $dateTo]);
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = [
                    'vendor_name' => $row['vendor_name'],
                    'invoice_number' => $row['invoice_number'],
                    'invoice_date' => $row['invoice_date'],
                    'total_cents' => isset($row['total']) ? (int)round($row['total'] * 100) : 0
                ];
            }
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown widget']);
            exit;
    }
} catch (Exception $e) {
    error_log('Finance overview API error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}