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
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2110');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2120');

    $vatData = VatCalculator::calculate(
        $DB, $companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode
    );

    $outputVatCents = $vatData['total_output_vat_cents'];
    $inputVatCents  = $vatData['total_input_vat_cents'];
    $netVatCents    = $vatData['net_vat_cents'];

    // Persist totals in gl_vat_periods
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods
         SET output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?,
             status = 'prepared', prepared_by = ?, prepared_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([
        $outputVatCents,
        $inputVatCents,
        $netVatCents,
        $userId,
        $periodId
    ]);

    // Lock journal entries in this period (legacy flag for backward compatibility)
    $stmt = $DB->prepare(
        "UPDATE journal_entries
         SET is_locked = 1
         WHERE company_id = ?
           AND entry_date BETWEEN ? AND ?"
    );
    $stmt->execute([$companyId, $period['period_start'], $period['period_end']]);

    // Insert a period lock using gl_period_locks so the posting service respects it
    // We lock up to the period_end date inclusive
    // KNOWN LIMITATION: locking at period_end means preparing periods out of
    // order (e.g. preparing a later period before an earlier one) over-locks
    // earlier, still-open periods. Left as-is intentionally.
    $stmt = $DB->prepare(
        "INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at)
         VALUES (?, ?, 'vat_period_locked', ?, NOW())"
    );
    $stmt->execute([$companyId, $period['period_end'], $userId]);

    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'vat_period_locked', ?, ?, NOW())"
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