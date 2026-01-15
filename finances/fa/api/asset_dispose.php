<?php
// /finances/fa/api/asset_dispose.php
// Endpoint to dispose of a fixed asset. Creates a journal entry to remove the asset
// and records gain or loss on disposal. Marks the asset as disposed and logs
// the disposal in the fa_disposals table.

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../auth_gate.php';

// HTTP method guard
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    json_error('Error');
}

// CSRF validation
require_once __DIR__ . '/../../lib/Csrf.php';
Csrf::validate();

// Include permissions helper; only admins and bookkeepers may dispose assets
require_once __DIR__ . '/../../permissions.php';
requireRoles(['admin', 'bookkeeper']);

require_once __DIR__ . '/../../lib/PeriodService.php';
require_once __DIR__ . '/../../lib/AccountsMap.php';

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];

// Parse JSON input
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    json_error('Error');
}

try {
    $assetId   = isset($data['asset_id']) ? (int)$data['asset_id'] : 0;
    $dateStr   = trim($data['disposal_date'] ?? '');
    $proceeds  = isset($data['proceeds']) ? (float)$data['proceeds'] : 0.0;
    $notes     = isset($data['notes']) ? trim($data['notes']) : null;
    if (!$assetId || !$dateStr) {
        throw new Exception('Missing asset_id or disposal_date');
    }
    // Validate date format (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        throw new Exception('Invalid disposal_date format');
    }
    // Ensure numeric proceeds
    if (!is_numeric($proceeds)) {
        throw new Exception('Invalid proceeds value');
    }
    // Load asset
    $stmt = $DB->prepare(
        "SELECT asset_id, asset_name, purchase_cost_cents, accumulated_depreciation_cents,
                asset_account_id, accumulated_depreciation_account_id, status
         FROM gl_fixed_assets
         WHERE company_id = ? AND asset_id = ?
         LIMIT 1"
    );
    $stmt->execute([$companyId, $assetId]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        throw new Exception('Asset not found');
    }
    if (strtolower($asset['status']) !== 'active') {
        throw new Exception('Asset is not active and cannot be disposed');
    }
    // Check the disposal date is not in a locked period
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($dateStr)) {
        throw new Exception('Cannot post to locked period (' . $dateStr . ')');
    }
    // Determine account codes
    $accounts = new AccountsMap($DB, $companyId);
    $assetCode = $accounts->getById((int)$asset['asset_account_id']);
    $accumCode = $accounts->getById((int)$asset['accumulated_depreciation_account_id']);
    if (!$assetCode || !$accumCode) {
        throw new Exception('Asset missing GL account mappings');
    }
    // Bank account for proceeds; fallback to 1110
    $bankCode = $accounts->get('finance_bank_account_id', '1110');
    // Gain and loss accounts; fallback to Other Income (4900) and General Expenses (6900)
    $gainCode = $accounts->get('finance_gain_on_disposal_account_id', '4900');
    $lossCode = $accounts->get('finance_loss_on_disposal_account_id', '6900');
    if (!$gainCode || !$lossCode) {
        throw new Exception('Missing gain/loss account mappings');
    }
    // Convert monetary amounts to decimals
    $costDec  = $asset['purchase_cost_cents'] / 100.0;
    $accumDec = $asset['accumulated_depreciation_cents'] / 100.0;
    $proceedsDec = round(floatval($proceeds), 2);
    // Compute book value and difference
    $bookValue = $costDec - $accumDec;
    $diff      = $proceedsDec - $bookValue;
    // Prepare amounts for journal lines (always positive values where appropriate)
    $bankAmt   = max(0, $proceedsDec);
    $accumAmt  = $accumDec;
    $assetAmt  = $costDec;
    $gainAmt   = $diff > 0 ? $diff : 0.0;
    $lossAmt   = $diff < 0 ? abs($diff) : 0.0;
    // Begin database transaction
    $DB->beginTransaction();
    // Insert journal entry
    $reference = 'DISP' . $assetId;
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
    // Debit bank for proceeds (if any)
    $stmtL = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit)
         VALUES (?, ?, ?, ?, ?)"
    );
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
    // Loss entry (debit if any)
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
    // Gain entry (credit if any)
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
    // Insert into fa_disposals table
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
    // Commit transaction
    $DB->commit();
    echo json_encode(['ok' => true, 'data' => ['journal_id' => $journalId], 'message' => 'Asset disposed successfully']);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>