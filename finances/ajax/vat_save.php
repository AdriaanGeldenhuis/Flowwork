<?php
// /finances/ajax/vat_save.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
// Include PeriodService to insert a period lock
require_once __DIR__ . '/../lib/PeriodService.php';
// Include AccountsMap to resolve VAT account codes
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

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

    // Before updating status, compute and persist VAT totals for this period.
    // This ensures list view shows accurate amounts after locking the period.
    // Resolve VAT account codes and ids
    $accounts = new AccountsMap($DB, $companyId);
    $stmt = $DB->prepare(
        "SELECT setting_key, setting_value FROM company_settings
         WHERE company_id = ? AND setting_key IN ('finance_vat_output_account_id','finance_vat_input_account_id')"
    );
    $stmt->execute([$companyId]);
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $rawOutput = $settings['finance_vat_output_account_id'] ?? null;
    $rawInput  = $settings['finance_vat_input_account_id'] ?? null;
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2130');
    $vatOutputId = (is_numeric($rawOutput) ? (int)$rawOutput : null);
    $vatInputId  = (is_numeric($rawInput) ? (int)$rawInput : null);

    // Calculate total output VAT for this period (credit minus debit)
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(
                COALESCE(jl.credit, jl.credit_cents/100.0) - COALESCE(jl.debit, jl.debit_cents/100.0)
            ), 0) AS total_output
         FROM journal_lines jl
         JOIN journal_entries je ON jl.journal_id = je.id
         WHERE je.company_id = ?
           AND je.entry_date BETWEEN ? AND ?
           AND (
                jl.account_code = ?
             OR (? IS NOT NULL AND jl.account_id = ?)
           )"
    );
    $stmt->execute([
        $companyId,
        $period['period_start'],
        $period['period_end'],
        $vatOutputCode,
        $vatOutputId,
        $vatOutputId
    ]);
    $outputVat = (float)$stmt->fetchColumn();

    // Calculate total input VAT for this period (debit minus credit)
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(
                COALESCE(jl.debit, jl.debit_cents/100.0) - COALESCE(jl.credit, jl.credit_cents/100.0)
            ), 0) AS total_input
         FROM journal_lines jl
         JOIN journal_entries je ON jl.journal_id = je.id
         WHERE je.company_id = ?
           AND je.entry_date BETWEEN ? AND ?
           AND (
                jl.account_code = ?
             OR (? IS NOT NULL AND jl.account_id = ?)
           )"
    );
    $stmt->execute([
        $companyId,
        $period['period_start'],
        $period['period_end'],
        $vatInputCode,
        $vatInputId,
        $vatInputId
    ]);
    $inputVat = (float)$stmt->fetchColumn();

    // Convert to cents
    $outputVatCents = (int)round($outputVat * 100);
    $inputVatCents  = (int)round($inputVat * 100);
    $netVatCents    = $outputVatCents - $inputVatCents;

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
    $DB->rollBack();
    error_log("VAT save error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}