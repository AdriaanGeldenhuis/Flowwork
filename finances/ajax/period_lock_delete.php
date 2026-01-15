<?php
// /finances/ajax/period_lock_delete.php
// Endpoint to delete a period lock. Accepts JSON with lock_id.
// Only users with admin or bookkeeper roles may call this.

// Dynamically include init, auth and permissions for root or app structures.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) {
        require_once $permPath;
    }
}

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    json_error('Error');
}

// CSRF validation
$csrfPath = $__fin_root . '/finances/lib/Csrf.php';
if (file_exists($csrfPath)) {
    require_once $csrfPath;
    Csrf::validate();
}

// Restrict access
requireRoles(['admin','bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    http_response_code(403);
    json_error('Error');
}

// Read JSON body
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_error('Error');
}

$lockId = intval($payload['lock_id'] ?? 0);
if ($lockId <= 0) {
    json_error('Error');
}

try {
    $DB->beginTransaction();
    // Fetch lock to include details in audit
    $stmt = $DB->prepare("SELECT lock_date, lock_reason FROM gl_period_locks WHERE company_id = ? AND lock_id = ?");
    $stmt->execute([$userId, $companyId, $lockId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $DB->rollBack();
        json_error('Error');
    }
    $lockDate  = $row['lock_date'];
    $lockReason = $row['lock_reason'];
    // Delete lock
    $stmt = $DB->prepare("UPDATE gl_period_locks SET is_active = 0, deleted_at = NOW(), deleted_by = ? WHERE company_id = ? AND lock_id = ?");
    $stmt->execute([$userId, $companyId, $lockId]);
    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)\n" .
        "VALUES (?, ?, 'period_lock_deleted', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['lock_id' => $lockId, 'lock_date' => $lockDate, 'reason' => $lockReason]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $DB->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Period lock delete error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}