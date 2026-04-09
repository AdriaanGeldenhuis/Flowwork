<?php
// /finances/ajax/journal_delete.php — Delete a draft journal entry
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../permissions.php';

require_method('POST');
Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) { json_error('Not authorised', 403); }

$input = json_decode(file_get_contents('php://input'), true);
$journalId = isset($input['journal_id']) ? (int)$input['journal_id'] : 0;
if ($journalId <= 0) { json_error('Invalid journal_id'); }

try {
    $stmt = $DB->prepare("SELECT status FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);
    $status = $stmt->fetchColumn();

    if (!$status) { json_error('Journal not found', 404); }
    if ($status !== 'draft') { json_error('Only draft journals can be deleted', 409); }

    $DB->beginTransaction();

    $stmt = $DB->prepare("DELETE FROM journal_lines WHERE journal_id = ?");
    $stmt->execute([$journalId]);

    $stmt = $DB->prepare("DELETE FROM journal_entries WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $companyId]);

    require_once __DIR__ . '/../lib/Audit.php';
    Audit::log('journal_deleted', ['journal_id' => $journalId]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    $DB->rollBack();
    error_log('journal_delete: ' . $e->getMessage());
    json_error('Failed to delete journal', 500);
}
