<?php
// /finances/ajax/journal_unapprove.php
// Send an approved (not-yet-posted) journal back to draft so an erroneous
// approval can be corrected before it reaches the ledger. The inverse of
// journal_approve.php.

// permissions.php bootstraps the app session that Csrf::validate() reads its
// token from — it MUST load first (mirrors the sibling journal endpoints).
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_method('POST');
Csrf::validate();
requireRoles(['bookkeeper','admin']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input = json_decode(file_get_contents('php://input'), true);
$journalId = isset($input['journal_id']) ? (int)$input['journal_id'] : 0;
if ($journalId <= 0) { json_error('Invalid journal_id'); }

try {
    // Only an approved journal may be reverted. A posted journal is on the
    // ledger and is immutable (it can only be reversed), never un-approved.
    $stmt = $DB->prepare("SELECT status FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { json_error('Journal not found', 404); }
    if ($row['status'] !== 'approved') {
        json_error('Only approved journals can be sent back to draft (current status: ' . $row['status'] . ')', 409);
    }

    // Atomic transition guarded on status: closes the race with a concurrent
    // post so an already-POSTED journal can never be pulled back to draft.
    $stmt = $DB->prepare("UPDATE journal_entries
                             SET status = 'draft', approved_by = NULL, approved_at = NULL
                           WHERE id = ? AND company_id = ? AND status = 'approved'");
    $stmt->execute([$journalId, $companyId]);
    if ($stmt->rowCount() === 0) {
        json_error('Journal is no longer approved — refresh and try again', 409);
    }

    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_unapproved', ['journal_id' => $journalId]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('journal_unapprove: ' . $e->getMessage());
    json_error('Failed to unapprove', 500);
}
