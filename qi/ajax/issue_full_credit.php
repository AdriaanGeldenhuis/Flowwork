<?php
// /qi/ajax/issue_full_credit.php
// Generate a draft credit note that mirrors all lines of the source invoice.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/require_writer.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$invoiceId = (int)($input['invoice_id'] ?? 0);
$reason    = trim((string)($input['reason'] ?? 'Full credit'));

if (!$invoiceId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid invoice ID']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$invoiceId, $companyId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) throw new Exception('Invoice not found');

    // Only issued invoices can be credited, and never beyond what is still
    // creditable (invoice total minus credits already issued) — mirrors the s21
    // control in save_credit_note.php. Without this, clicking "Full credit"
    // twice manufactured duplicate revenue/VAT reversals.
    if (in_array($inv['status'], ['draft', 'cancelled'], true) || !empty($inv['deleted_at'])) {
        throw new Exception('Only issued invoices can be credited');
    }
    $credStmt = $DB->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM credit_notes
          WHERE invoice_id = ? AND company_id = ? AND status <> 'cancelled'"
    );
    $credStmt->execute([$invoiceId, $companyId]);
    $creditable = round((float)$inv['total'] - (float)$credStmt->fetchColumn(), 2);
    if (round((float)$inv['total'], 2) > $creditable + 0.01) {
        throw new Exception(sprintf(
            'A full credit of R%.2f exceeds the remaining creditable amount R%.2f on this invoice',
            (float)$inv['total'], max(0, $creditable)
        ));
    }

    $stmt = $DB->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
    $stmt->execute([$invoiceId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) throw new Exception('Invoice has no lines to credit');

    $year = date('Y');
    $stmt = $DB->prepare("
        INSERT INTO qi_sequences (company_id, type, year, next_number)
        VALUES (?, 'credit_note', ?, 1)
        ON DUPLICATE KEY UPDATE next_number = next_number + 1
    ");
    $stmt->execute([$companyId, $year]);
    $stmt = $DB->prepare("SELECT next_number - 1 AS num FROM qi_sequences WHERE company_id = ? AND type = 'credit_note' AND year = ?");
    $stmt->execute([$companyId, $year]);
    $num = (int)$stmt->fetchColumn();
    $cnNumber = sprintf('CN%d-%04d', $year, $num);

    $stmt = $DB->prepare("
        INSERT INTO credit_notes (
            company_id, credit_note_number, invoice_id, customer_id,
            issue_date, status, subtotal, tax, total, currency, exchange_rate, reason, reason_code, created_by
        ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, 'cancellation', ?)
    ");
    $stmt->execute([
        $companyId, $cnNumber, $invoiceId, $inv['customer_id'],
        date('Y-m-d'), $inv['subtotal'], $inv['tax'], $inv['total'],
        $inv['currency'] ?: 'ZAR', $inv['exchange_rate'] ?? 1,
        $reason, $userId,
    ]);
    $cnId = (int)$DB->lastInsertId();

    // Full credit mirrors the invoice lines exactly, tax codes included, so
    // the VAT reversal is classified identically to the original supply.
    $ins = $DB->prepare("
        INSERT INTO credit_note_lines (
            credit_note_id, item_description, quantity, unit, unit_price,
            discount, tax_rate, tax_code_id, line_total, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($lines as $l) {
        $ins->execute([
            $cnId, $l['item_description'], $l['quantity'], $l['unit'], $l['unit_price'],
            $l['discount'], $l['tax_rate'], $l['tax_code_id'] ?? null, $l['line_total'], $l['sort_order'],
        ]);
    }

    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'credit_note_created_from_invoice', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['invoice_id'=>$invoiceId, 'credit_note_id'=>$cnId, 'credit_note_number'=>$cnNumber]),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    $DB->commit();

    // Render the new credit note's PDF and publish it to FlowWork Drive.
    require_once __DIR__ . '/../services/DocumentPdfService.php';
    DocumentPdfService::generateAndFile($DB, (int)$companyId, 'credit_note', (int)$cnId, (int)$userId);

    echo json_encode(['ok'=>true, 'credit_note_id'=>$cnId, 'credit_note_number'=>$cnNumber]);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Issue full credit error: '.$e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Failed to issue credit note' : $e->getMessage();
    echo json_encode(['ok'=>false, 'error'=>$msg]);
}
