<?php
// /qi/ajax/approve_credit_note.php – Approve a draft credit note
// Accepts JSON input {credit_note_id} and sets the credit note status to 'approved' if it is currently 'draft'.

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Decode JSON payload
$input = json_decode(file_get_contents('php://input'), true);
$creditNoteId = isset($input['credit_note_id']) ? (int)$input['credit_note_id'] : 0;

if (!$creditNoteId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid credit note ID']);
    exit;
}

try {
    $DB->beginTransaction();
    // Lock the credit note row for update
    $stmt = $DB->prepare("SELECT * FROM credit_notes WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$creditNoteId, $companyId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$credit) {
        throw new Exception('Credit note not found');
    }
    if ($credit['status'] !== 'draft') {
        throw new Exception('Only draft credit notes can be approved');
    }
    // Approve the credit note
    $upd = $DB->prepare("UPDATE credit_notes SET status = 'approved', updated_at = NOW() WHERE id = ? AND company_id = ?");
    $upd->execute([$creditNoteId, $companyId]);
    // Write audit log
    $insAudit = $DB->prepare("INSERT INTO audit_log (company_id, user_id, action, details, ip) VALUES (?, ?, 'credit_note_approved', ?, ?)");
    $details = json_encode(['credit_note_id' => $creditNoteId, 'credit_note_number' => $credit['credit_note_number']]);
    $insAudit->execute([$companyId, $userId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    $DB->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Approve credit note error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}