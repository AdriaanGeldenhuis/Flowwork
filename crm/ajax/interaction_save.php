<?php
// /crm/ajax/interaction_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $DB->beginTransaction();

    $accountId = (int)($_POST['account_id'] ?? 0);

    if (!$accountId) {
        throw new Exception('Account ID required');
    }

    // Verify account belongs to company
    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Account not found');
    }

    $type = $_POST['type'] ?? 'note';
    if (!in_array($type, ['email', 'call', 'visit', 'note', 'issue'])) {
        $type = 'note';
    }

    $contactId = !empty($_POST['contact_id']) ? (int)$_POST['contact_id'] : null;
    $nextActionAt = !empty($_POST['next_action_at']) ? str_replace('T', ' ', trim($_POST['next_action_at'])) : null;
    if ($nextActionAt !== null && strtotime($nextActionAt) === false) {
        throw new Exception('Invalid next action date/time');
    }

    // INSERT interaction
    $stmt = $DB->prepare("
        INSERT INTO crm_interactions (
            company_id, account_id, contact_id, type, subject, body,
            via, created_by, created_at, next_action_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'manual', ?, NOW(), ?)
    ");

    $stmt->execute([
        $companyId,
        $accountId,
        $contactId,
        $type,
        trim($_POST['subject'] ?? '') ?: null,
        trim($_POST['body'] ?? '') ?: null,
        $userId,
        $nextActionAt
    ]);

    $interactionId = $DB->lastInsertId();

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'create', 'crm_interaction', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $interactionId,
        json_encode(['account_id' => $accountId, 'type' => $type])
    ]);

    $DB->commit();

    // A next-action becomes a calendar follow-up with an in-app reminder.
    // Failure here must not lose the interaction that just committed.
    if ($nextActionAt !== null && strtotime($nextActionAt) > time()) {
        try {
            require_once __DIR__ . '/../includes/calendar_followup.php';
            $subject = trim($_POST['subject'] ?? '') ?: ucfirst($type);
            crm_create_followup(
                $DB, $companyId, $userId,
                'account', $accountId,
                'Next action: ' . $subject,
                $nextActionAt, 60, ['in_app']
            );
        } catch (Throwable $fe) {
            error_log('CRM interaction follow-up error: ' . $fe->getMessage());
        }
    }

    echo json_encode(['ok' => true, 'interaction_id' => $interactionId]);

} catch (Throwable $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM interaction_save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}