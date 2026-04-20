<?php
// /qi/ajax/refund_invoice.php
// Record a refund against a paid / part-paid invoice.
// This creates a negative payment allocation and flips status where appropriate.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

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

    // Flag the underlying payment(s) as refunded where possible
    try {
        $stmt = $DB->prepare("
            UPDATE payments p
              JOIN payment_allocations pa ON pa.payment_id = p.id
               SET p.refunded_at = NOW(), p.refunded_by = ?, p.refund_reference = ?
             WHERE pa.invoice_id = ? AND p.amount > 0 AND p.refunded_at IS NULL
             ORDER BY p.payment_date DESC
             LIMIT 1
        ");
        $stmt->execute([$userId, $reference ?: null, $invoiceId]);
    } catch (Throwable $e) {
        error_log('Refund flag original payment: '.$e->getMessage());
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

    $DB->commit();
    echo json_encode(['ok'=>true, 'new_balance'=>$newBalance, 'status'=>$newStatus]);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Refund invoice error: '.$e->getMessage());
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
