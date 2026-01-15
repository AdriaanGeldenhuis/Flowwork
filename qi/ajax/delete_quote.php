<?php
// /qi/ajax/delete_quote.php - COMPLETE
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$input = json_decode(file_get_contents('php://input'), true);
$quoteId = filter_var($input['quote_id'] ?? 0, FILTER_VALIDATE_INT);

if (!$quoteId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid quote ID']);
    exit;
}

try {
    $DB->beginTransaction();
    
    $stmt = $DB->prepare("SELECT status FROM quotes WHERE id = ? AND company_id = ?");
    $stmt->execute([$quoteId, $companyId]);
    $quote = $stmt->fetch();
    
    if (!$quote) {
        throw new Exception('Quote not found');
    }
    
    if ($quote['status'] !== 'draft') {
        throw new Exception('Only draft quotes can be deleted');
    }
    
    $stmt = $DB->prepare("DELETE FROM quote_lines WHERE quote_id = ?");
    $stmt->execute([$quoteId]);
    
    $stmt = $DB->prepare("DELETE FROM quotes WHERE id = ? AND company_id = ?");
    $stmt->execute([$quoteId, $companyId]);
    
    $DB->commit();

    // After deletion, remove any calendar events linked to this quote
    try {
        require_once __DIR__ . '/../../services/CalendarHook.php';
        $calendarHook = new CalendarHook($DB);
        $calendarHook->deleteEvent('quote', $quoteId);
    } catch (Exception $chEx) {
        error_log('Calendar hook delete for quote failed: ' . $chEx->getMessage());
    }

    echo json_encode(['ok' => true, 'message' => 'Quote deleted']);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Delete quote error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}