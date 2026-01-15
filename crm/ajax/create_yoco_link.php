<?php
// /qi/ajax/create_yoco_link.php
// Creates a Yoco payment link for an invoice and stores the link + reference.
// Requires Yoco API credentials stored on the company record.

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Parse JSON body
$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);

$invoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;
if (!$invoiceId) {
    echo json_encode(['ok' => false, 'error' => 'Missing invoice_id']);
    exit;
}

try {
    // Fetch company Yoco credentials
    $stmt = $DB->prepare("SELECT yoco_mode, yoco_pub_key, yoco_sec_key, yoco_webhook_secret, name FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        throw new Exception('Company not found');
    }
    // Ensure we have secret key
    $secKey = $company['yoco_sec_key'];
    if (empty($secKey)) {
        throw new Exception('Yoco integration is not configured');
    }
    $mode = $company['yoco_mode'] ?: 'test';
    $baseUrl = ($mode === 'live') ? 'https://api.yoco.com' : 'https://api.yocosandbox.com';

    // Fetch invoice
    $stmt = $DB->prepare("SELECT * FROM invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    // Ensure invoice is not paid and has balance to collect
    if ($invoice['status'] === 'paid' || (float)$invoice['balance_due'] <= 0) {
        throw new Exception('Invoice is already paid');
    }
    // Do not recreate if link already exists
    if (!empty($invoice['yoco_payment_link'])) {
        echo json_encode(['ok' => true, 'payment_link' => $invoice['yoco_payment_link'], 'message' => 'Link already exists']);
        exit;
    }
    // Amount to charge in minor currency units (cents)
    $amountCents = (int)round(((float)$invoice['balance_due'] ?: (float)$invoice['total']) * 100);
    if ($amountCents <= 0) {
        throw new Exception('Invalid invoice amount');
    }
    $currency = $invoice['currency'] ?: 'ZAR';
    // Compose payload
    $payload = [
        'amount' => [
            'amount'   => $amountCents,
            'currency' => $currency
        ],
        // Show invoice number to customer
        'customer_description' => 'Invoice ' . $invoice['invoice_number'] . ' from ' . ($company['name'] ?? ''),
        'customer_reference'   => $invoice['invoice_number'],
    ];
    // Build request
    $url = $baseUrl . '/v1/payment_links/';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        throw new Exception('Failed to connect to Yoco API');
    }
    $respData = json_decode($response, true);
    if ($httpCode >= 300 || !$respData || empty($respData['id']) || empty($respData['url'])) {
        // Provide error details if available
        $msg = $respData['message'] ?? 'Unable to create payment link';
        throw new Exception('Yoco error: ' . $msg);
    }
    $yocoId  = $respData['id'];
    $payLink = $respData['url'];
    // Save to database
    $DB->beginTransaction();
    $stmt = $DB->prepare("UPDATE invoices SET yoco_payment_link = ?, yoco_reference = ?, updated_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$payLink, $yocoId, $invoiceId, $companyId]);
    // Log audit
    $stmt = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, 'yoco_link_created', 'invoice', ?, ?, ?)");
    $stmt->execute([$companyId, $userId, $invoiceId, json_encode(['yoco_id' => $yocoId, 'link' => $payLink]), $_SERVER['REMOTE_ADDR'] ?? null]);
    $DB->commit();
    echo json_encode(['ok' => true, 'payment_link' => $payLink, 'yoco_reference' => $yocoId]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Create Yoco link error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
