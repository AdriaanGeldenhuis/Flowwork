<?php
// /crm/ajax/bulk_action.php
// Handles bulk actions on CRM accounts (e.g. status change)
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/_helpers.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
crm_require_min_role('member');

$action = $_POST['action'] ?? '';
$ids = $_POST['ids'] ?? [];

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['ok' => false, 'error' => 'No accounts selected']);
    exit;
}

// Sanitize IDs to integers
$ids = array_map('intval', $ids);
$ids = array_filter($ids, function($id) { return $id > 0; });

if (empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid account IDs']);
    exit;
}

try {
    $DB->beginTransaction();

    switch ($action) {
        case 'update_status':
            $newStatus = $_POST['status'] ?? '';
            if (!in_array($newStatus, CRM_ACCOUNT_STATUSES)) {
                throw new Exception('Invalid status value');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $DB->prepare("
                UPDATE crm_accounts
                SET status = ?, updated_at = NOW()
                WHERE id IN ($placeholders) AND company_id = ?
            ");
            $params = array_merge([$newStatus], $ids, [$companyId]);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            // Audit log
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
                VALUES (?, ?, 'crm_bulk_status_update', 'crm_account', 0, ?, NOW())
            ");
            $stmt->execute([
                $companyId,
                $userId,
                json_encode(['ids' => $ids, 'new_status' => $newStatus, 'affected' => $affected])
            ]);

            $DB->commit();
            echo json_encode(['ok' => true, 'affected' => $affected]);
            break;

        case 'soft_delete':
        case 'restore':
            $isDelete = ($action === 'soft_delete');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($isDelete) {
                $stmt = $DB->prepare("
                    UPDATE crm_accounts
                    SET deleted_at = NOW(), updated_at = NOW()
                    WHERE id IN ($placeholders) AND company_id = ? AND deleted_at IS NULL
                ");
            } else {
                $stmt = $DB->prepare("
                    UPDATE crm_accounts
                    SET deleted_at = NULL, updated_at = NOW()
                    WHERE id IN ($placeholders) AND company_id = ? AND deleted_at IS NOT NULL
                ");
            }
            $params = array_merge($ids, [$companyId]);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            $auditAction = $isDelete ? 'crm_bulk_soft_delete' : 'crm_bulk_restore';
            $stmt = $DB->prepare("
                INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, created_at)
                VALUES (?, ?, ?, 'crm_account', 0, ?, NOW())
            ");
            $stmt->execute([
                $companyId,
                $userId,
                $auditAction,
                json_encode(['ids' => $ids, 'affected' => $affected])
            ]);

            $DB->commit();
            echo json_encode(['ok' => true, 'affected' => $affected]);
            break;

        default:
            throw new Exception('Unknown bulk action');
    }

} catch (Throwable $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("CRM bulk_action error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => crm_public_error($e)]);
}
