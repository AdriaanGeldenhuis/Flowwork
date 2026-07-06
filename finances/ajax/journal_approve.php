<?php
// /finances/ajax/journal_approve.php
// Approve a draft/prepared journal for posting.

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_method('POST');
Csrf::validate();

require_once __DIR__ . '/../permissions.php';
requireRoles(['bookkeeper','admin']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input = json_decode(file_get_contents('php://input'), true);
$journalId = isset($input['journal_id']) ? (int)$input['journal_id'] : 0;
if ($journalId <= 0) { json_error('Invalid journal_id'); }

try {
    // Approve only draft journals (SARS workflow: draft → approved → posted)
    $stmt = $DB->prepare("SELECT status, created_by FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { json_error('Journal not found', 404); }
    $st = $row['status'];
    if ($st !== 'draft') { json_error('Only draft journals can be approved (current status: ' . $st . ')', 409); }

    // Segregation of duties (optional control): the approver must not be the
    // preparer when finance_require_sod is enabled in Finance Settings.
    $stmt = $DB->prepare("SELECT setting_value FROM company_settings
                           WHERE company_id = ? AND setting_key = 'finance_require_sod' LIMIT 1");
    $stmt->execute([$companyId]);
    if ((string)$stmt->fetchColumn() === '1' && (int)$row['created_by'] === $userId) {
        json_error('Segregation of duties: a journal must be approved by someone other than its preparer', 403);
    }

    $stmt = $DB->prepare("UPDATE journal_entries SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$userId, $journalId, $companyId]);

    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_approved', ['journal_id'=>$journalId]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('journal_approve: ' . $e->getMessage());
    json_error('Failed to approve', 500);
}
