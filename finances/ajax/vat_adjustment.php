<?php
// /finances/ajax/vat_adjustment.php
//
// Endpoint to record manual VAT adjustments for a given VAT period. This allows
// small corrections to be applied after preparing a VAT return but before
// filing. The adjustments are posted as a balanced journal entry against
// the company’s VAT control accounts. Only users with admin or bookkeeper
// roles may perform this action. The period must not already be filed or
// paid.

// Dynamically load init, auth, and permissions depending on project structure.
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
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

// Only allow POST requests
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

// Parse JSON input
$input    = json_decode(file_get_contents('php://input'), true);
$periodId = isset($input['period_id']) ? (int)$input['period_id'] : 0;
$lines    = $input['lines'] ?? [];

if ($periodId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Period ID is required']);
    exit;
}
if (!is_array($lines) || count($lines) === 0) {
    echo json_encode(['ok' => false, 'error' => 'At least one adjustment line is required']);
    exit;
}

try {
    // Retrieve the VAT period and ensure it is not filed or paid
    $stmt = $DB->prepare("SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        throw new Exception('VAT period not found');
    }
    $status = strtolower((string)$period['status']);
    if (in_array($status, ['filed', 'paid'], true)) {
        throw new Exception('Adjustments are not allowed on filed or paid periods');
    }

    // Resolve VAT control account codes
    $accountsMap = new AccountsMap($DB, (int)$companyId);
    $vatOutputCode = $accountsMap->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accountsMap->get('finance_vat_input_account_id', '2130');
    if (!$vatOutputCode || !$vatInputCode) {
        throw new Exception('VAT accounts are not configured');
    }

    // Build journal lines
    $journalLines = [];
    $totalDebit  = 0.0;
    $totalCredit = 0.0;
    foreach ($lines as $line) {
        $type   = strtolower(trim($line['account'] ?? ''));
        $amount = (float)($line['amount'] ?? 0);
        $memo   = trim($line['memo'] ?? '');
        if ($type !== 'output' && $type !== 'input') {
            throw new Exception('Invalid account type for adjustment');
        }
        if ($amount == 0.0) {
            continue;
        }
        // Determine account code and debit/credit values
        $accountCode = ($type === 'output') ? $vatOutputCode : $vatInputCode;
        $description = ($memo !== '') ? $memo : 'VAT adjustment';
        if ($type === 'output') {
            if ($amount > 0) {
                // Increase output VAT: credit VAT output
                $journalLines[] = [
                    'account_code' => $accountCode,
                    'description'  => $description,
                    'debit'        => 0.0,
                    'credit'       => round($amount, 2)
                ];
                $totalCredit += round($amount, 2);
            } else {
                // Decrease output VAT: debit VAT output
                $abs = abs($amount);
                $journalLines[] = [
                    'account_code' => $accountCode,
                    'description'  => $description,
                    'debit'        => round($abs, 2),
                    'credit'       => 0.0
                ];
                $totalDebit += round($abs, 2);
            }
        } else { // input
            if ($amount > 0) {
                // Increase input VAT: debit VAT input
                $journalLines[] = [
                    'account_code' => $accountCode,
                    'description'  => $description,
                    'debit'        => round($amount, 2),
                    'credit'       => 0.0
                ];
                $totalDebit += round($amount, 2);
            } else {
                // Decrease input VAT: credit VAT input
                $abs = abs($amount);
                $journalLines[] = [
                    'account_code' => $accountCode,
                    'description'  => $description,
                    'debit'        => 0.0,
                    'credit'       => round($abs, 2)
                ];
                $totalCredit += round($abs, 2);
            }
        }
    }
    // Ensure there are valid lines
    if (empty($journalLines)) {
        throw new Exception('No valid adjustment lines');
    }
    // Ensure the journal balances
    if (abs($totalDebit - $totalCredit) > 0.01) {
        throw new Exception('Adjustment is not balanced. Debits: ' . $totalDebit . ', Credits: ' . $totalCredit);
    }
    if ($totalDebit == 0.0 && $totalCredit == 0.0) {
        throw new Exception('Adjustment has no value');
    }

    // Use the period end date as the entry date for the adjustment
    $entryDate = $period['period_end'];
    $reference = 'VAT Adjustment';
    $memoDesc  = 'VAT adjustment for period ' . $period['period_start'] . ' to ' . $period['period_end'];

    $DB->beginTransaction();

    // Insert journal entry
    $stmt = $DB->prepare(
        "INSERT INTO journal_entries (
            company_id, entry_date, memo, reference, module, ref_type, ref_id, created_by, created_at
        ) VALUES (?, ?, ?, ?, 'vat_adjust', 'vat_period', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $entryDate,
        $memoDesc,
        $reference,
        $periodId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();

    // Prepare statement for inserting journal lines
    $stmtLine = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, reference)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($journalLines as $jl) {
        $stmtLine->execute([
            $journalId,
            $jl['account_code'],
            $jl['description'],
            number_format($jl['debit'], 2, '.', ''),
            number_format($jl['credit'], 2, '.', ''),
            $reference
        ]);
    }

    // Mark the period as adjusted if it was prepared; keep status unchanged if already adjusted
    if ($status === 'open') {
        $newStatus = 'adjusted';
    } elseif ($status === 'prepared') {
        $newStatus = 'adjusted';
    } else {
        $newStatus = $status;
    }
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$newStatus, $periodId, $companyId]);

    // Audit log entry
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
         VALUES (?, ?, 'vat_adjustment', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['period_id' => $periodId, 'journal_id' => $journalId, 'lines' => $lines]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok'   => true,
        'data' => ['journal_id' => $journalId]
    ]);
    exit;

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('VAT adjustment error: ' . $e->getMessage());
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
