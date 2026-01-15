<?php
// /qi/invoice_edit.php - COMPLETE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = $_SESSION['company_id'];
$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$invoiceId) {
    header('Location: /qi/?tab=invoices');
    exit;
}

$stmt = $DB->prepare("SELECT id, status FROM invoices WHERE id = ? AND company_id = ?");
$stmt->execute([$invoiceId, $companyId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('Invoice not found');
}

if ($invoice['status'] !== 'draft') {
    die('Only draft invoices can be edited');
}

header('Location: /qi/invoice_new.php?edit=' . $invoiceId);
exit;