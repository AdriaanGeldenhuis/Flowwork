<?php
// /finances/ajax/fa_run_depreciation.php
//
// Endpoint to calculate and post depreciation for a given month. This script
// computes depreciation amounts for all active fixed assets as of the
// specified month, records a depreciation run and its lines, updates the
// accumulated depreciation on each asset, and posts the run to the general
// ledger using PostingService. Only users with admin or bookkeeper roles
// may invoke this endpoint.

// Dynamically include core init, auth, and permissions. Supports both
// installations with an /app directory and those with files at the root.
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
require_once __DIR__ . '/../lib/PostingService.php';
require_once __DIR__ . '/../lib/PeriodService.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Enforce admin/bookkeeper permissions
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Parse input JSON
$input    = json_decode(file_get_contents('php://input'), true);
$runMonth = isset($input['run_month']) ? trim((string)$input['run_month']) : '';
if (!$runMonth || !preg_match('/^\d{4}-\d{2}$/', $runMonth)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid run_month format']);
    exit;
}

// Normalize to first day of the month
$runMonthDate = $runMonth . '-01';

try {
    // Check if the month is locked. Depreciation is recorded at run_month date.
    $periodService = new PeriodService($DB, (int)$companyId);
    if ($periodService->isLocked($runMonthDate)) {
        throw new Exception('Cannot run depreciation in locked period (' . $runMonthDate . ')');
    }

    // If an existing run exists for this month, remove it (if unposted) or block if posted
    $stmt = $DB->prepare(
        "SELECT id, status FROM fa_depreciation_runs WHERE company_id = ? AND run_month = ? LIMIT 1"
    );
    $stmt->execute([$companyId, $runMonthDate]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ($existing['status'] === 'posted') {
            throw new Exception('A posted depreciation run already exists for this month');
        }
        // Remove existing run and its lines
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
            $rate      = 2.0 / $life;
            $calc      = (int)floor($bookValue * $rate);
            if ($calc < 0) {
                $calc = 0;
            }
            $depCents = min($calc, $remaining);
        } else {
            // Straight line: evenly over life
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
            'asset_id'    => (int)$asset['asset_id'],
            'amount_cents' => $depCents
        ];
    }
    if (empty($lines)) {
        throw new Exception('No depreciable amounts calculated for this month');
    }

    // Insert run and lines, update assets, then post via PostingService
    $DB->beginTransaction();
    // Insert run record (status = draft)
    $stmt = $DB->prepare(
        "INSERT INTO fa_depreciation_runs (company_id, run_month, status, created_by, created_at)
         VALUES (?, ?, 'draft', ?, NOW())"
    );
    $stmt->execute([$companyId, $runMonthDate, $userId]);
    $runId = (int)$DB->lastInsertId();
    // Prepare statements for inserting lines and updating assets
    $insLine = $DB->prepare(
        "INSERT INTO fa_depreciation_lines (run_id, asset_id, amount_cents) VALUES (?, ?, ?)"
    );
    $updAsset = $DB->prepare(
        "UPDATE gl_fixed_assets
         SET accumulated_depreciation_cents = accumulated_depreciation_cents + ?, updated_at = NOW()
         WHERE asset_id = ? AND company_id = ?"
    );
    foreach ($lines as $ln) {
        $insLine->execute([$runId, $ln['asset_id'], $ln['amount_cents']]);
        $updAsset->execute([$ln['amount_cents'], $ln['asset_id'], $companyId]);
    }
    $DB->commit();

    // Post the depreciation run via PostingService
    $posting = new PostingService($DB, (int)$companyId, (int)$userId);
    $posting->postDepreciation($runId);

    // Record audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'fa_depreciation_run_posted', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['run_id' => $runId, 'run_month' => $runMonth]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    // Compute total depreciation in decimals
    $totalCents = 0;
    foreach ($lines as $ln) {
        $totalCents += $ln['amount_cents'];
    }
    $total = $totalCents / 100.0;

    echo json_encode([
        'ok'    => true,
        'data'  => [
            'run_id' => $runId,
            'total'  => $total
        ],
        'message' => 'Depreciation run posted successfully'
    ]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('FA depreciation run error: ' . $e->getMessage());
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
