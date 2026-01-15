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

// Validate year
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

try {
    // Begin transaction
    $DB->beginTransaction();

    // Remove existing budgets for this company/year (project_id NULL only)
    $delStmt = $DB->prepare(
        "DELETE FROM gl_budgets WHERE company_id = ? AND period_year = ? AND project_id IS NULL"
    );
    $delStmt->execute([$companyId, $year]);

    // Prepare insert statement
    $insStmt = $DB->prepare(
        "INSERT INTO gl_budgets (company_id, gl_account_id, period_year, period_month, amount_cents, project_id) " .
        "VALUES (?, ?, ?, ?, ?, NULL)"
    );

    // Loop through budgets and insert
    foreach ($budgets as $row) {
        if (!is_array($row)) continue;
        if (!isset($row['account_id'], $row['month'], $row['amount'])) continue;
        $accId = (int) $row['account_id'];
        $month = (int) $row['month'];
        $amt   = (float) $row['amount'];
        // Validate month and amount
        if ($month < 1 || $month > 12) continue;
        if ($amt < 0) $amt = 0.0;
        // Convert to cents
        $cents = (int) round($amt * 100);
        // Only insert if non-zero (we remove zeros during deletion step)
        if ($cents === 0) continue;
        $insStmt->execute([$companyId, $accId, $year, $month, $cents]);
    }

    $DB->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    $DB->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error saving budgets: ' . $e->getMessage()]);
}
?>