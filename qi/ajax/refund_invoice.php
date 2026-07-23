<?php
// /qi/ajax/refund_invoice.php
// Record a refund against a paid / part-paid invoice.
// This creates a negative payment allocation and flips status where appropriate.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/lib/Csrf.php';
require_once __DIR__ . '/../../finances/permissions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// A refund moves money and reopens the receivable — validate CSRF and gate to
// finance roles (the qi front-end injects X-CSRF-Token via qi.ui.js). Use the
// inline 403 JSON response rather than requireRoles(), which 302-redirects and
// would surface as a misleading "Network error" inside fetch.
Csrf::validate();
$role = fw_get_user_role($DB);
if (!in_array($role, ['admin', 'bookkeeper'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$invoiceId = (int)($input['invoice_id'] ?? 0);
$amount    = (float)($input['amount'] ?? 0);
$reference = trim((string)($input['reference'] ?? ''));
$reason    = trim((string)($input['reason'] ?? ''));

if (!$invoiceId || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) throw new Exception('Invoice not found');
    if (!in_array($invoice['status'], ['paid','part-paid'], true)) {
        throw new Exception('Only paid or part-paid invoices can be refunded');
    }

    $paidStmt = $DB->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE invoice_id = ?");
    $paidStmt->execute([$invoiceId]);
    $paidTotal = (float)$paidStmt->fetchColumn();
    if ($amount > $paidTotal + 0.01) {
        throw new Exception('Refund amount exceeds amount paid');
    }

    // Record as a negative payment so the ledger stays balanced
    $stmt = $DB->prepare("
        INSERT INTO payments (company_id, payment_date, amount, method, reference, notes, received_by)
        VALUES (?, ?, ?, 'refund', ?, ?, ?)
    ");
    $stmt->execute([$companyId, date('Y-m-d'), -$amount, $reference, $reason, $userId]);
    $refundPaymentId = (int)$DB->lastInsertId();

    $stmt = $DB->prepare("
        INSERT INTO payment_allocations (payment_id, invoice_id, amount)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$refundPaymentId, $invoiceId, -$amount]);

    $newBalance = (float)$invoice['balance_due'] + $amount;
    $newTotalPaid = $paidTotal - $amount;
    if ($newTotalPaid <= 0.01) {
        $newStatus = 'refunded';
    } elseif ($newBalance > 0) {
        $newStatus = 'part-paid';
    } else {
        $newStatus = 'paid';
    }
    $stmt = $DB->prepare("UPDATE invoices SET balance_due=?, status=?, updated_at=NOW() WHERE id=? AND company_id=?");
    $stmt->execute([$newBalance, $newStatus, $invoiceId, $companyId]);

    // Flag the most recent underlying receipt as refunded. The previous
    // UPDATE..JOIN..ORDER BY..LIMIT is invalid MySQL — a multi-table (JOIN)
    // UPDATE cannot take ORDER BY or LIMIT — so it threw on every refund and the
    // swallowing try/catch silently hid it. Resolve the target id with a SELECT,
    // then UPDATE by primary key.
    $findStmt = $DB->prepare("
        SELECT p.id
          FROM payments p
          JOIN payment_allocations pa ON pa.payment_id = p.id
         WHERE pa.invoice_id = ? AND p.company_id = ? AND p.amount > 0 AND p.refunded_at IS NULL
         ORDER BY p.payment_date DESC, p.id DESC
         LIMIT 1
    ");
    $findStmt->execute([$invoiceId, $companyId]);
    $origPaymentId = $findStmt->fetchColumn();
    if ($origPaymentId) {
        $DB->prepare("UPDATE payments SET refunded_at = NOW(), refunded_by = ?, refund_reference = ?
                       WHERE id = ? AND company_id = ?")
           ->execute([$userId, $reference ?: null, (int)$origPaymentId, $companyId]);
    }

    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'invoice_refunded', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['invoice_id'=>$invoiceId, 'amount'=>$amount, 'reference'=>$reference, 'reason'=>$reason]),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    // Post the refund to the GL inside this same transaction (Dr AR / Cr Bank).
    // A refund must never live only in the AR subledger — otherwise the
    // receipt's Dr Bank / Cr AR stays posted and the GL bank is overstated by
    // the cash paid out while AR control disagrees with the invoice.
    require_once __DIR__ . '/../../finances/lib/PostingService.php';
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postCustomerRefund($refundPaymentId);

    $DB->commit();

    // Balance/status changed — refresh the invoice PDF and its drive copy.
    require_once __DIR__ . '/../services/DocumentPdfService.php';
    DocumentPdfService::generateAndFile($DB, (int)$companyId, 'invoice', (int)$invoiceId, (int)$userId);

    echo json_encode(['ok'=>true, 'new_balance'=>$newBalance, 'status'=>$newStatus]);

} catch (Throwable $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Refund invoice error: '.$e->getMessage());
    // Don't leak SQL/engine internals; pass through business-rule messages.
    $safeMsg = ($e instanceof PDOException || $e instanceof Error)
        ? 'Could not record the refund. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok'=>false, 'error'=>$safeMsg]);
}
