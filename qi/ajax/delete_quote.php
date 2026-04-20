<?php
// /qi/ajax/delete_quote.php
// Delete a quote. Any status is permitted EXCEPT 'converted' — once an invoice
// has been created from a quote, the quote is kept as an audit trail; the user
// must delete/void the invoice first.
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$quoteId = (int)($input['quote_id'] ?? 0);

if (!$quoteId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid quote ID']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("SELECT id, status FROM quotes WHERE id = ? AND company_id = ?");
    $stmt->execute([$quoteId, $companyId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) throw new Exception('Quote not found');

    if ($quote['status'] === 'converted') {
        throw new Exception('This quote was converted to an invoice. Delete the invoice first.');
    }

    // If an invoice references this quote (even without "converted" status set),
    // block the delete so we never orphan an invoice FK.
    $check = $DB->prepare("SELECT id, invoice_number FROM invoices WHERE quote_id = ? AND company_id = ? LIMIT 1");
    $check->execute([$quoteId, $companyId]);
    if ($linkedInv = $check->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Invoice ' . $linkedInv['invoice_number'] . ' references this quote. Delete that invoice first.');
    }

    // Purge child rows (no FK cascade in schema)
    $DB->prepare("DELETE FROM quote_lines WHERE quote_id = ?")->execute([$quoteId]);
    try {
        $DB->prepare("DELETE FROM payment_milestones WHERE entity_type = 'quote' AND entity_id = ? AND company_id = ?")
           ->execute([$quoteId, $companyId]);
    } catch (Throwable $e) {
        error_log('delete_quote milestones cleanup: ' . $e->getMessage());
    }

    $stmt = $DB->prepare("DELETE FROM quotes WHERE id = ? AND company_id = ?");
    $stmt->execute([$quoteId, $companyId]);

    try {
        $stmt = $DB->prepare("
            INSERT INTO audit_log (company_id, user_id, action, details, ip)
            VALUES (?, ?, 'quote_deleted', ?, ?)
        ");
        $stmt->execute([
            $companyId, $userId,
            json_encode(['quote_id' => $quoteId, 'prior_status' => $quote['status']]),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('delete_quote audit: ' . $e->getMessage());
    }

    $DB->commit();

    try {
        $calendarHookPath = __DIR__ . '/../services/CalendarHook.php';
        if (file_exists($calendarHookPath)) {
            require_once $calendarHookPath;
            $calendarHook = new CalendarHook($DB);
            $calendarHook->deleteEvent('quote', $quoteId);
        }
    } catch (Throwable $chEx) {
        error_log('Calendar hook delete for quote failed: ' . $chEx->getMessage());
    }

    echo json_encode(['ok' => true, 'message' => 'Quote deleted']);

} catch (Exception $e) {
    if ($DB->inTransaction()) $DB->rollBack();
    error_log('Delete quote error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
