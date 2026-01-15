<?php
// /finances/ajax/vat_prepare_period.php
//
// Endpoint to prepare a VAT period. This script calculates the total output
// and input VAT across journal entries within the specified VAT period,
// persists these totals on the gl_vat_periods record, locks the period
// against further postings by inserting a gl_period_locks row, and
// writes an audit log entry. Only admin or bookkeeper roles may
// perform this operation.

// Dynamically load init, auth, and permissions; support both /app and project root structures.
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

// Accept only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Enforce finance role permissions
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
$userId    = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

// Decode the incoming JSON payload
$input    = json_decode(file_get_contents('php://input'), true);
$periodId = isset($input['period_id']) ? (int)$input['period_id'] : 0;
if ($periodId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Period ID required']);
    exit;
}

try {
    // Fetch the VAT period and validate it belongs to the current company
    $stmt = $DB->prepare(
        "SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ? LIMIT 1"
    );
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        throw new Exception('VAT period not found');
    }
    // Only allow preparation on an open period
    if (strtolower((string)$period['status']) !== 'open') {
        throw new Exception('Period is already ' . $period['status']);
    }

    // Resolve VAT account codes and IDs from company settings
    $accounts = new AccountsMap($DB, (int)$companyId);
    // Load raw setting values (could be account IDs or codes)
    $stmt = $DB->prepare(
        "SELECT setting_key, setting_value FROM company_settings
         WHERE company_id = ?
           AND setting_key IN ('finance_vat_output_account_id', 'finance_vat_input_account_id')"
    );
    $stmt->execute([$companyId]);
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $rawOutput = $settings['finance_vat_output_account_id'] ?? null;
    $rawInput  = $settings['finance_vat_input_account_id'] ?? null;
    // Resolve codes via AccountsMap (defaults to SA VAT control accounts)
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2130');
    // Determine numeric IDs if settings specify them
    $vatOutputId = (is_numeric($rawOutput) ? (int)$rawOutput : null);
    $vatInputId  = (is_numeric($rawInput)  ? (int)$rawInput  : null);
    if (!$vatOutputCode || !$vatInputCode) {
        throw new Exception('Please configure VAT accounts in Finance Settings');
    }

    // Start transaction for atomic update and lock
    $DB->beginTransaction();

    // Calculate total output VAT (credit minus debit) for the period
    $stmt = $DB->prepare(
        "SELECT
            COALESCE(SUM(
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

    // Calculate total input VAT (debit minus credit) for the period
    $stmt = $DB->prepare(
        "SELECT
            COALESCE(SUM(
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

    // Convert to cents for storage
    $outputVatCents = (int)round($outputVat * 100);
    $inputVatCents  = (int)round($inputVat * 100);
    $netVatCents    = $outputVatCents - $inputVatCents;

    // Persist totals and mark the period as prepared
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods
         SET output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?,
             status = 'prepared', prepared_by = ?, prepared_at = NOW()
         WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([
        $outputVatCents,
        $inputVatCents,
        $netVatCents,
        $userId,
        $periodId,
        $companyId
    ]);

    // Lock all journal entries within this period (legacy flag)
    $stmt = $DB->prepare(
        "UPDATE journal_entries
         SET is_locked = 1
         WHERE company_id = ?
           AND entry_date BETWEEN ? AND ?"
    );
    $stmt->execute([
        $companyId,
        $period['period_start'],
        $period['period_end']
    ]);

    // Insert a period lock up to the period end date inclusive so future postings are blocked
    $stmt = $DB->prepare(
        "INSERT INTO gl_period_locks (company_id, lock_date, lock_reason, locked_by, locked_at)
         VALUES (?, ?, 'vat_period_locked', ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $period['period_end'],
        $userId
    ]);

    // Write audit log entry
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'vat_period_prepared', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode([
            'period_id' => $periodId,
            'output_vat_cents' => $outputVatCents,
            'input_vat_cents' => $inputVatCents,
            'net_vat_cents' => $netVatCents
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => [
            'period_id' => $periodId,
            'output_vat_cents' => $outputVatCents,
            'input_vat_cents' => $inputVatCents,
            'net_vat_cents' => $netVatCents
        ]
    ]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('VAT prepare period error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
