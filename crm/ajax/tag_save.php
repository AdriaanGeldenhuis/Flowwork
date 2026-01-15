<?php
// /crm/ajax/tag_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

try {
    $DB->beginTransaction();

    $accountId = (int)($_POST['account_id'] ?? 0);
    $tagName = trim($_POST['name'] ?? '');
    $tagColor = trim($_POST['color'] ?? '#06b6d4');

    if (!$accountId || !$tagName) {
        throw new Exception('Account ID and tag name required');
    }

    // Verify account belongs to company
    $stmt = $DB->prepare("SELECT id FROM crm_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Account not found');
    }

    // Check if tag exists, create if not
    $stmt = $DB->prepare("
        SELECT id FROM crm_tags 
        WHERE company_id = ? AND name = ?
    ");
    $stmt->execute([$companyId, $tagName]);
    $tag = $stmt->fetch();

    if ($tag) {
        $tagId = $tag['id'];
    } else {
        $stmt = $DB->prepare("
            INSERT INTO crm_tags (company_id, name, color)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$companyId, $tagName, $tagColor]);
        $tagId = $DB->lastInsertId();
    }

    // Link tag to account (ignore if already exists)
    $stmt = $DB->prepare("
        INSERT IGNORE INTO crm_account_tags (account_id, tag_id)
        VALUES (?, ?)
    ");
    $stmt->execute([$accountId, $tagId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, ?, 'update', 'crm_account', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        $accountId,
        json_encode(['action' => 'add_tag', 'tag_name' => $tagName])
    ]);

    $DB->commit();

    echo json_encode(['ok' => true, 'tag_id' => $tagId]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM tag_save error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}