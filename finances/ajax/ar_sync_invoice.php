<?php
// /finances/ajax/ar_sync_invoice.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$invoiceId = $input['invoice_id'] ?? null;

if (!$invoiceId) {
    echo json_encode(['ok' => false, 'error' => 'Invoice ID required']);
    exit;
}

try {
    // Use the PostingService for consistent journal creation
    require_once __DIR__ . '/../lib/PostingService.php';
    $service = new PostingService($DB, $companyId, $userId);
    $service->postInvoice((int)$invoiceId);
    // Fetch new journal id
    $stmt = $DB->prepare("SELECT journal_id FROM invoices WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$invoiceId, $companyId]);
    $journalId = $stmt->fetchColumn();
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
        'data' => ['journal_id' => (int)$journalId]
    ]);
} catch (Exception $e) {
    error_log("AR sync error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}