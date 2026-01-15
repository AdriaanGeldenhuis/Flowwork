<?php
// /finances/ajax/budget_save.php
// Endpoint to save account budgets for a given year (and optionally project).
// Expects JSON payload: { year: YYYY, project_id: <nullable>, budgets: [ { account_id, month, amount } ... ] }
// Requires admin or bookkeeper roles.

// Dynamically load init, auth and permissions.
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

// Restrict access
requireRoles(['admin','bookkeeper']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Parse JSON payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$year = isset($data['year']) ? intval($data['year']) : null;
$projectId = isset($data['project_id']) && $data['project_id'] !== '' ? intval($data['project_id']) : null;
$budgets = $data['budgets'] ?? null;

if (!$year || $year < 2000 || $year > 2100) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing year']);
    exit;
}
if (!is_array($budgets)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid budgets array']);
    exit;
}

try {
    $DB->beginTransaction();
    // Delete existing budgets for this company, year and project
    if ($projectId === null) {
        $delStmt = $DB->prepare(
            "DELETE FROM gl_budgets WHERE company_id = ? AND period_year = ? AND project_id IS NULL"
        );
        $delStmt->execute([$companyId, $year]);
    } else {
        $delStmt = $DB->prepare(
            "DELETE FROM gl_budgets WHERE company_id = ? AND period_year = ? AND project_id = ?"
        );
        $delStmt->execute([$companyId, $year, $projectId]);
    }
    // Prepare insert
    $insStmt = $DB->prepare(
        "INSERT INTO gl_budgets (company_id, gl_account_id, period_year, period_month, amount_cents, project_id)\n" .
        "VALUES (?, ?, ?, ?, ?, ?)"
    );
    $count = 0;
    foreach ($budgets as $row) {
        if (!is_array($row)) continue;
        if (!isset($row['account_id'], $row['month'], $row['amount'])) continue;
        $accId = (int)$row['account_id'];
        $month = (int)$row['month'];
        $amount = (float)$row['amount'];
        if ($month < 1 || $month > 12) continue;
        if ($amount < 0) $amount = 0.0;
        $cents = (int) round($amount * 100);
        if ($cents === 0) continue;
        $insStmt->execute([$companyId, $accId, $year, $month, $cents, $projectId]);
        $count++;
    }
    // Audit log
    $details = json_encode([
        'year' => $year,
        'project_id' => $projectId,
        'count' => $count
    ]);
    $logStmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)\n" .
        "VALUES (?, ?, 'budget_saved', ?, ?, NOW())"
    );
    $logStmt->execute([
        $companyId,
        $userId,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $DB->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Budget save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save budgets']);
}
