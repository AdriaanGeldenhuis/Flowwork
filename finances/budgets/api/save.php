<?php
// /finances/budgets/api/save.php
// API endpoint to save account budgets for a given year.
// Expects JSON payload: { year: 2025, budgets: [ { account_id: 123, month: 1, amount: 100.00 }, ... ] }

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

// Include permissions helper and restrict to admins and bookkeepers
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Parse JSON payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

// Validate year (accept numeric strings — JSON clients often send "2026")
if (isset($data['year']) && is_numeric($data['year'])) {
    $data['year'] = (int)$data['year'];
}
if (!isset($data['year']) || !is_int($data['year']) || $data['year'] < 2000 || $data['year'] > 2100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing year']);
    exit;
}
$year = $data['year'];

// Validate budgets array
if (!isset($data['budgets']) || !is_array($data['budgets'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid budgets array']);
    exit;
}
$budgets = $data['budgets'];

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Period lock check – must be per-month, not per-year. The save DELETEs and
// re-INSERTs rows for the year, so a coarse "is Dec 31 locked?" test would let a
// partial-year lock (e.g. books locked through Jun 30) be silently rewritten for
// the locked Jan–Jun months. Compute the locked month set and only ever touch
// unlocked months below.
require_once __DIR__ . '/../../lib/PeriodService.php';
$periodService = new PeriodService($DB, $companyId);
$lockedMonths  = [];
for ($m = 1; $m <= 12; $m++) {
    $lastDay = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m)));
    if ($periodService->isLocked($lastDay)) {
        $lockedMonths[$m] = true;
    }
}
if (count($lockedMonths) === 12) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Period is locked. Budget changes are not allowed for ' . $year . '.']);
    exit;
}

// Fetch valid account IDs for this company to prevent cross-company writes.
// NOTE: gl_accounts.account_type is ENUM('asset','liability','equity','revenue','expense')
// — the value is 'revenue', not 'income'.
$validAccStmt = $DB->prepare(
    "SELECT account_id FROM gl_accounts WHERE company_id = ? AND is_active = 1 AND account_type IN ('revenue','expense')"
);
$validAccStmt->execute([$companyId]);
$validAccountIds = array_column($validAccStmt->fetchAll(PDO::FETCH_ASSOC), 'account_id');
$validAccountIds = array_map('intval', $validAccountIds);

try {
    // Begin transaction
    $DB->beginTransaction();

    // Remove existing budgets for this company/year (project_id NULL only),
    // but never touch locked months — their existing rows are preserved.
    if (empty($lockedMonths)) {
        $delStmt = $DB->prepare(
            "DELETE FROM gl_budgets WHERE company_id = ? AND period_year = ? AND project_id IS NULL"
        );
        $delStmt->execute([$companyId, $year]);
    } else {
        $unlocked = array_values(array_diff(range(1, 12), array_keys($lockedMonths)));
        $ph = implode(',', array_fill(0, count($unlocked), '?'));
        $delStmt = $DB->prepare(
            "DELETE FROM gl_budgets WHERE company_id = ? AND period_year = ? AND project_id IS NULL AND period_month IN ($ph)"
        );
        $delStmt->execute(array_merge([$companyId, $year], $unlocked));
    }

    // Prepare insert statement
    $insStmt = $DB->prepare(
        "INSERT INTO gl_budgets (company_id, gl_account_id, period_year, period_month, amount_cents, project_id) " .
        "VALUES (?, ?, ?, ?, ?, NULL)"
    );

    // Loop through budgets and insert
    $count = 0;
    foreach ($budgets as $row) {
        if (!is_array($row)) continue;
        if (!isset($row['account_id'], $row['month'], $row['amount'])) continue;
        $accId = (int) $row['account_id'];
        $month = (int) $row['month'];
        $amt   = (float) $row['amount'];
        // Validate account belongs to this company
        if (!in_array($accId, $validAccountIds, true)) continue;
        // Validate month and amount
        if ($month < 1 || $month > 12) continue;
        // Never write into a locked month (its existing rows were preserved above)
        if (isset($lockedMonths[$month])) continue;
        if ($amt < 0) $amt = 0.0;
        // Convert to cents
        $cents = (int) round($amt * 100);
        // Only insert if non-zero (we remove zeros during deletion step)
        if ($cents === 0) continue;
        $insStmt->execute([$companyId, $accId, $year, $month, $cents]);
        $count++;
    }

    $DB->commit();

    // Audit log – separate try-catch so a logging failure cannot mask a successful save
    try {
        $details = json_encode([
            'year' => $year,
            'project_id' => null,
            'count' => $count
        ]);
        $logStmt = $DB->prepare(
            "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
             VALUES (?, ?, 'budget_saved', ?, ?, NOW())"
        );
        $logStmt->execute([
            $companyId,
            $userId,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $auditErr) {
        error_log('Budget audit log error [company=' . $companyId . ']: ' . $auditErr->getMessage());
    }

    echo json_encode(['success' => true, 'count' => $count]);
} catch (Throwable $e) {
    $DB->rollBack();
    error_log('Budget save error [company=' . $companyId . ']: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while saving budgets. Please try again.']);
}
?>