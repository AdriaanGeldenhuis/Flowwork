<?php
// /qi/ajax/decline_quote.php
// Public endpoint to decline a quote via token

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
    $DB->beginTransaction();

    // Fetch the quote by public token
    $stmt = $DB->prepare("SELECT id, status FROM quotes WHERE public_token = ?");
    $stmt->execute([$token]);
    $quote = $stmt->fetch();

    if (!$quote) {
        throw new Exception('Quote not found');
    }

    // If already declined or accepted, do nothing
    if (in_array($quote['status'], ['accepted', 'declined'])) {
        $DB->rollBack();
        echo json_encode(['ok' => true, 'message' => 'Quote already ' . $quote['status']]);
        return;
    }

    // Update quote status, declined timestamp and IP
    $update = $DB->prepare("UPDATE quotes SET status = 'declined', declined_at = NOW(), declined_ip = ?, accepted_at = NULL, accepted_ip = NULL, updated_at = NOW() WHERE id = ?");
    $update->execute([$ipAddress, $quote['id']]);

    $DB->commit();

    // TODO: send notification email (handled later)

    echo json_encode(['ok' => true, 'message' => 'Quote declined successfully']);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Decline quote error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}