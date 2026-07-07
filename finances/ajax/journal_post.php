<?php
// /finances/ajax/journal_post.php
// Post an approved journal. Enforces period locks. No edits after post.

// permissions.php must load first: it bootstraps the app session
// (FLOWWORKSESSID) that Csrf::validate() reads the token from.
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_method('POST');
Csrf::validate();
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

    // Re-verify integrity at post time: the journal must have lines and balance.
    $stmt = $DB->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c FROM journal_lines WHERE journal_id = ?");
    $stmt->execute([$journalId]);
    $sums = $stmt->fetch(PDO::FETCH_ASSOC);
    if ((int)$sums['n'] === 0) {
        json_error('Journal has no lines and cannot be posted', 409);
    }
    if (abs((float)$sums['d'] - (float)$sums['c']) >= 0.005) {
        json_error('Journal is not balanced: debits ' . number_format((float)$sums['d'], 2)
            . ' != credits ' . number_format((float)$sums['c'], 2), 409);
    }

    // Atomic transition: the status predicate guards against a concurrent
    // post/edit racing between the check above and this UPDATE.
    $stmt = $DB->prepare("UPDATE journal_entries SET status='posted', posted_by=?, posted_at=NOW() WHERE id = ? AND company_id = ? AND status='approved'");
    $stmt->execute([$userId, $journalId, $companyId]);
    if ($stmt->rowCount() === 0) {
        json_error('Journal status changed concurrently — reload and try again', 409);
    }

    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_posted', ['journal_id'=>$journalId]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('journal_post: ' . $e->getMessage());
    json_error('Failed to post', 500);
}
