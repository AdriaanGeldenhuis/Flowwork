<?php
// /projects/api/_audit.php
//
// Shared audit-log writer. Every board mutation should call fw_audit() so the
// Activity Feed (api/activity/list.php reads board_audit_log) reflects reality.
// Logging must never break the mutation itself, so failures are swallowed.

if (!function_exists('fw_audit')) {
    /**
     * @param PDO         $DB
     * @param int         $companyId
     * @param int         $boardId
     * @param int|null    $itemId
     * @param int         $userId
     * @param string      $action   e.g. item_created, item_updated, status_changed,
     *                              item_moved, item_deleted, bulk_update, comment_added,
     *                              column_added, group_added
     * @param array       $details  small JSON-serializable context payload
     */
    function fw_audit($DB, $companyId, $boardId, $itemId, $userId, $action, array $details = [])
    {
        try {
            $stmt = $DB->prepare("
                INSERT INTO board_audit_log (
                    company_id, board_id, item_id, user_id,
                    action, details, ip_address, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                (int)$companyId,
                (int)$boardId,
                $itemId !== null ? (int)$itemId : null,
                (int)$userId,
                $action,
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (Exception $e) {
            error_log('fw_audit error: ' . $e->getMessage());
        }
    }
}
