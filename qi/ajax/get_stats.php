<?php
// /qi/ajax/get_stats.php
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json');

$company_id = $_SESSION['company_id'];

try {
    // Open quotes
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM quotes 
        WHERE company_id = ? 
        AND status IN ('draft', 'sent', 'viewed')
    ");
    $stmt->execute([$company_id]);
    $open_quotes = $stmt->fetchColumn();

    // Outstanding invoices
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM invoices 
        WHERE company_id = ? 
        AND status IN ('sent', 'viewed', 'overdue')
        AND balance_due > 0
    ");
    $stmt->execute([$company_id]);
    $outstanding_invoices = $stmt->fetchColumn();

    // Overdue invoices
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM invoices 
        WHERE company_id = ? 
        AND status = 'overdue'
        AND balance_due > 0
    ");
    $stmt->execute([$company_id]);
    $overdue_invoices = $stmt->fetchColumn();

    // Paid this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM invoices 
        WHERE company_id = ? 
        AND status = 'paid'
        AND MONTH(paid_at) = MONTH(CURRENT_DATE())
        AND YEAR(paid_at) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$company_id]);
    $paid_this_month = $stmt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'stats' => [
            'open_quotes' => $open_quotes,
            'outstanding_invoices' => $outstanding_invoices,
            'overdue_invoices' => $overdue_invoices,
            'paid_this_month' => $paid_this_month
        ]
    ]);

} catch (Exception $e) {
    error_log("QI stats error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load stats']);
}