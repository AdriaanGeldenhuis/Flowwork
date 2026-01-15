<?php
// /qi/ajax/save_recurring.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$templateName = $_POST['template_name'] ?? '';
$customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
$frequency = $_POST['frequency'] ?? 'monthly';
$intervalCount = filter_input(INPUT_POST, 'interval_count', FILTER_VALIDATE_INT) ?: 1;
$nextRunDate = $_POST['next_run_date'] ?? date('Y-m-d');
$endDate = $_POST['end_date'] ?? null;
$terms = $_POST['terms'] ?? '';
$notes = $_POST['notes'] ?? '';
$lines = $_POST['lines'] ?? [];

if (!$customerId || empty($lines) || !$templateName) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    // Insert recurring invoice
    $stmt = $DB->prepare("
        INSERT INTO recurring_invoices (
            company_id, template_name, customer_id, frequency, interval_count,
            next_run_date, end_date, active, terms, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId, $templateName, $customerId, $frequency, $intervalCount,
        $nextRunDate, $endDate, $terms, $notes, $userId
    ]);

    $recurringId = $DB->lastInsertId();

    // Insert line items
    $sortOrder = 0;
    foreach ($lines as $line) {
        $stmt = $DB->prepare("
            INSERT INTO recurring_invoice_lines (
                recurring_invoice_id, item_description, quantity, unit, unit_price, discount, tax_rate, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $recurringId,
            $line['description'] ?? '',
            floatval($line['quantity'] ?? 1),
            $line['unit'] ?? 'unit',
            floatval($line['unit_price'] ?? 0),
            floatval($line['discount'] ?? 0),
            floatval($line['tax_rate'] ?? 15),
            $sortOrder++
        ]);
    }

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'recurring_invoice_created', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['recurring_id' => $recurringId, 'template_name' => $templateName]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'recurring_id' => $recurringId]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Save recurring error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save']);
}