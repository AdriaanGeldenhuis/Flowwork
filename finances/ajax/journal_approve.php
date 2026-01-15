<?php
// /finances/ajax/journal_approve.php
// Approve a draft/prepared journal for posting.

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_method('POST');
Csrf::validate();

require_once __DIR__ . '/../init.php';
requireRoles(['bookkeeper','admin']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input = json_decode(file_get_contents('php://input'), true);
$journalId = isset($input['journal_id']) ? (int)$input['journal_id'] : 0;
if ($journalId <= 0) { json_error('Invalid journal_id'); }

try {
    // Approve only if not already posted
    $stmt = $DB->prepare("SELECT status FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);
    $st = $stmt->fetchColumn();
    if (!$st) { json_error('Journal not found', 404); }
    if ($st === 'posted') { json_error('Already posted', 409); }

    $stmt = $DB->prepare("UPDATE journal_entries SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$userId, $journalId, $companyId]);

    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_approved', ['journal_id'=>$journalId]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('journal_approve: ' . $e->getMessage());
    json_error('Failed to approve', 500);
}
