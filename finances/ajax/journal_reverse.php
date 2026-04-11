<?php
// /finances/ajax/journal_reverse.php — Reverse a posted journal entry
// SARS compliance: posted journals cannot be deleted or modified, only reversed.
// Creates a mirror-image journal with debits/credits swapped.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/ReversalService.php';

require_method('POST');
Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input     = json_decode(file_get_contents('php://input'), true);
$journalId = isset($input['journal_id']) ? (int)$input['journal_id'] : 0;
$reason    = trim($input['reason'] ?? '');

if ($journalId <= 0) { json_error('Invalid journal_id'); }
if ($reason === '') { json_error('A reason is required for reversals (SARS audit trail)'); }

try {
    // Verify the journal is posted and not already reversed
    $stmt = $DB->prepare("SELECT status, reversed_by_journal_id, entry_date, description FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { json_error('Journal not found', 404); }
    if ($row['status'] !== 'posted') { json_error('Only posted journals can be reversed', 409); }
    if ($row['reversed_by_journal_id']) { json_error('Journal has already been reversed (by #' . $row['reversed_by_journal_id'] . ')', 409); }

    $service = new ReversalService($DB, $companyId);
    $reversalId = $service->reverseJournal($journalId, $userId, $reason);

    if (!$reversalId) {
        json_error('Failed to reverse — the entry date may be in a locked period');
    }

    // Audit
    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_reversed', [
        'original_journal_id' => $journalId,
        'reversal_journal_id' => $reversalId,
        'reason'              => $reason,
        'entry_date'          => $row['entry_date'],
        'description'         => $row['description']
    ]);

    echo json_encode(['ok' => true, 'data' => ['reversal_journal_id' => $reversalId]]);

} catch (Throwable $e) {
    error_log('journal_reverse: ' . $e->getMessage());
    json_error('Failed to reverse journal', 500);
}
