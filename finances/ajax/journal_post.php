<?php
// /finances/ajax/journal_post.php
// Post an approved journal. Enforces period locks. No edits after post.

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_method('POST');
Csrf::validate();

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../lib/PeriodService.php';
requireRoles(['bookkeeper','admin']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input = json_decode(file_get_contents('php://input'), true);
$journalId = isset($input['journal_id']) ? (int)$input['journal_id'] : 0;
if ($journalId <= 0) { json_error('Invalid journal_id'); }

try {
    $stmt = $DB->prepare("SELECT entry_date, status FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { json_error('Journal not found', 404); }
    if ($row['status'] !== 'approved') { json_error('Journal must be approved first', 409); }

    $periods = new PeriodService($DB, $companyId);
    if ($periods->isLocked($row['entry_date'])) {
        json_error('Period is locked for ' . $row['entry_date'], 409);
    }

    $stmt = $DB->prepare("UPDATE journal_entries SET status='posted', posted_by=?, posted_at=NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$userId, $journalId, $companyId]);

    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_posted', ['journal_id'=>$journalId]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('journal_post: ' . $e->getMessage());
    json_error('Failed to post', 500);
}
