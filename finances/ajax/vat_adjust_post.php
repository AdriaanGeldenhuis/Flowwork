<?php
// /finances/ajax/vat_adjust_post.php
// Endpoint to record manual VAT adjustments for a given period.
// Expects JSON payload: { period_id: int, lines: [ { account: 'output'|'input', amount: float, memo?: string } ] }
// Each line will post a debit or credit to the configured VAT accounts and create a balanced journal.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

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
// Optional client-supplied idempotency nonce. The adjustment modal generates
// one token per open and re-sends it on retry, so a double-click (or a network
// retry) resolves to the SAME journal instead of posting a second adjustment.
// Sanitised to a short token and stamped into the journal reference (the only
// dedup key available without a schema change).
$clientRef = '';
if (isset($input['client_ref']) && is_string($input['client_ref'])) {
    $clientRef = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $input['client_ref']), 0, 40);
}

if (!$periodId || !is_numeric($periodId)) {
    echo json_encode(['ok' => false, 'error' => 'Period ID is required']);
    exit;
}
if (!is_array($lines) || count($lines) === 0) {
    echo json_encode(['ok' => false, 'error' => 'At least one adjustment line is required']);
    exit;
}

try {
    // One transaction from the period read onward. Locking the period row
    // FOR UPDATE serialises concurrent submits: a double-click's second request
    // blocks here until the first commits, so the two can no longer interleave
    // and each post an independent adjustment journal.
    $DB->beginTransaction();
    $stmt = $DB->prepare("SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ? FOR UPDATE");
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        throw new Exception('VAT period not found');
    }
    // Only allow adjustments on prepared or already-adjusted periods
    if (!in_array($period['status'], ['prepared', 'adjusted'])) {
        throw new Exception('Adjustments are only allowed on prepared or adjusted periods');
    }

    // Reference carries the idempotency nonce when supplied. Under the row lock
    // above, a duplicate submit sees the journal the first submit already wrote
    // and short-circuits to it — the endpoint is now idempotent per nonce.
    $reference = $clientRef !== '' ? ('VAT Adj ' . $clientRef) : 'VAT Adjustment';
    if ($clientRef !== '') {
        $dup = $DB->prepare(
            "SELECT id FROM journal_entries
              WHERE company_id = ? AND module = 'vat_adjust'
                AND ref_type = 'vat_period' AND ref_id = ?
                AND reference = ? AND reversed_by_journal_id IS NULL
              LIMIT 1"
        );
        $dup->execute([$companyId, $periodId, $reference]);
        $dupId = $dup->fetchColumn();
        if ($dupId !== false) {
            $DB->commit();
            echo json_encode(['ok' => true, 'data' => ['journal_id' => (int)$dupId, 'idempotent' => true]]);
            exit;
        }
    }

    // Resolve VAT output and input account codes
    $accountsMap = new AccountsMap($DB, (int)$companyId);
    $vatOutputCode = $accountsMap->code('finance_vat_output_account_id');
    $vatInputCode  = $accountsMap->code('finance_vat_input_account_id');

    // Build journal lines in INTEGER CENTS: the previous float totals with an
    // "abs(imbalance) > 0.01" contra threshold let a 1-cent-imbalanced
    // journal post straight into the GL as status 'posted'.
    $journalLines = [];
    $totalDebitC  = 0;
    $totalCreditC = 0;
    foreach ($lines as $line) {
        $type    = isset($line['account']) ? strtolower(trim($line['account'])) : '';
        $amountC = (int)round(((float)($line['amount'] ?? 0)) * 100);
        $memo    = trim($line['memo'] ?? '');
        if ($type !== 'output' && $type !== 'input') {
            throw new Exception('Invalid account type for adjustment');
        }
        if ($amountC === 0) {
            continue; // skip zero lines
        }
        $accountCode = ($type === 'output') ? $vatOutputCode : $vatInputCode;
        $description = $memo !== '' ? $memo : 'VAT adjustment';
        // Output increase = credit VAT output; input increase = debit VAT input.
        $isDebit = ($type === 'output') ? ($amountC < 0) : ($amountC > 0);
        $absC = abs($amountC);
        $journalLines[] = [
            'account_code' => $accountCode,
            'description'  => $description,
            'debit'        => $isDebit ? $absC / 100 : 0,
            'credit'       => $isDebit ? 0 : $absC / 100,
        ];
        if ($isDebit) { $totalDebitC += $absC; } else { $totalCreditC += $absC; }
    }
    // Auto-balance: contra to VAT Control for ANY residual, including 1 cent.
    $imbalanceC = $totalDebitC - $totalCreditC;
    if ($imbalanceC !== 0) {
        $vatControlCode = $accountsMap->code('finance_vat_control_account_id');
        $journalLines[] = [
            'account_code' => $vatControlCode,
            'description'  => 'VAT adjustment contra',
            'debit'        => $imbalanceC < 0 ? -$imbalanceC / 100 : 0,
            'credit'       => $imbalanceC > 0 ? $imbalanceC / 100 : 0,
        ];
    }
    if (empty($journalLines)) {
        throw new Exception('No valid adjustment lines');
    }
    if (count($journalLines) < 2) {
        throw new Exception('Adjustment must produce a balanced journal — add a second line');
    }

    // Determine entry date: use period_end for consistency
    $entryDate = $period['period_end'];
    $memo      = 'VAT adjustment for period ' . $period['period_start'] . ' to ' . $period['period_end'];

    // Post through the engine choke point: period-lock check, integer-cents
    // balance assertion, account-existence check, audit trail, status
    // 'posted' — the previous hand-rolled INSERT enforced none of these.
    require_once __DIR__ . '/../lib/PostingService.php';
    $posting = new PostingService($DB, (int)$companyId, (int)$userId);
    $journalId = $posting->postAdHocJournal([
        'entry_date'  => $entryDate,
        'reference'   => $reference,
        'description' => $memo,
        'module'      => 'vat_adjust',
        'ref_type'    => 'vat_period',
        'ref_id'      => $periodId,
        'source_type' => 'vat_adjustment',
        'source_id'   => $periodId,
    ], $journalLines);

    // Update period status to adjusted and refresh the stored totals so the
    // filed figures include this adjustment (the snapshot taken at prepare
    // time predates any adjustments by definition — adjustments are only
    // allowed on prepared periods).
    require_once __DIR__ . '/../lib/VatCalculator.php';
    $freshTotals = VatCalculator::vat201Boxes(
        $DB, (int)$companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode,
        VatCalculator::periodBasis($DB, (int)$companyId, $period)
    );
    $stmt = $DB->prepare(
        "UPDATE gl_vat_periods
            SET status = 'adjusted',
                output_vat_cents = ?, input_vat_cents = ?, net_vat_cents = ?,
                updated_at = NOW()
          WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([
        $freshTotals['box5_total_output_cents'],
        $freshTotals['box9_total_input_cents'],
        $freshTotals['box10_net_cents'],
        $periodId, $companyId,
    ]);

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
    // Business-rule messages (locked period, unknown account) reach the
    // bookkeeper; PDO/engine details stay in the log.
    $msg = ($e instanceof PDOException) ? 'Failed to post VAT adjustment' : $e->getMessage();
    echo json_encode([
        'ok'    => false,
        'error' => $msg
    ]);
    exit;
}