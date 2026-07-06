<?php
// /finances/ajax/vat_file.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();
requireRoles(['admin']);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$periodId = $input['period_id'] ?? null;

if (!$periodId) {
    echo json_encode(['ok' => false, 'error' => 'Period ID required']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare(
        "SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        throw new Exception('VAT period not found');
    }

    // Accept both prepared and adjusted periods for filing
    $status = strtolower((string)$period['status']);
    if (!in_array($status, ['prepared', 'adjusted'], true)) {
        throw new Exception('Period must be prepared or adjusted before filing');
    }

    // Recompute totals at filing time so the stored figures are exactly what
    // was filed — including any adjustments posted after prepare.
    require_once __DIR__ . '/../lib/AccountsMap.php';
    require_once __DIR__ . '/../lib/VatCalculator.php';
    $accounts = new AccountsMap($DB, (int)$companyId);
    $filedTotals = VatCalculator::calculate(
        $DB, (int)$companyId,
        $period['period_start'], $period['period_end'],
        $accounts->code('finance_vat_output_account_id'),
        $accounts->code('finance_vat_input_account_id')
    );

    // Update period status to filed
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods
         SET status = 'filed', filed_by = ?, filed_at = NOW(),
             output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?
         WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([
        $userId,
        $filedTotals['total_output_vat_cents'],
        $filedTotals['total_input_vat_cents'],
        $filedTotals['net_vat_cents'],
        $periodId, $companyId,
    ]);

    // Insert a period lock if one does not already exist at or after this date
    $stmt = $DB->prepare(
        "SELECT 1 FROM gl_period_locks WHERE company_id = ? AND lock_date >= ? LIMIT 1"
    );
    $stmt->execute([$companyId, $period['period_end']]);
    if (!$stmt->fetchColumn()) {
        $stmt = $DB->prepare(
            "INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at)
             VALUES (?, ?, 'vat_period_filed', ?, NOW())"
        );
        $stmt->execute([$companyId, $period['period_end'], $userId]);
    }

    // Audit log
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'vat_period_filed', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['period_id' => $periodId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log("VAT file error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to file VAT return'
    ]);
}
