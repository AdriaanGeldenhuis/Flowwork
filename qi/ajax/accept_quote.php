<?php
// /qi/ajax/accept_quote.php
// Accept a quote either via public token (customer-facing) or via quote_id
// from the logged-in admin view. Older quotes may have no public_token, so
// the admin path must not require one.

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

// Admin path requires a session (auth_gate only when no token present)
$authenticated = false;
if (!$token) {
    require_once __DIR__ . '/../../auth_gate.php';
    $authenticated = !empty($_SESSION['user_id']);
    if (!$authenticated || !$quoteId) {
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
           SET status = 'accepted', accepted_at = NOW(), accepted_by_ip = ?,
               declined_at = NULL, declined_ip = NULL, updated_at = NOW()
         WHERE id = ?
    ");
    $update->execute([$ipAddress, $quote['id']]);

    $DB->commit();

    $invoiceId = null;
    $invoiceNumber = null;
    try {
        require_once __DIR__ . '/../lib/QuoteConverter.php';
        $convCompanyId = (int)$quote['company_id'];
        $convUserId = (int)($_SESSION['user_id'] ?? 0);
        if (!$convUserId) {
            // Public token path: fall back to the quote creator so created_by is populated.
            $stmtCb = $DB->prepare("SELECT created_by FROM quotes WHERE id = ?");
            $stmtCb->execute([$quote['id']]);
            $convUserId = (int)($stmtCb->fetchColumn() ?: 0);
        }
        $converter = new QuoteConverter($DB);
        $convResult = $converter->convert((int)$quote['id'], $convCompanyId, $convUserId);
        $invoiceId = $convResult['invoice_id'];
        $invoiceNumber = $convResult['invoice_number'];
    } catch (Exception $convEx) {
        error_log('Auto invoice from accept failed: ' . $convEx->getMessage());
    }

    // Refresh the accepted quote's PDF (status changed) and render the
    // auto-created invoice's PDF; publish both to FlowWork Drive.
    try {
        require_once __DIR__ . '/../services/DocumentPdfService.php';
        $hookCompanyId = (int)$quote['company_id'];
        $hookUserId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        DocumentPdfService::generateAndFile($DB, $hookCompanyId, 'quote', (int)$quote['id'], $hookUserId);
        if (!empty($invoiceId)) {
            DocumentPdfService::generateAndFile($DB, $hookCompanyId, 'invoice', (int)$invoiceId, $hookUserId);
        }
    } catch (Throwable $pdfEx) {
        error_log('Accept quote PDF publish failed: ' . $pdfEx->getMessage());
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
            $subject  = 'Quote ' . $qInfo['quote_number'] . ' accepted by ' . ($qInfo['customer_name'] ?? 'customer');
            $htmlBody = '<p>Quote <strong>' . htmlspecialchars($qInfo['quote_number']) . '</strong> has been accepted by <strong>' . htmlspecialchars($qInfo['customer_name'] ?? 'the customer') . '</strong>.</p>'
                      . '<p>Total: R ' . number_format((float)$qInfo['total'], 2) . '</p>'
                      . '<p>You can now convert this quote to an invoice.</p>';
            $textBody = "Quote {$qInfo['quote_number']} accepted by " . ($qInfo['customer_name'] ?? 'the customer') . ". Total: R " . number_format((float)$qInfo['total'], 2);
            $mailer->sendDocument((int)$qInfo['company_id'], 0, 'quote', (int)$quote['id'], $qInfo['company_email'], $subject, $htmlBody, $textBody);
        }
    } catch (Exception $mailEx) {
        error_log('Accept quote notification email failed: ' . $mailEx->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Quote accepted successfully',
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
    ]);
} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Accept quote error: ' . $e->getMessage());
    $safeMsg = ($e instanceof PDOException) ? 'A database error occurred. Please try again.' : $e->getMessage();
    echo json_encode(['ok' => false, 'error' => $safeMsg]);
}
