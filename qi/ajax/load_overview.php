<?php
// /qi/ajax/load_overview.php - ENHANCED VERSION
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    // ===== FINANCIAL STATS =====
    $stmt = $DB->prepare("
        SELECT 
            (SELECT COUNT(*) FROM quotes WHERE company_id = ? AND status IN ('draft','sent','viewed')) as active_quotes,
            (SELECT COUNT(*) FROM invoices WHERE company_id = ? AND status = 'overdue') as overdue_invoices,
            (SELECT COUNT(*) FROM invoices WHERE company_id = ? AND status IN ('sent','viewed')) as pending_invoices,
            (SELECT SUM(balance_due) FROM invoices WHERE company_id = ? AND status IN ('sent','viewed','overdue')) as outstanding_amount,
            (SELECT SUM(total) FROM invoices WHERE company_id = ? AND status = 'paid' AND MONTH(paid_at) = MONTH(CURRENT_DATE())) as paid_this_month,
            (SELECT SUM(total) FROM invoices WHERE company_id = ? AND status = 'paid' AND YEAR(paid_at) = YEAR(CURRENT_DATE())) as paid_this_year,
            (SELECT AVG(DATEDIFF(paid_at, issue_date)) FROM invoices WHERE company_id = ? AND status = 'paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) as avg_payment_days
    ");
    $stmt->execute([$companyId, $companyId, $companyId, $companyId, $companyId, $companyId, $companyId]);
    $stats = $stmt->fetch();

    // ===== OVERDUE INVOICES (urgent attention) =====
    $stmt = $DB->prepare("
        SELECT i.id, i.invoice_number, i.due_date, i.balance_due, i.issue_date,
               ca.name AS customer_name,
               DATEDIFF(CURDATE(), i.due_date) as days_overdue
        FROM invoices i
        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
        WHERE i.company_id = ?
        AND i.status = 'overdue'
        ORDER BY i.due_date ASC
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $overdueInvoices = $stmt->fetchAll();

    // ===== PENDING INVOICES (due soon) =====
    $stmt = $DB->prepare("
        SELECT i.id, i.invoice_number, i.due_date, i.balance_due, i.issue_date,
               ca.name AS customer_name,
               DATEDIFF(i.due_date, CURDATE()) as days_until_due
        FROM invoices i
        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
        WHERE i.company_id = ?
        AND i.status IN ('sent', 'viewed')
        ORDER BY i.due_date ASC
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $pendingInvoices = $stmt->fetchAll();

    // ===== RECENT QUOTES (awaiting response) =====
    $stmt = $DB->prepare("
        SELECT q.id, q.quote_number, q.expiry_date, q.total, q.status, q.issue_date,
               ca.name AS customer_name,
               DATEDIFF(q.expiry_date, CURDATE()) as days_until_expiry
        FROM quotes q
        LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
        WHERE q.company_id = ?
        AND q.status IN ('sent', 'viewed')
        ORDER BY q.expiry_date ASC
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $activeQuotes = $stmt->fetchAll();

    // ===== RECENT PAYMENTS =====
    $stmt = $DB->prepare("
        SELECT p.id, p.payment_date, p.amount, p.method,
               i.invoice_number,
               ca.name AS customer_name
        FROM payments p
        LEFT JOIN payment_allocations pa ON p.id = pa.payment_id
        LEFT JOIN invoices i ON pa.invoice_id = i.id
        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
        WHERE p.company_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $recentPayments = $stmt->fetchAll();

    // ===== MONTHLY REVENUE CHART (last 6 months) =====
    $stmt = $DB->prepare("
        SELECT 
            DATE_FORMAT(paid_at, '%Y-%m') as month,
            SUM(total) as revenue,
            COUNT(*) as invoice_count
        FROM invoices
        WHERE company_id = ?
        AND status = 'paid'
        AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute([$companyId]);
    $revenueChart = $stmt->fetchAll();

    // ===== TOP CUSTOMERS (by revenue this year) =====
    $stmt = $DB->prepare("
        SELECT 
            ca.id,
            ca.name,
            SUM(i.total) as total_revenue,
            COUNT(i.id) as invoice_count
        FROM invoices i
        LEFT JOIN crm_accounts ca ON i.customer_id = ca.id
        WHERE i.company_id = ?
        AND i.status = 'paid'
        AND YEAR(i.paid_at) = YEAR(CURRENT_DATE())
        GROUP BY ca.id, ca.name
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    $stmt->execute([$companyId]);
    $topCustomers = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'stats' => $stats,
        'overdue_invoices' => $overdueInvoices,
        'pending_invoices' => $pendingInvoices,
        'active_quotes' => $activeQuotes,
        'recent_payments' => $recentPayments,
        'revenue_chart' => $revenueChart,
        'top_customers' => $topCustomers
    ]);

} catch (Exception $e) {
    error_log("Load overview error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load overview']);
}