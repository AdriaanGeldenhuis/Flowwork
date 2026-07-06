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
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
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

header('Content-Type: application/json');

// Parse JSON input
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

try {
    $assetId   = isset($data['asset_id']) ? (int)$data['asset_id'] : 0;
    $dateStr   = trim($data['disposal_date'] ?? '');
    $proceeds  = isset($data['proceeds']) ? (float)$data['proceeds'] : 0.0;
    $notes     = isset($data['notes']) ? trim($data['notes']) : null;
    $includeVat = !empty($data['include_vat']);
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
    // Load and LOCK the asset — the whole flow (read status → post journal →
    // mark disposed) is check-then-act; a double-submitted disposal used to
    // credit the asset cost twice.
    $DB->beginTransaction();
    $stmt = $DB->prepare(
        "SELECT asset_id, asset_name, purchase_cost_cents, accumulated_depreciation_cents,
                asset_account_id, accumulated_depreciation_account_id, status
         FROM gl_fixed_assets
         WHERE company_id = ? AND asset_id = ?
         LIMIT 1 FOR UPDATE"
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
    $bankCode = $accounts->code('finance_bank_account_id');
    // Gain and loss accounts; fallback to Other Income (4900) and General Expenses (6900)
    $gainCode = $accounts->code('finance_gain_on_disposal_account_id');
    $lossCode = $accounts->code('finance_loss_on_disposal_account_id');
    if (!$gainCode || !$lossCode) {
        throw new Exception('Missing gain/loss account mappings');
    }
    // VAT output account for disposal proceeds (SARS Section 8(13))
    $vatOutputCode = $accounts->code('finance_vat_output_account_id');
    // Convert monetary amounts to decimals
    $costDec  = $asset['purchase_cost_cents'] / 100.0;
    $accumDec = $asset['accumulated_depreciation_cents'] / 100.0;
    $proceedsDec = round(floatval($proceeds), 2);
    // Calculate VAT on proceeds if applicable (VAT-inclusive tax fraction at
    // the company's configured standard rate, tagged with the STD code so the
    // VAT201 output build-up includes the disposal — a hardcoded 15/115 with
    // no tax_code_id left the disposal's output VAT out of the filed boxes).
    require_once __DIR__ . '/../../lib/TaxCodes.php';
    $taxCodes = new TaxCodes($DB, (int)$companyId);
    $stdRate = $taxCodes->standardRatePercent();
    $vatAmt = 0.0;
    $vatTaxCodeId = null;
    if ($includeVat && $proceedsDec > 0) {
        $vatAmt = round($proceedsDec * $stdRate / (100 + $stdRate), 2);
        $vatTaxCodeId = $taxCodes->resolveOutputForLine(null, 'STD', $stdRate);
    }
    $proceedsExVat = $proceedsDec - $vatAmt;
    // Compute book value and difference (gain/loss based on ex-VAT proceeds)
    $bookValue = $costDec - $accumDec;
    $diff      = $proceedsExVat - $bookValue;
    // Prepare amounts for journal lines (always positive values where appropriate)
    $bankAmt   = max(0, $proceedsDec);
    $accumAmt  = $accumDec;
    $assetAmt  = $costDec;
    $gainAmt   = $diff > 0 ? $diff : 0.0;
    $lossAmt   = $diff < 0 ? abs($diff) : 0.0;
    // Build journal lines and post through the engine choke point (balance
    // assertion, account existence, audit trail).
    $reference = 'DISP' . $assetId;
    $journalLines = [];
    if ($bankAmt > 0.00001) {
        $journalLines[] = ['account_code' => $bankCode, 'description' => 'Proceeds from asset disposal',
            'debit' => round($bankAmt, 2), 'credit' => 0];
    }
    if ($accumAmt > 0.00001) {
        $journalLines[] = ['account_code' => $accumCode, 'description' => 'Reverse accumulated depreciation',
            'debit' => round($accumAmt, 2), 'credit' => 0];
    }
    if ($lossAmt > 0.00001) {
        $journalLines[] = ['account_code' => $lossCode, 'description' => 'Loss on disposal',
            'debit' => round($lossAmt, 2), 'credit' => 0];
    }
    if ($assetAmt > 0.00001) {
        $journalLines[] = ['account_code' => $assetCode, 'description' => 'Remove asset cost',
            'debit' => 0, 'credit' => round($assetAmt, 2)];
    }
    if ($gainAmt > 0.00001) {
        $journalLines[] = ['account_code' => $gainCode, 'description' => 'Gain on disposal',
            'debit' => 0, 'credit' => round($gainAmt, 2)];
    }
    // VAT output on disposal proceeds (SARS Section 8(13))
    if ($vatAmt > 0.00001) {
        $journalLines[] = ['account_code' => $vatOutputCode,
            'description' => 'VAT on disposal proceeds (' . rtrim(rtrim(number_format($stdRate, 2, '.', ''), '0'), '.') . '%)',
            'debit' => 0, 'credit' => round($vatAmt, 2), 'tax_code_id' => $vatTaxCodeId];
    }

    require_once __DIR__ . '/../../lib/PostingService.php';
    $posting = new PostingService($DB, (int)$companyId, (int)$userId);
    $journalId = $posting->postAdHocJournal([
        'entry_date'  => $dateStr,
        'reference'   => $reference,
        'description' => 'Disposal of asset ' . $asset['asset_name'],
        'module'      => 'fin',
        'ref_type'    => 'fa_disposal',
        'ref_id'      => $assetId,
        'source_type' => 'fa_disposal',
        'source_id'   => $assetId,
    ], $journalLines);

    // Update asset record: mark disposed. status='active' predicate +
    // rowCount guard backstop a concurrent disposal that slipped the lock.
    $stmtU = $DB->prepare(
        "UPDATE gl_fixed_assets
         SET status = 'disposed', disposal_date = ?, disposal_proceeds_cents = ?, disposal_journal_id = ?, updated_at = NOW()
         WHERE asset_id = ? AND company_id = ? AND status = 'active'"
    );
    $stmtU->execute([
        $dateStr,
        (int)round($proceedsDec * 100),
        $journalId,
        $assetId,
        $companyId
    ]);
    if ($stmtU->rowCount() === 0) {
        throw new Exception('Asset was disposed by another request');
    }
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
    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'fa_asset_disposed', ?, ?, NOW())"
    );
    $stmt->execute([$companyId, $userId, json_encode(['asset_id' => $assetId, 'journal_id' => $journalId, 'proceeds' => $proceedsDec, 'date' => $dateStr]), $_SERVER['REMOTE_ADDR'] ?? null]);
    echo json_encode(['success' => true, 'data' => ['journal_id' => $journalId], 'message' => 'Asset disposed successfully']);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('FA asset dispose error: ' . $e->getMessage());
    $msg = ($e instanceof PDOException) ? 'Failed to dispose asset' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $msg]);
}
?>