<?php
// /qi/ajax/duplicate_invoice.php
// Duplicate an existing invoice as a new draft.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/SequenceAllocator.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$invoiceId = (int)($input['invoice_id'] ?? 0);
if (!$invoiceId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid invoice ID']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$invoiceId, $companyId]);
    $original = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$original) throw new Exception('Invoice not found');

    $alloc = new SequenceAllocator($DB);
    [$newNumber] = $alloc->allocate($companyId, 'invoice', null, true);

    $issueDate = date('Y-m-d');
    $dueDate   = date('Y-m-d', strtotime('+30 days'));

    $stmt = $DB->prepare("
        INSERT INTO invoices (
            company_id, invoice_number, customer_id, contact_id, project_id,
            issue_date, due_date, status,
            subtotal, discount, tax, total, balance_due,
            currency, exchange_rate, terms, notes, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $companyId, $newNumber,
        $original['customer_id'], $original['contact_id'], $original['project_id'],
        $issueDate, $dueDate,
        $original['subtotal'], $original['discount'], $original['tax'],
        $original['total'], $original['total'],  // balance_due = total on new draft
        $original['currency'], $original['exchange_rate'] ?? 1,
        $original['terms'], $original['notes'], $userId,
    ]);
    $newId = (int)$DB->lastInsertId();

    $stmt = $DB->prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order");
    $stmt->execute([$invoiceId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ins = $DB->prepare("
        INSERT INTO invoice_lines (
            invoice_id, item_description, quantity, unit, unit_price,
            discount, tax_rate, line_total, sort_order, gl_account_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($lines as $l) {
        $ins->execute([
            $newId, $l['item_description'], $l['quantity'], $l['unit'], $l['unit_price'],
            $l['discount'], $l['tax_rate'], $l['line_total'], $l['sort_order'], $l['gl_account_id'],
        ]);
    }

    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'invoice_duplicated', ?, ?)
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['source_id'=>$invoiceId, 'new_id'=>$newId, 'new_number'=>$newNumber]),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    $DB->commit();
    echo json_encode(['ok'=>true, 'invoice_id'=>$newId, 'invoice_number'=>$newNumber]);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Duplicate invoice error: '.$e->getMessage());
    $msg = ($e instanceof PDOException) ? 'A database error occurred.' : $e->getMessage();
    echo json_encode(['ok'=>false, 'error'=>$msg]);
}
