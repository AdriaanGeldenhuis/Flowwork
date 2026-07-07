<?php
// /finances/fa/api/run_depreciation.php
// Calculates and posts depreciation for the specified month.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method Not Allowed']);
    exit;
}

// CSRF validation
require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

// Include permissions helper and restrict to admins and bookkeepers
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin', 'bookkeeper']);
require_once __DIR__ . '/../../lib/PeriodService.php';

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

header('Content-Type: application/json');

// Read input
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['run_month'])) {
    echo json_encode(['success' => false, 'message' => 'No run month provided']);
    exit;
}

$monthInput = trim($data['run_month']);
// Normalize to YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
    echo json_encode(['success' => false, 'message' => 'Invalid run_month format']);
    exit;
}
$runMonthDate = $monthInput . '-01';

// Check period lock before any calculations or updates
$periodService = new PeriodService($DB, $companyId);
if ($periodService->isLocked($runMonthDate)) {
    echo json_encode(['success' => false, 'message' => 'Cannot run depreciation in locked period (' . $runMonthDate . ')']);
    exit;
}

try {
    // Everything below runs in a SINGLE transaction: the existing-run check,
    // the run marker + line inserts, the journal posting and the per-asset
    // accumulated depreciation updates commit or roll back together. The old
    // flow checked-then-inserted outside any transaction (double depreciation
    // under concurrency) and updated asset accumulated depreciation AFTER the
    // journal transaction committed (desync when the update failed).
    $DB->beginTransaction();

    // Existing-run check with a row lock. FOR UPDATE serialises concurrent
    // requests on the run-marker row for this month (and, via InnoDB index
    // locking, on the key range when no row exists yet), so two simultaneous
    // runs cannot both pass this check.
    $stmt = $DB->prepare(
        "SELECT id, status FROM fa_depreciation_runs
         WHERE company_id = ? AND run_month = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$companyId, $runMonthDate]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        // If exists and already posted, return error; else allow re-run by replacing the old draft
        if ($existing['status'] === 'posted') {
            throw new Exception('A posted depreciation run already exists for this month');
        }
        // Remove existing unposted run and its lines
        $runIdToDelete = (int)$existing['id'];
        $DB->prepare("DELETE FROM fa_depreciation_lines WHERE run_id = ?")->execute([$runIdToDelete]);
        $DB->prepare("DELETE FROM fa_depreciation_runs WHERE id = ?")->execute([$runIdToDelete]);
    }
    // Fetch active assets purchased on or before run month, resolving their
    // depreciation account codes (company-scoped) for the journal lines.
    $stmt = $DB->prepare(
        "SELECT a.asset_id, a.purchase_cost_cents, a.salvage_value_cents, a.useful_life_months,
                a.depreciation_method, a.accumulated_depreciation_cents,
                ea.account_code AS expense_code, aa.account_code AS accum_code
         FROM gl_fixed_assets a
         LEFT JOIN gl_accounts ea ON ea.account_id = a.depreciation_expense_account_id AND ea.company_id = a.company_id
         LEFT JOIN gl_accounts aa ON aa.account_id = a.accumulated_depreciation_account_id AND aa.company_id = a.company_id
         WHERE a.company_id = ? AND a.status = 'active' AND a.purchase_date <= ?"
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
        // Every depreciable asset must have resolvable, company-owned
        // depreciation accounts (same rule as PostingService::postDepreciation).
        if (empty($asset['expense_code']) || empty($asset['accum_code'])) {
            throw new Exception('Fixed asset ' . (int)$asset['asset_id'] . ' missing depreciation account mapping');
        }
        $lines[] = [
            'asset_id'     => (int)$asset['asset_id'],
            'amount_cents' => $depCents,
            'expense_code' => $asset['expense_code'],
            'accum_code'   => $asset['accum_code']
        ];
    }
    if (empty($lines)) {
        throw new Exception('No depreciable amounts calculated for this month');
    }
    // Insert run marker
    $stmt = $DB->prepare("INSERT INTO fa_depreciation_runs (company_id, run_month, status, created_by, created_at) VALUES (?, ?, 'draft', ?, NOW())");
    $stmt->execute([$companyId, $runMonthDate, $userId]);
    $runId = (int)$DB->lastInsertId();
    // Insert run lines
    $insLine = $DB->prepare("INSERT INTO fa_depreciation_lines (run_id, asset_id, amount_cents) VALUES (?, ?, ?)");
    $totalCents = 0;
    foreach ($lines as $ln) {
        $insLine->execute([$runId, $ln['asset_id'], $ln['amount_cents']]);
        $totalCents += $ln['amount_cents'];
    }
    // Post the depreciation journal inside this same transaction. This mirrors
    // PostingService::postDepreciation (which manages its own transaction and
    // therefore cannot be nested here); this endpoint is its only caller.
    $expenseTotals = [];
    $accumTotals   = [];
    foreach ($lines as $ln) {
        $amt = $ln['amount_cents'] / 100.0;
        $expenseTotals[$ln['expense_code']] = ($expenseTotals[$ln['expense_code']] ?? 0.0) + $amt;
        $accumTotals[$ln['accum_code']]     = ($accumTotals[$ln['accum_code']] ?? 0.0) + $amt;
    }
    $stmt = $DB->prepare(
        "INSERT INTO journal_entries (
            company_id, entry_date, reference, description,
            module, ref_type, ref_id, source_type, source_id,
            created_by, created_at, status, posted_by, posted_at
        ) VALUES (?, ?, ?, ?, 'fin', 'depreciation', ?, 'manual', ?, ?, NOW(), 'posted', ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $runMonthDate,
        'DEP' . $runId,
        'Depreciation Run ' . $runMonthDate,
        $runId,
        $runId,
        $userId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();
    // Debit depreciation expense
    $stmtL = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit) VALUES (?, ?, ?, ?, 0)"
    );
    foreach ($expenseTotals as $code => $amt) {
        $stmtL->execute([$journalId, $code, 'Depreciation Expense', number_format($amt, 2, '.', '')]);
    }
    // Credit accumulated depreciation
    $stmtL = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit) VALUES (?, ?, ?, 0, ?)"
    );
    foreach ($accumTotals as $code => $amt) {
        $stmtL->execute([$journalId, $code, 'Accumulated Depreciation', number_format($amt, 2, '.', '')]);
    }
    // Mark run as posted
    $stmt = $DB->prepare("UPDATE fa_depreciation_runs SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ?");
    $stmt->execute([$journalId, $runId, $companyId]);
    // Update per-asset accumulated depreciation in the SAME transaction as the
    // journal, so a failure here rolls the journal back too (no desync).
    $updAsset = $DB->prepare("UPDATE gl_fixed_assets SET accumulated_depreciation_cents = accumulated_depreciation_cents + ? WHERE asset_id = ? AND company_id = ?");
    foreach ($lines as $ln) {
        $updAsset->execute([$ln['amount_cents'], $ln['asset_id'], $companyId]);
    }
    $total = $totalCents / 100.0;
    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'fa_depreciation_run_posted', ?, ?, NOW())"
    );
    $stmt->execute([$companyId, $userId, json_encode(['run_id' => $runId, 'run_month' => $monthInput, 'journal_id' => $journalId, 'total' => $total]), $_SERVER['REMOTE_ADDR'] ?? null]);
    $DB->commit();
    echo json_encode(['success' => true, 'data' => ['run_id' => $runId, 'total' => $total], 'message' => 'Depreciation run posted successfully']);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('FA depreciation run error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to run depreciation']);
}
?>