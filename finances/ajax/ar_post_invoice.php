<?php
// /finances/ajax/ar_post_invoice.php
//
// Endpoint for posting an invoice to the general ledger. This script
// ensures that only authorised users can post invoices, checks for
// locked periods, delegates the posting to ArService and updates the
// invoice status and journal reference. It also writes an entry to
// the audit log for traceability. Duplicate postings are prevented by
// checking whether the invoice already has a journal_id.

// Load initialization, authentication, and permissions dynamically to support
// projects with or without an `/app` directory. Determine the project root
// two levels above this script (e.g. `/finances/ajax` -> `/`).
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    // Fall back to files in the project root
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/ArService.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Enforce finance role permissions
requireRoles(['admin', 'bookkeeper']);

// Pull company and user context from the session
$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;

if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Decode JSON payload
$input = json_decode(file_get_contents('php://input'), true);
$invoiceId      = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;
$idempotencyKey = isset($input['idempotency_key']) ? trim((string)$input['idempotency_key']) : '';

if ($invoiceId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invoice ID required']);
    exit;
}

try {
    // Fetch invoice details to determine date and status
    $stmt = $DB->prepare(
        "SELECT id, issue_date, status, journal_id FROM invoices WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        echo json_encode(['ok' => false, 'error' => 'Invoice not found']);
        exit;
    }

    // Prevent duplicate posting by checking existing journal
    
    // Assign invoice number if missing using per-company sequence
    if (empty($invoice['invoice_number'])) {
        require_once __DIR__ . '/../lib/Sequence.php';
        $seq = new Sequence($DB, $companyId);
        $newNo = $seq->issue('AR-INVOICE', ['prefix' => 'INV-{YYYY}-{MM}-', 'pad' => 4]);
        $u = $DB->prepare("UPDATE invoices SET invoice_number = ? WHERE id = ? AND company_id = ?");
        $u->execute([$newNo, $invoiceId, $companyId]);
        $invoice['invoice_number'] = $newNo;
    }
if (!empty($invoice['journal_id'])) {
        echo json_encode([
            'ok' => false,
            'duplicate' => true,
            'error' => 'Invoice already posted'
        ]);
        exit;
    }

    // Check locked period for the invoice issue date (or today if null)
    $issueDate = $invoice['issue_date'] ?: date('Y-m-d');
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($issueDate)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Cannot post invoice to locked period (' . $issueDate . ')'
        ]);
        exit;
    }

    // Delegate posting to ArService
    $arService = new ArService($DB, $companyId, $userId);
    $arService->postInvoice($invoiceId);

    // After posting, do not alter the invoice status. The status should remain as entered
    // (e.g. draft or sent). We only update the journal reference via ArService.

    // Re-fetch journal id after posting
    $stmt = $DB->prepare(
        "SELECT journal_id FROM invoices WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$invoiceId, $companyId]);
    $journalId = (int)$stmt->fetchColumn();

    // Record audit log entry
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'ar_invoice_posted', ?, ?, NOW())"
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
    exit;

} catch (Exception $e) {
    error_log('AR post invoice error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
