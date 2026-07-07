<?php
// /finances/ajax/vat_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/VatCalculator.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$periodId = isset($input['period_id']) ? (int)$input['period_id'] : 0;

if ($periodId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Period ID required']);
    exit;
}

try {
    $DB->beginTransaction();

    // Get period
    $stmt = $DB->prepare("
        SELECT * FROM gl_vat_periods 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        throw new Exception('VAT period not found');
    }

    if ($period['status'] !== 'open') {
        throw new Exception('Period is already ' . $period['status']);
    }

    // SARS: do not allow preparing a VAT return when its period_end
    // already falls inside a locked accounting period
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($period['period_end'])) {
        throw new Exception('Cannot prepare VAT for a locked period (' . $period['period_end'] . ')');
    }

    // Compute VAT totals using centralised helper
    $accounts = new AccountsMap($DB, $companyId);
    $vatOutputCode = $accounts->code('finance_vat_output_account_id');
    $vatInputCode  = $accounts->code('finance_vat_input_account_id');

    $basis = VatCalculator::companyBasis($DB, (int)$companyId);
    $vatData = VatCalculator::vat201Boxes(
        $DB, $companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode,
        $basis
    );

    // Snapshot the Box 5/9/10 totals (adjustment-inclusive) and stamp the
    // basis the return was prepared on.
    $outputVatCents = $vatData['box5_total_output_cents'];
    $inputVatCents  = $vatData['box9_total_input_cents'];
    $netVatCents    = $vatData['box10_net_cents'];

    // Atomic + company-scoped transition: the status='open' predicate and
    // rowCount guard stop two concurrent prepares from both snapshotting and
    // inserting duplicate period locks / audit rows.
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods
         SET output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?, basis = ?,
             status = 'prepared', prepared_by = ?, prepared_at = NOW()
         WHERE id = ? AND company_id = ? AND status = 'open'"
    );
    $stmt->execute([
        $outputVatCents,
        $inputVatCents,
        $netVatCents,
        $basis,
        $userId,
        $periodId,
        $companyId
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new Exception('Period is no longer open — refresh and try again');
    }

    // Lock journal entries in this period (legacy flag for backward compatibility)
    $stmt = $DB->prepare(
        "UPDATE journal_entries
         SET is_locked = 1
         WHERE company_id = ?
           AND entry_date BETWEEN ? AND ?"
    );
    $stmt->execute([$companyId, $period['period_start'], $period['period_end']]);

    // The period is intentionally NOT locked at prepare time. Locking here
    // (the previous behaviour) sealed period_end and made it impossible to post
    // the VAT adjustments — and the settlement journal — that must land on
    // period_end between prepare and file, so every adjustment threw "Cannot
    // post into locked period". The period is now sealed only on FILE
    // (vat_file.php posts the settlement journal, then inserts the gl_period_locks
    // row). File recomputes the box totals, so any adjustment posted in the
    // meantime is captured in the filed figures.

    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'vat_period_prepared', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['period_id' => $periodId, 'output_vat_cents' => $outputVatCents, 'input_vat_cents' => $inputVatCents, 'net_vat_cents' => $netVatCents]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("VAT save error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to prepare VAT period'
    ]);
}