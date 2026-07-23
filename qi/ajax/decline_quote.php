<?php
// /qi/ajax/decline_quote.php
// Decline a quote either via public token (customer-facing) or via quote_id
// from the logged-in admin view.

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$token    = (string)($input['token'] ?? '');
$quoteId  = (int)($input['quote_id'] ?? 0);

if (!$token) {
    require_once __DIR__ . '/../../auth_gate.php';
    if (empty($_SESSION['user_id']) || !$quoteId) {
        echo json_encode(['ok' => false, 'error' => 'Missing token or quote_id']);
        exit;
    }
}

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $DB->beginTransaction();

    if ($token) {
        $stmt = $DB->prepare("SELECT id, status, company_id FROM quotes WHERE public_token = ?");
        $stmt->execute([$token]);
    } else {
        $companyId = (int)$_SESSION['company_id'];
        $stmt = $DB->prepare("SELECT id, status, company_id FROM quotes WHERE id = ? AND company_id = ?");
        $stmt->execute([$quoteId, $companyId]);
    }
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) throw new Exception('Quote not found');

    if (in_array($quote['status'], ['accepted', 'declined'], true)) {
        $DB->rollBack();
        echo json_encode(['ok' => true, 'message' => 'Quote already ' . $quote['status']]);
        return;
    }

    $update = $DB->prepare("
        UPDATE quotes
           SET status = 'declined', declined_at = NOW(), declined_ip = ?,
               accepted_at = NULL, accepted_ip = NULL, updated_at = NOW()
         WHERE id = ?
    ");
    $update->execute([$ipAddress, $quote['id']]);

    $DB->commit();

    // Status changed — refresh the quote's PDF and its FlowWork Drive copy.
    try {
        require_once __DIR__ . '/../services/DocumentPdfService.php';
        DocumentPdfService::generateAndFile($DB, (int)$quote['company_id'], 'quote', (int)$quote['id'],
            !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    } catch (Throwable $pdfEx) {
        error_log('Decline quote PDF publish failed: ' . $pdfEx->getMessage());
    }

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
            $subject  = 'Quote ' . $qInfo['quote_number'] . ' declined by ' . ($qInfo['customer_name'] ?? 'customer');
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
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Decline quote error: ' . $e->getMessage());
    $safeMsg = ($e instanceof PDOException) ? 'A database error occurred. Please try again.' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}
