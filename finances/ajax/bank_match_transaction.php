<?php
// /finances/ajax/bank_match_transaction.php
// Matches an individual bank transaction to a GL account by creating a journal entry.
// Only finance roles (admin/bookkeeper) may perform this operation. The endpoint
// prevents matching a transaction that has already been matched or that falls
// into a locked accounting period.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

Csrf::validate();

// Check user role
$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Decode input
$input = json_decode(file_get_contents('php://input'), true);
$bankTxId = $input['bank_tx_id'] ?? null;
$accountCodeInput = isset($input['account_code']) ? trim($input['account_code']) : '';
$taxCodeId = !empty($input['tax_code_id']) ? (int)$input['tax_code_id'] : null;

if (!$bankTxId || !$accountCodeInput) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $DB->beginTransaction();
    // Retrieve the bank transaction along with the bank GL account id
    $stmt = $DB->prepare(
        "SELECT bt.*, ba.gl_account_id AS bank_gl_account_id
         FROM gl_bank_transactions bt
         JOIN gl_bank_accounts ba ON bt.bank_account_id = ba.id
         WHERE bt.bank_tx_id = ? AND bt.company_id = ? FOR UPDATE"
    );
    $stmt->execute([$bankTxId, $companyId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        throw new Exception('Transaction not found');
    }
    // Check for locked period
    $periodService = new PeriodService($DB, $companyId);
    if ($periodService->isLocked($tx['tx_date'])) {
        throw new Exception('Cannot match transaction to locked period (' . $tx['tx_date'] . ')');
    }
    // Ensure not already matched
    if (!empty($tx['matched']) && intval($tx['matched']) === 1) {
        throw new Exception('Transaction already matched');
    }
    // Validate the other side account code exists
    $stmt = $DB->prepare("SELECT account_code FROM gl_accounts WHERE company_id = ? AND account_code = ?");
    $stmt->execute([$companyId, $accountCodeInput]);
    $validatedAccountCode = $stmt->fetchColumn();
    if (!$validatedAccountCode) {
        throw new Exception('Account code not found');
    }
    // Create a journal entry for this match
    $stmt = $DB->prepare(
        "INSERT INTO journal_entries (
            company_id, entry_date, reference, description, module, ref_type, ref_id,
            source_type, source_id, created_by, created_at, status, posted_by, posted_at
         ) VALUES (?, ?, ?, ?, 'fin', 'bank_tx', ?, 'bank_tx', ?, ?, NOW(), 'posted', ?, NOW())"
    );
    $reference = 'BANKTX' . $bankTxId;
    $description = $tx['description'] ?: 'Bank transaction';
    $stmt->execute([
        $companyId,
        $tx['tx_date'],
        $reference,
        $description,
        $bankTxId,
        $bankTxId,
        $userId,
        $userId
    ]);
    $journalId = (int)$DB->lastInsertId();
    // Determine the transaction amount as a positive decimal
    $amount = abs(intval($tx['amount_cents'])) / 100;
    // Determine the GL account code for the bank account if available
    $bankAccountCode = null;
    if (!empty($tx['bank_gl_account_id'])) {
        $lookup = $DB->prepare("SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ?");
        $lookup->execute([$tx['bank_gl_account_id'], $companyId]);
        $bankAccountCode = $lookup->fetchColumn() ?: null;
    }
    // Reject match if bank GL account can't be resolved — would create unbalanced journal
    if (!$bankAccountCode) {
        throw new Exception('Bank GL account not configured for this bank account');
    }
    // When a VAT tax code is selected the bank amount is VAT-INCLUSIVE: split
    // it into a net leg on the chosen account and a VAT leg on the VAT
    // output/input account (tax fraction), so the VAT201 sees the tax. The
    // previous behaviour posted the gross amount with only a tag — VAT on
    // bank-matched card sales and charges never reached the VAT accounts.
    $amountC = abs((int)$tx['amount_cents']);
    $netC = $amountC;
    $vatC = 0;
    $vatLegCode = null;
    $isMoneyIn = (int)$tx['amount_cents'] > 0;
    if ($taxCodeId) {
        $tcStmt = $DB->prepare("SELECT rate_percent FROM gl_tax_codes WHERE tax_code_id = ? AND company_id = ? AND is_active = 1");
        $tcStmt->execute([$taxCodeId, $companyId]);
        $rate = (float)$tcStmt->fetchColumn();
        if ($rate > 0.005) {
            // Tax fraction: VAT = gross * r/(100+r)
            $vatC = (int)round($amountC * $rate / (100 + $rate));
            $netC = $amountC - $vatC;
            require_once __DIR__ . '/../lib/AccountsMap.php';
            $accountsMap = new AccountsMap($DB, $companyId);
            $vatLegCode = $isMoneyIn
                ? $accountsMap->code('finance_vat_output_account_id')
                : $accountsMap->code('finance_vat_input_account_id');
        }
    }

    // Insert journal lines (debits/credits) with tax code for SARS VAT tracking
    $lineStmt = $DB->prepare(
        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, tax_code_id, supplier_id, customer_id, reference) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?)"
    );
    $fmt = fn(int $c) => number_format($c / 100, 2, '.', '');
    if ($isMoneyIn) {
        // Money IN: Dr bank (gross), Cr other (net), Cr VAT output (vat)
        $lineStmt->execute([$journalId, $bankAccountCode, $description, $fmt($amountC), 0.0, null, $reference]);
        $lineStmt->execute([$journalId, $validatedAccountCode, $description, 0.0, $fmt($netC), $taxCodeId, $reference]);
        if ($vatC > 0 && $vatLegCode) {
            $lineStmt->execute([$journalId, $vatLegCode, 'VAT on ' . $description, 0.0, $fmt($vatC), $taxCodeId, $reference]);
        }
    } else {
        // Money OUT: Dr other (net), Dr VAT input (vat), Cr bank (gross)
        $lineStmt->execute([$journalId, $validatedAccountCode, $description, $fmt($netC), 0.0, $taxCodeId, $reference]);
        if ($vatC > 0 && $vatLegCode) {
            $lineStmt->execute([$journalId, $vatLegCode, 'VAT on ' . $description, $fmt($vatC), 0.0, $taxCodeId, $reference]);
        }
        $lineStmt->execute([$journalId, $bankAccountCode, $description, 0.0, $fmt($amountC), null, $reference]);
    }
    // Mark the bank transaction as matched and store journal id. The
    // matched=0 predicate + rowCount guard backstops a double-click race:
    // the loser must roll back its journal, not overwrite the winner's link.
    $stmt = $DB->prepare(
        "UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ? AND matched = 0"
    );
    $stmt->execute([$journalId, $bankTxId, $companyId]);
    if ($stmt->rowCount() === 0) {
        throw new Exception('Transaction already matched');
    }
    // Audit log
    $audit = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'bank_tx_matched', ?, ?, NOW())"
    );
    $audit->execute([
        $companyId,
        $userId,
        json_encode(['bank_tx_id' => $bankTxId, 'journal_id' => $journalId]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    $DB->commit();
    echo json_encode(['ok' => true, 'journal_id' => $journalId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Bank match error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to match transaction']);
}