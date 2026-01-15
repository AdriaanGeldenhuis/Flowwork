<?php
// /finances/ajax/period_lock_save.php
// Endpoint to create or update a period lock. Accepts JSON with
// lock_date (YYYY-MM-DD) and optional reason. Only users with admin or
// bookkeeper roles may call this. On success returns the lock ID and
// locked_at timestamp.

// Dynamically include init, auth and permissions. Determine the root two levels above.
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

$lockDate = trim($payload['lock_date'] ?? '');
$reason   = trim($payload['reason'] ?? '');

// Validate date (YYYY-MM-DD). Use regex to ensure proper format.
if (!$lockDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate)) {
    json_error('Error');
}

try {
    $DB->beginTransaction();

    // Check if lock for this date already exists
    $stmt = $DB->prepare("SELECT lock_id FROM gl_period_locks WHERE company_id = ? AND lock_date = ?");
    $stmt->execute([$companyId, $lockDate]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        // Update existing lock: update reason and locked_by/locked_at
        $stmt = $DB->prepare(
            "UPDATE gl_period_locks\n" .
            "SET lock_reason = ?, locked_by = ?, locked_at = NOW()\n" .
            "WHERE company_id = ? AND lock_id = ?"
        );
        $stmt->execute([$reason, $userId, $companyId, $existingId]);
        $lockId = (int)$existingId;
    } else {
        // Insert new lock
        $stmt = $DB->prepare(
            "INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at)\n" .
            "VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$companyId, $lockDate, $reason, $userId]);
        $lockId = (int)$DB->lastInsertId();
    }

    // Fetch locked_at timestamp for response
    $stmt = $DB->prepare("SELECT locked_at FROM gl_period_locks WHERE company_id = ? AND lock_id = ?");
    $stmt->execute([$companyId, $lockId]);
    $lockedAt = $stmt->fetchColumn();

    // Audit log
    $action = $existingId ? 'period_lock_updated' : 'period_lock_added';
    $details = json_encode(['lock_date' => $lockDate, 'reason' => $reason, 'lock_id' => $lockId]);
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)\n" .
        "VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    echo json_encode(['ok' => true, 'data' => ['lock_id' => $lockId, 'locked_at' => $lockedAt]]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Period lock save error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}