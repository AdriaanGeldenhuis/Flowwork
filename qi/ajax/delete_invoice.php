<?php
// /qi/ajax/delete_invoice.php - COMPLETE FILE
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$invoiceId = $input['invoice_id'] ?? null;

if (!$invoiceId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    // Check if invoice can be deleted (only draft)
    $stmt = $DB->prepare("SELECT status FROM invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    if ($invoice['status'] !== 'draft') {
        throw new Exception('Only draft invoices can be deleted');
    }

    // Delete invoice (cascade will delete lines)
    $stmt = $DB->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$invoiceId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'invoice_deleted', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['invoice_id' => $invoiceId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    // After deletion, remove any calendar events linked to this invoice
    try {
        require_once __DIR__ . '/../../services/CalendarHook.php';
        $calendarHook = new CalendarHook($DB);
        $calendarHook->deleteEvent('invoice', $invoiceId);
    } catch (Exception $chEx) {
        error_log('Calendar hook delete for invoice failed: ' . $chEx->getMessage());
    }

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Delete invoice error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}