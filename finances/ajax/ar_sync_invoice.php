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
    // Only genuinely issued invoices belong in the GL. The AR list renders a
    // "Sync to GL" button for every invoice with journal_id IS NULL — which
    // includes drafts, voided (cancelled) invoices and reverted-to-draft ones.
    // Syncing any of those would inject revenue+VAT for a document that should
    // not be in the ledger, and desync AR ↔ GL ↔ VAT201. Restrict to the
    // issued/live statuses; draft invoices must be issued through the normal
    // flow (which posts on issue).
    $chk = $DB->prepare("SELECT status, deleted_at FROM invoices WHERE id = ? AND company_id = ? LIMIT 1");
    $chk->execute([$invoiceId, $companyId]);
    $chkRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$chkRow) {
        echo json_encode(['ok' => false, 'error' => 'Invoice not found']);
        exit;
    }
    if (!empty($chkRow['deleted_at'])) {
        echo json_encode(['ok' => false, 'error' => 'Deleted invoices cannot be synced to the GL']);
        exit;
    }
    $syncable = ['sent', 'viewed', 'part-paid', 'paid', 'overdue'];
    if (!in_array($chkRow['status'], $syncable, true)) {
        echo json_encode(['ok' => false, 'error' => 'Only issued invoices can be synced to the GL (this one is "' . $chkRow['status'] . '"). Issue the invoice first.']);
        exit;
    }

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