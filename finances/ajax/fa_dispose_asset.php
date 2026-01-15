<?php
// /finances/ajax/fa_dispose_asset.php
//
// Endpoint to dispose of a fixed asset. This script creates a balanced
// journal entry to remove the asset from the books, records gain or loss
// on disposal, updates the asset status to 'disposed', logs the disposal
// in fa_disposals, and writes an audit log entry. Only admin or
// bookkeeper roles may perform disposals. Disposal cannot occur in
// locked periods.

// Load init, auth and permissions depending on project structure.
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
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/AccountsMap.php';

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

// Parse JSON input
$input    = json_decode(file_get_contents('php://input'), true);
$assetId  = isset($input['asset_id']) ? (int)$input['asset_id'] : 0;
$dateStr  = isset($input['disposal_date']) ? trim((string)$input['disposal_date']) : '';
$proceeds = isset($input['proceeds']) ? (float)$input['proceeds'] : 0.0;
$notes    = isset($input['notes']) ? trim((string)$input['notes']) : null;

if ($assetId <= 0 || $dateStr === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing asset_id or disposal_date']);
    exit;
}
// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid disposal_date format']);
    exit;
}
// Validate proceeds is numeric
if (!is_numeric($proceeds)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid proceeds value']);
    exit;
}

try {
    // Check if disposal date is in a locked period
    $periodService = new PeriodService($DB, (int)$companyId);
    if ($periodService->isLocked($dateStr)) {
        throw new Exception('Cannot post to locked period (' . $dateStr . ')');
    }
    // Load asset
    $stmt = $DB->prepare(
        "SELECT asset_id, asset_name, purchase_cost_cents, accumulated_depreciation_cents,
                asset_account_id, accumulated_depreciation_account_id, status
         FROM gl_fixed_assets
         WHERE company_id = ? AND asset_id = ? LIMIT 1"
    );
    $stmt->execute([$companyId, $assetId]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        throw new Exception('Asset not found');
    }
    if (strtolower((string)$asset['status']) !== 'active') {
        throw new Exception('Asset is not active and cannot be disposed');
    }
    // Resolve account codes
    $accounts = new AccountsMap($DB, (int)$companyId);
    $assetCode = $accounts->getById((int)$asset['asset_account_id']);
    $accumCode = $accounts->getById((int)$asset['accumulated_depreciation_account_id']);
    if (!$assetCode || !$accumCode) {
        throw new Exception('Asset missing GL account mappings');
    }
    // Bank account for proceeds; default 1110
    $bankCode = $accounts->get('finance_bank_account_id', '1110');
    // Gain and loss accounts; defaults 4900 and 6900
    $gainCode = $accounts->get('finance_gain_on_disposal_account_id', '4900');
    $lossCode = $accounts->get('finance_loss_on_disposal_account_id', '6900');
    if (!$gainCode || !$lossCode) {
        throw new Exception('Missing gain/loss account mappings');
    }
    // Convert monetary amounts to decimals
    $costDec   = $asset['purchase_cost_cents'] / 100.0;
    $accumDec  = $asset['accumulated_depreciation_cents'] / 100.0;
    $proceedsDec = round(floatval($proceeds), 2);
    // Compute book value and difference
    $bookValue = $costDec - $accumDec;
    $diff      = $proceedsDec - $bookValue;
    // Prepare amounts for journal lines (positive values)
    $bankAmt  = $proceedsDec > 0 ? $proceedsDec : 0.0;
    $accumAmt = $accumDec > 0 ? $accumDec : 0.0;
    $assetAmt = $costDec > 0 ? $costDec : 0.0;
    $gainAmt  = ($diff > 0) ? $diff : 0.0;
    $lossAmt  = ($diff < 0) ? abs($diff) : 0.0;

    // Begin transaction
    $DB->beginTransaction();
    // Insert journal entry
    $reference   = 'DISP' . $assetId;
    $description = 'Disposal of asset ' . $asset['asset_name'];
    $stmtJ = $DB->prepare(
        "INSERT INTO journal_entries (
            company_id, entry_date, reference, description,
            module, ref_type, ref_id, source_type, source_id,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, 'fin', 'fa_disposal', ?, 'asset', ?, ?, NOW())"
    );
    $stmtJ->execute([
        $companyId,
        $dateStr,
        $reference,
        $description,
        $assetId,
        $assetId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();
    // Insert journal lines
    $stmtL = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit)
         VALUES (?, ?, ?, ?, ?)"
    );
    // Debit bank for proceeds
    if ($bankAmt > 0.00001) {
        $stmtL->execute([
            $journalId,
            $bankCode,
            'Proceeds from asset disposal',
            number_format($bankAmt, 2, '.', ''),
            '0.00'
        ]);
    }
    // Debit accumulated depreciation
    if ($accumAmt > 0.00001) {
        $stmtL->execute([
            $journalId,
            $accumCode,
            'Reverse accumulated depreciation',
            number_format($accumAmt, 2, '.', ''),
            '0.00'
        ]);
    }
    // Debit loss (if any)
    if ($lossAmt > 0.00001) {
        $stmtL->execute([
            $journalId,
            $lossCode,
            'Loss on disposal',
            number_format($lossAmt, 2, '.', ''),
            '0.00'
        ]);
    }
    // Credit asset cost
    if ($assetAmt > 0.00001) {
        $stmtL->execute([
            $journalId,
            $assetCode,
            'Remove asset cost',
            '0.00',
            number_format($assetAmt, 2, '.', '')
        ]);
    }
    // Credit gain (if any)
    if ($gainAmt > 0.00001) {
        $stmtL->execute([
            $journalId,
            $gainCode,
            'Gain on disposal',
            '0.00',
            number_format($gainAmt, 2, '.', '')
        ]);
    }
    // Update asset record: mark disposed
    $stmtU = $DB->prepare(
        "UPDATE gl_fixed_assets
         SET status = 'disposed', disposal_date = ?, disposal_proceeds_cents = ?, disposal_journal_id = ?, updated_at = NOW()
         WHERE asset_id = ? AND company_id = ?"
    );
    $stmtU->execute([
        $dateStr,
        (int)round($proceedsDec * 100),
        $journalId,
        $assetId,
        $companyId
    ]);
    // Insert into fa_disposals
    $stmtD = $DB->prepare(
        "INSERT INTO fa_disposals (company_id, asset_id, disposal_date, proceeds_cents, notes, journal_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmtD->execute([
        $companyId,
        $assetId,
        $dateStr,
        (int)round($proceedsDec * 100),
        $notes,
        $journalId,
        $userId
    ]);
    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'fa_asset_disposed', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode([
            'asset_id' => $assetId,
            'journal_id' => $journalId,
            'proceeds' => $proceedsDec,
            'date' => $dateStr
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    // Commit
    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => ['journal_id' => $journalId],
        'message' => 'Asset disposed successfully'
    ]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('FA dispose asset error: ' . $e->getMessage());
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
