<?php
// /qi/ajax/convert_to_invoice.php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/QuoteConverter.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$quoteId = filter_var($input['quote_id'] ?? 0, FILTER_VALIDATE_INT);

if (!$quoteId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid quote ID']);
    exit;
}

try {
    $converter = new QuoteConverter($DB);
    $result = $converter->convert((int)$quoteId, (int)$companyId, (int)$userId);

    echo json_encode([
        'ok' => true,
        'invoice_id' => $result['invoice_id'],
        'invoice_number' => $result['invoice_number'],
        'message' => 'Quote converted to invoice successfully!',
    ]);
} catch (Exception $e) {
    error_log("Quote conversion error: " . $e->getMessage());
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}
