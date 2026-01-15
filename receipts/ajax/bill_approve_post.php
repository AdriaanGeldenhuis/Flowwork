<?php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'member';

// Only admin/bookkeeper can approve
if (!in_array($userRole, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$fileId = (int)($_POST['file_id'] ?? 0);
$supplierId = (int)($_POST['supplier_id'] ?? 0);
$invoiceNumber = trim($_POST['invoice_number'] ?? '');
$invoiceDate = $_POST['invoice_date'] ?? date('Y-m-d');
$currency = $_POST['currency'] ?? 'ZAR';
$subtotal = (float)($_POST['subtotal'] ?? 0);
$tax = (float)($_POST['tax'] ?? 0);
$total = (float)($_POST['total'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$lines = $_POST['lines'] ?? [];

// Validation
if (!$fileId || !$supplierId || !$invoiceNumber || !$total) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Check for duplicate invoice number
$stmt = $DB->prepare("
    SELECT id FROM invoices 
    WHERE company_id = ? AND customer_id = ? AND invoice_number = ? AND status != 'cancelled'
    LIMIT 1
");
$stmt->execute([$companyId, $supplierId, $invoiceNumber]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate invoice number for this supplier']);
    exit;
}

// Fetch settings
$stmt = $DB->prepare("SELECT * FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch() ?: ['require_vat_number' => 1, 'block_expired_compliance' => 1];

// Policy checks
$policyErrors = [];

// 1. VAT number check
if ($settings['require_vat_number'] && $tax > 0) {
    $stmt = $DB->prepare("SELECT vat_no FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$supplierId, $companyId]);
    $supplier = $stmt->fetch();
    if (empty($supplier['vat_no'])) {
        $policyErrors[] = 'VAT charged but supplier has no VAT number on file';
    }
}

// 2. Compliance check
if ($settings['block_expired_compliance']) {
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM crm_compliance_docs 
        WHERE company_id = ? AND account_id = ? AND status IN ('expired', 'missing')
    ");
    $stmt->execute([$companyId, $supplierId]);
    if ($stmt->fetchColumn() > 0) {
        $policyErrors[] = 'Supplier has expired or missing compliance documents';
    }
}

if (!empty($policyErrors)) {
    // Log policy violations
    foreach ($policyErrors as $err) {
        $stmt = $DB->prepare("
            INSERT INTO receipt_policy_log (company_id, file_id, code, message, severity)
            VALUES (?, ?, 'compliance_block', ?, 'error')
        ");
        $stmt->execute([$companyId, $fileId, $err]);
    }
    
    echo json_encode([
        'ok' => false,
        'error' => 'Policy violations detected',
        'policy_errors' => $policyErrors
    ]);
    exit;
}

// All checks passed - create invoice
try {
    $DB->beginTransaction();

    // Create invoice (status = 'sent' means approved/posted)
    $stmt = $DB->prepare("
        INSERT INTO invoices (
            company_id, invoice_number, customer_id, issue_date, due_date,
            status, subtotal, tax, total, balance_due, currency, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, 'sent', ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $invoiceNumber,
        $supplierId,
        $invoiceDate,
        date('Y-m-d', strtotime($invoiceDate . ' +30 days')),
        $subtotal,
        $tax,
        $total,
        $total,
        $currency,
        $notes,
        $userId
    ]);
    $invoiceId = $DB->lastInsertId();

    // Insert lines
    $sortOrder = 0;
    foreach ($lines as $line) {
        if (empty($line['description'])) continue;

        $qty = (float)($line['qty'] ?? 1);
        $unitPrice = (float)($line['unit_price'] ?? 0);
        $lineTotal = $qty * $unitPrice;

        $stmt = $DB->prepare("
            INSERT INTO invoice_lines (
                invoice_id, item_description, quantity, unit, unit_price,
                discount, tax_rate, line_total, sort_order
            ) VALUES (?, ?, ?, ?, ?, 0, 15.00, ?, ?)
        ");
        $stmt->execute([
            $invoiceId,
            $line['description'],
            $qty,
            $line['unit'] ?? 'ea',
            $unitPrice,
            $lineTotal,
            $sortOrder++
        ]);
    }

    // Link to file
    $stmt = $DB->prepare("UPDATE receipt_file SET invoice_id = ? WHERE file_id = ? AND company_id = ?");
    $stmt->execute([$invoiceId, $fileId, $companyId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip)
        VALUES (?, ?, 'receipt_approved', ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['file_id' => $fileId, 'invoice_id' => $invoiceId, 'total' => $total]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Invoice approved and posted to AP',
        'invoice_id' => $invoiceId
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log('Approve/post error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}