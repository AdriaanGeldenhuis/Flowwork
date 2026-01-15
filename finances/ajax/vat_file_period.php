<?php
// /finances/ajax/vat_file_period.php
//
// Endpoint to file a VAT period. Filing marks the period as final and
// inserts a lock on the period end date to prevent any further postings.
// Only users with admin or bookkeeper roles may file a VAT return. The
// period must already be prepared or adjusted. Upon filing, the script
// updates the gl_vat_periods record with filed_by/at details, inserts a
// gl_period_locks entry (if not already present) and records an audit
// trail entry.

// Dynamically include init, auth, and permissions from either /app or project root.
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

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Enforce finance role permissions
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Parse JSON input
$input    = json_decode(file_get_contents('php://input'), true);
$periodId = isset($input['period_id']) ? (int)$input['period_id'] : 0;
if ($periodId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Period ID required']);
    exit;
}

try {
    // Fetch VAT period
    $stmt = $DB->prepare(
        "SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        throw new Exception('VAT period not found');
    }
    $status = strtolower((string)$period['status']);
    if (!in_array($status, ['prepared', 'adjusted'], true)) {
        throw new Exception('Period must be prepared or adjusted before filing');
    }

    $DB->beginTransaction();

    // Update period status to filed
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods
         SET status = 'filed', filed_by = ?, filed_at = NOW()
         WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$userId, $periodId, $companyId]);

    // Insert a lock for the period end date unless one already exists
    // We check if there is an existing lock at or after this date for the company
    $stmt = $DB->prepare(
        "SELECT 1 FROM gl_period_locks WHERE company_id = ? AND lock_date >= ? LIMIT 1"
    );
    $stmt->execute([$companyId, $period['period_end']]);
    $lockExists = (bool)$stmt->fetchColumn();
    if (!$lockExists) {
        $stmt = $DB->prepare(
            "INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at)
             VALUES (?, ?, 'vat_period_filed', ?, NOW())"
        );
        $stmt->execute([
            $companyId,
            $period['period_end'],
            $userId
        ]);
    }

    // Audit log entry
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'vat_period_filed', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['period_id' => $periodId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('VAT file period error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
