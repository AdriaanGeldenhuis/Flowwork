<?php
// /finances/ajax/ar_aging.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    $stmt = $DB->prepare("
        SELECT 
            c.name as customer_name,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 0 THEN i.balance_due 
                ELSE 0 
            END) as current,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.balance_due 
                ELSE 0 
            END) as days_1_30,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.balance_due 
                ELSE 0 
            END) as days_31_60,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.balance_due 
                ELSE 0 
            END) as days_61_90,
            SUM(CASE 
                WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.balance_due 
                ELSE 0 
            END) as days_90_plus,
            SUM(i.balance_due) as total
        FROM invoices i
        LEFT JOIN crm_accounts c ON i.customer_id = c.id
        WHERE i.company_id = ?
        AND i.status != 'paid'
        AND i.balance_due > 0
        GROUP BY c.id, c.name
        HAVING total > 0
        ORDER BY total DESC
    ");
    $stmt->execute([$companyId]);
    $aging = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => $aging
    ]);

} catch (Exception $e) {
    error_log("AR aging error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate aging report'
    ]);
}