<?php
// /qi/ajax/create_yoco_link.php
// Endpoint to generate a Yoco payment link for an invoice.
//
// This script creates a unique payment reference for the invoice, constructs a
// payment link URL and stores both the link and reference on the invoice. It
// does not perform a live call to Yoco's API – a real integration would send
// the details to Yoco and return the hosted checkout link. Instead, this
// implementation generates a placeholder link so that the rest of the system
// (invoice UI, email templates, posting logic) can function correctly until
// API credentials are configured.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure company context
$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Parse JSON input
$inputJson = file_get_contents('php://input');
$input     = json_decode($inputJson, true);
if (!is_array($input) || empty($input['invoice_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

$invoiceId = (int)$input['invoice_id'];
if ($invoiceId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid invoice ID']);
    exit;
}

try {
    // Fetch invoice and verify ownership
    $stmt = $DB->prepare('SELECT id, invoice_number, balance_due, status, yoco_payment_link FROM invoices WHERE id = ? AND company_id = ?');
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        echo json_encode(['ok' => false, 'error' => 'Invoice not found']);
        return;
    }
    if ($invoice['status'] === 'paid') {
        echo json_encode(['ok' => false, 'error' => 'Invoice already paid']);
        return;
    }
    if (!empty($invoice['yoco_payment_link'])) {
        // Already has a link – return it
        echo json_encode(['ok' => true, 'payment_link' => $invoice['yoco_payment_link']]);
        return;
    }

    // Generate a unique reference for the payment link. This reference is used
    // both as an identifier on the invoice and as part of the link. A
    // real-world integration would send this reference to Yoco so that the
    // webhook can identify the related invoice upon payment completion.
    $uniqueRef = 'INV-' . $invoiceId . '-' . bin2hex(random_bytes(4));

    // Construct a placeholder payment link. Replace this with a call to
    // Yoco's API when API keys are configured in qi_settings. For example:
    // $link = 'https://pay.yoco.com/' . $uniqueRef;
    $link = 'https://pay.yoco.com/pay/' . $uniqueRef;

    // Persist the link and reference on the invoice
    $stmt = $DB->prepare('UPDATE invoices SET yoco_payment_link = ?, yoco_reference = ?, updated_at = NOW() WHERE id = ? AND company_id = ?');
    $stmt->execute([$link, $uniqueRef, $invoiceId, $companyId]);

    // Optionally log the creation in yoco_payment_events for auditing
    $stmtLog = $DB->prepare('INSERT INTO yoco_payment_events (company_id, event_type, event_id, payload_json) VALUES (?, ?, ?, ?)');
    $payloadJson = json_encode(['invoice_id' => $invoiceId, 'reference' => $uniqueRef, 'link' => $link]);
    $stmtLog->execute([$companyId, 'payment_link_created', null, $payloadJson]);

    echo json_encode(['ok' => true, 'payment_link' => $link]);
    return;

} catch (Exception $e) {
    // Error handling
    error_log('Create Yoco link error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not create payment link']);
    return;
}