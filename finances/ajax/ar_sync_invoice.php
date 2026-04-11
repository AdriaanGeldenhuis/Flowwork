<?php
// /finances/ajax/ar_sync_invoice.php
require_once __DIR__ . '/../lib/http.php';
require_method('POST');
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';
Csrf::validate();
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input = json_decode(file_get_contents('php://input'), true);
$invoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;

if ($invoiceId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invoice ID required']);
    exit;
}

try {
    // Use the PostingService for consistent journal creation
    require_once __DIR__ . '/../lib/PostingService.php';
    $service = new PostingService($DB, $companyId, $userId);
    $service->postInvoice($invoiceId);
    // Fetch new journal id
    $stmt = $DB->prepare("SELECT journal_id FROM invoices WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$invoiceId, $companyId]);
    $journalId = (int)$stmt->fetchColumn();
    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'ar_invoice_synced', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['invoice_id' => $invoiceId, 'journal_id' => $journalId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    echo json_encode([
        'ok' => true,
        'data' => ['journal_id' => $journalId]
    ]);
} catch (Exception $e) {
    error_log("AR sync error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to sync invoice to GL'
    ]);
}