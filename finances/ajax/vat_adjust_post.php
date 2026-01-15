<?php
// /finances/ajax/vat_adjust_post.php
// Endpoint to record manual VAT adjustments for a given period.
// Expects JSON payload: { period_id: int, lines: [ { account: 'output'|'input', amount: float, memo?: string } ] }
// Each line will post a debit or credit to the configured VAT accounts and create a balanced journal.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

// Ensure company and user context
$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorised']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);
$periodId = $input['period_id'] ?? null;
$lines    = $input['lines'] ?? [];

if (!$periodId || !is_numeric($periodId)) {
    echo json_encode(['ok' => false, 'error' => 'Period ID is required']);
    exit;
}
if (!is_array($lines) || count($lines) === 0) {
    echo json_encode(['ok' => false, 'error' => 'At least one adjustment line is required']);
    exit;
}

try {
    // Fetch period and validate status
    $stmt = $DB->prepare("SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ?");
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        throw new Exception('VAT period not found');
    }
    // Prevent adjustments on filed or paid periods
    if (in_array($period['status'], ['filed', 'paid'])) {
        throw new Exception('Adjustments are not allowed on filed periods');
    }

    // Resolve VAT output and input account codes
    $accountsMap = new AccountsMap($DB, (int)$companyId);
    $vatOutputCode = $accountsMap->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accountsMap->get('finance_vat_input_account_id', '2130');

    // Build journal lines arrays
    $journalLines = [];
    $totalDebit  = 0.0;
    $totalCredit = 0.0;
    foreach ($lines as $line) {
        $type   = isset($line['account']) ? strtolower(trim($line['account'])) : '';
        $amount = (float)($line['amount'] ?? 0);
        $memo   = trim($line['memo'] ?? '');
        if ($type !== 'output' && $type !== 'input') {
            throw new Exception('Invalid account type for adjustment');
        }
        if ($amount == 0.0) {
            continue; // skip zero lines
        }
        // Determine account code and debit/credit
        $accountCode = ($type === 'output') ? $vatOutputCode : $vatInputCode;
        $description = $memo !== '' ? $memo : 'VAT adjustment';
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
    // After processing, ensure journal is balanced
    if (abs($totalDebit - $totalCredit) > 0.01) {
        throw new Exception('Adjustment is not balanced. Debits: ' . $totalDebit . ', Credits: ' . $totalCredit);
    }
    if (empty($journalLines)) {
        throw new Exception('No valid adjustment lines');
    }

    // Determine entry date: use period_end for consistency
    $entryDate = $period['period_end'];
    $reference = 'VAT Adjustment';
    $memo      = 'VAT adjustment for period ' . $period['period_start'] . ' to ' . $period['period_end'];

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
        $memo,
        $reference,
        $periodId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();

    // Prepare journal lines insert statement
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

    // Update period status to adjusted
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods SET status = 'adjusted', updated_at = NOW() WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$periodId, $companyId]);

    // Audit log
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
        'data' => [ 'journal_id' => $journalId ]
    ]);
    exit;
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('VAT adjust error: ' . $e->getMessage());
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
    exit;
}