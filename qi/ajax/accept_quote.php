<?php
// /qi/ajax/accept_quote.php
// Public endpoint to accept a quote via token

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Read JSON body
$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';

if (!$token) {
    echo json_encode(['ok' => false, 'error' => 'Missing token']);
    exit;
}

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    // Begin transaction for atomic update
    $DB->beginTransaction();

    // Fetch the quote by public token
    $stmt = $DB->prepare("SELECT id, status FROM quotes WHERE public_token = ?");
    $stmt->execute([$token]);
    $quote = $stmt->fetch();

    if (!$quote) {
        throw new Exception('Quote not found');
    }

    // If already accepted or declined, do nothing
    if (in_array($quote['status'], ['accepted', 'declined'])) {
        $DB->rollBack();
        echo json_encode(['ok' => true, 'message' => 'Quote already ' . $quote['status']]);
        return;
    }

    // Update quote status, accepted timestamp and IP
    $update = $DB->prepare("UPDATE quotes SET status = 'accepted', accepted_at = NOW(), accepted_ip = ?, declined_at = NULL, declined_ip = NULL, updated_at = NOW() WHERE id = ?");
    $update->execute([$ipAddress, $quote['id']]);

    $DB->commit();

    // TODO: send confirmation email to company and customer (implemented in later sections)

    echo json_encode(['ok' => true, 'message' => 'Quote accepted successfully']);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Accept quote error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}