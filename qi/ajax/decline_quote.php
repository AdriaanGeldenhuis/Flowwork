<?php
// /qi/ajax/decline_quote.php
// Public endpoint to decline a quote via token

error_reporting(E_ALL);
ini_set('display_errors', '0');

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

    // Send decline notification email to the company owner
    try {
        require_once __DIR__ . '/../services/Mailer.php';
        $mailer = new Mailer($DB);
        $stmtQ = $DB->prepare("SELECT q.quote_number, q.total, q.company_id, c.email AS company_email, ca.name AS customer_name
            FROM quotes q
            LEFT JOIN companies c ON q.company_id = c.id
            LEFT JOIN crm_accounts ca ON q.customer_id = ca.id
            WHERE q.id = ?");
        $stmtQ->execute([$quote['id']]);
        $qInfo = $stmtQ->fetch(PDO::FETCH_ASSOC);
        if ($qInfo && $qInfo['company_email']) {
            $subject = 'Quote ' . $qInfo['quote_number'] . ' declined by ' . ($qInfo['customer_name'] ?? 'customer');
            $htmlBody = '<p>Quote <strong>' . htmlspecialchars($qInfo['quote_number']) . '</strong> has been declined by <strong>' . htmlspecialchars($qInfo['customer_name'] ?? 'the customer') . '</strong>.</p>'
                . '<p>Total: R ' . number_format((float)$qInfo['total'], 2) . '</p>';
            $textBody = "Quote {$qInfo['quote_number']} declined by " . ($qInfo['customer_name'] ?? 'the customer') . ". Total: R " . number_format((float)$qInfo['total'], 2);
            $mailer->sendDocument((int)$qInfo['company_id'], 0, 'quote', (int)$quote['id'], $qInfo['company_email'], $subject, $htmlBody, $textBody);
        }
    } catch (Exception $mailEx) {
        error_log('Decline quote notification email failed: ' . $mailEx->getMessage());
    }

    echo json_encode(['ok' => true, 'message' => 'Quote declined successfully']);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Decline quote error: ' . $e->getMessage());
    $safeMsg = ($e instanceof PDOException)
        ? 'A database error occurred. Please try again.'
        : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}