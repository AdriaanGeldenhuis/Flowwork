<?php
// /finances/fa/api/run_depreciation.php
// Calculates and posts depreciation for the specified month.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
    exit;
}

// CSRF validation
require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

// Include permissions helper and restrict to admins and bookkeepers
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin', 'bookkeeper']);
require_once __DIR__ . '/../../lib/PostingService.php';

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

header('Content-Type: application/json');

// Read input
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['run_month'])) {
    echo json_encode(['ok' => false, 'error' => 'No run month provided']);
    exit;
}

$monthInput = trim($data['run_month']);
// Normalize to YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid run_month format']);
    exit;
}
$runMonthDate = $monthInput . '-01';

try {
    // Check for existing run for the month
    $stmt = $DB->prepare("SELECT id, status FROM fa_depreciation_runs WHERE company_id = ? AND run_month = ? LIMIT 1");
    $stmt->execute([$companyId, $runMonthDate]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        // If exists and already posted, return error; else allow re-run by deleting old run?
        if ($existing['status'] === 'posted') {
            throw new Exception('A posted depreciation run already exists for this month');
        }
        // Remove existing unposted run and its lines
        $runIdToDelete = (int)$existing['id'];
        $DB->beginTransaction();
        $DB->prepare("DELETE FROM fa_depreciation_lines WHERE run_id = ?")->execute([$runIdToDelete]);
        $DB->prepare("DELETE FROM fa_depreciation_runs WHERE id = ?")->execute([$runIdToDelete]);
        $DB->commit();
    }
    // Fetch active assets purchased on or before run month
    $stmt = $DB->prepare(
        "SELECT asset_id, purchase_cost_cents, salvage_value_cents, useful_life_months,
                depreciation_method, accumulated_depreciation_cents
         FROM gl_fixed_assets
         WHERE company_id = ? AND status = 'active' AND purchase_date <= ?"
    );
    $stmt->execute([$companyId, $runMonthDate]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$assets) {
        throw new Exception('No active assets found for this month');
    }
    // Compute depreciation per asset
    $lines = [];
    foreach ($assets as $asset) {
        $cost     = (int)$asset['purchase_cost_cents'];
        $salvage  = (int)$asset['salvage_value_cents'];
        $life     = (int)$asset['useful_life_months'];
        if ($life <= 0) {
            continue;
        }
        $method   = $asset['depreciation_method'];
        $accum    = (int)$asset['accumulated_depreciation_cents'];
        $depreciable = $cost - $salvage;
        if ($depreciable <= 0) {
            continue;
        }
        $remaining = $depreciable - $accum;
        if ($remaining <= 0) {
            continue; // fully depreciated
        }
        $depCents = 0;
        if ($method === 'declining_balance') {
            // Double declining balance: 2 / life * book value
            $bookValue = $cost - $accum;
            $rate = 2.0 / $life;
            $calc = (int)floor($bookValue * $rate);
            if ($calc < 0) $calc = 0;
            $depCents = min($calc, $remaining);
        } else {
            // Straight line
            $monthly = (int)floor($depreciable / $life);
            if ($monthly <= 0) {
                $monthly = $remaining;
            }
            $depCents = min($monthly, $remaining);
        }
        if ($depCents <= 0) {
            continue;
        }
        $lines[] = [
            'asset_id' => (int)$asset['asset_id'],
            'amount_cents' => $depCents
        ];
    }
    if (empty($lines)) {
        throw new Exception('No depreciable amounts calculated for this month');
    }
    // Create run and insert lines
    $DB->beginTransaction();
    // Insert run
    $stmt = $DB->prepare("INSERT INTO fa_depreciation_runs (company_id, run_month, status, created_by, created_at) VALUES (?, ?, 'draft', ?, NOW())");
    $stmt->execute([$companyId, $runMonthDate, $userId]);
    $runId = (int)$DB->lastInsertId();
    // Insert lines and update assets
    $insLine = $DB->prepare("INSERT INTO fa_depreciation_lines (run_id, asset_id, amount_cents) VALUES (?, ?, ?)");
    $updAsset = $DB->prepare("UPDATE gl_fixed_assets SET accumulated_depreciation_cents = accumulated_depreciation_cents + ? WHERE asset_id = ? AND company_id = ?");
    $totalCents = 0;
    foreach ($lines as $ln) {
        $insLine->execute([$runId, $ln['asset_id'], $ln['amount_cents']]);
        $updAsset->execute([$ln['amount_cents'], $ln['asset_id'], $companyId]);
        $totalCents += $ln['amount_cents'];
    }
    $DB->commit();
    // Post the run via PostingService
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postDepreciation($runId);
    $total = $totalCents / 100.0;
    echo json_encode(['ok' => true, 'data' => ['run_id' => $runId, 'total' => $total], 'message' => 'Depreciation run posted successfully']);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>