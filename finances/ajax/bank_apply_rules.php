<?php
// /finances/ajax/bank_apply_rules.php
// Applies active bank matching rules to unmatched bank transactions. Each
// unmatched transaction is evaluated against rule criteria, and a journal entry
// is created for the first matching rule. Once matched, the transaction is
// flagged so subsequent runs do not create duplicate entries. Transactions
// dated in locked periods are skipped. Only admin or bookkeeper roles may
// invoke this endpoint.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/PeriodService.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

// Ensure POST method
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

try {
    // Instantiate period service once
    $periodService = new PeriodService($DB, $companyId);
    // Load active bank rules with resolved account codes
    $stmt = $DB->prepare(
        "SELECT r.*, a.account_code AS rule_account_code
         FROM gl_bank_rules r
         LEFT JOIN gl_accounts a ON r.gl_account_id = a.account_id AND a.company_id = r.company_id
         WHERE r.company_id = ? AND r.is_active = 1
         ORDER BY r.priority ASC, r.id ASC"
    );
    $stmt->execute([$companyId]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Fetch all unmatched transactions and their bank GL ids
    $stmt = $DB->prepare(
        "SELECT bt.*, ba.gl_account_id AS bank_gl_account_id
         FROM gl_bank_transactions bt
         JOIN gl_bank_accounts ba ON bt.bank_account_id = ba.id
         WHERE bt.company_id = ? AND bt.matched = 0"
    );
    $stmt->execute([$companyId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $matchCount = 0;
    $skippedCount = 0;
    $warnings = [];
    // Cache of bank GL account_id => account_code lookups (company-scoped)
    $bankCodeCache = [];
    foreach ($transactions as $tx) {
        // Skip locked periods
        try {
            if ($periodService->isLocked($tx['tx_date'])) {
                continue;
            }
        } catch (Exception $lockEx) {
            // On error, skip this transaction
            continue;
        }
        // A zero-value transaction would post a 0.00/0.00 journal — skip it.
        if ((int)$tx['amount_cents'] === 0) {
            continue;
        }
        foreach ($rules as $rule) {
            $matches = false;
            $fieldValue = $tx[$rule['match_field']] ?? '';
            switch ($rule['match_operator']) {
                case 'contains':
                    $matches = stripos($fieldValue, $rule['match_value']) !== false;
                    break;
                case 'starts_with':
                    $matches = stripos($fieldValue, $rule['match_value']) === 0;
                    break;
                case 'equals':
                    $matches = strcasecmp($fieldValue, $rule['match_value']) === 0;
                    break;
            }
            if ($matches) {
                // Double-check lock before posting
                if ($periodService->isLocked($tx['tx_date'])) {
                    continue;
                }
                // Resolve ALL account codes BEFORE any insert so a failed
                // resolution can never leave an orphan journal header behind.
                $bankGlId = (int)($tx['bank_gl_account_id'] ?? 0);
                if ($bankGlId && !array_key_exists($bankGlId, $bankCodeCache)) {
                    $lookup = $DB->prepare("SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ?");
                    $lookup->execute([$bankGlId, $companyId]);
                    $bankCodeCache[$bankGlId] = $lookup->fetchColumn() ?: null;
                }
                $bankAccountCode = $bankGlId ? $bankCodeCache[$bankGlId] : null;
                if (!$bankAccountCode) {
                    // Skip transaction — bank GL account not configured, would create unbalanced journal
                    $skippedCount++;
                    $warnings[] = 'Skipped bank tx #' . $tx['bank_tx_id'] . ': bank GL account not configured';
                    break;
                }
                $ruleAccountCode = $rule['rule_account_code'];
                if (!$ruleAccountCode) {
                    // Skip transaction — rule GL account could not be resolved
                    $skippedCount++;
                    $warnings[] = 'Skipped bank tx #' . $tx['bank_tx_id'] . ': GL account for rule "' . $rule['rule_name'] . '" not found';
                    break;
                }
                $description = $rule['description_template'] ?: ($tx['description'] ?: 'Bank transaction');
                $reference = 'BANKTX' . $tx['bank_tx_id'];
                $ruleTaxCodeId = !empty($rule['tax_code_id']) ? (int)$rule['tax_code_id'] : null;

                // When the rule carries a VAT tax code the bank amount is
                // VAT-INCLUSIVE: split it into a net leg on the rule account and
                // a VAT leg on the VAT output/input account (tax fraction), so
                // the VAT201 sees the tax. Mirrors bank_match_transaction.php —
                // posting the gross with only a tag left VAT off the GL.
                $amountC    = abs((int)$tx['amount_cents']);
                $isMoneyIn  = (int)$tx['amount_cents'] > 0;
                $netC       = $amountC;
                $vatC       = 0;
                $vatLegCode = null;
                if ($ruleTaxCodeId) {
                    $tcStmt = $DB->prepare("SELECT rate_percent FROM gl_tax_codes WHERE tax_code_id = ? AND company_id = ? AND is_active = 1");
                    $tcStmt->execute([$ruleTaxCodeId, $companyId]);
                    $rateRaw = $tcStmt->fetchColumn();
                    if ($rateRaw === false) {
                        // No active tax code with that id — drop the tag entirely.
                        $ruleTaxCodeId = null;
                    } elseif ((float)$rateRaw > 0.005) {
                        $rate = (float)$rateRaw;
                        $vatC = (int)round($amountC * $rate / (100 + $rate));
                        $netC = $amountC - $vatC;
                        require_once __DIR__ . '/../lib/AccountsMap.php';
                        $accountsMap = new AccountsMap($DB, $companyId);
                        $vatLegCode = $isMoneyIn
                            ? $accountsMap->code('finance_vat_output_account_id')
                            : $accountsMap->code('finance_vat_input_account_id');
                        // Can't resolve a VAT account: fall back to a gross 2-line
                        // posting so the journal always balances.
                        if ($vatC > 0 && !$vatLegCode) {
                            $netC = $amountC;
                            $vatC = 0;
                        }
                    }
                    // A valid ZERO-rated / EXEMPT code (rate 0) keeps its tag on the
                    // net line (no VAT leg) so the zero/exempt base isn't dropped.
                }
                $fmt = fn(int $c) => number_format($c / 100, 2, '.', '');

                // Atomically create header + lines + matched flag for this transaction
                try {
                    $DB->beginTransaction();
                    // Re-lock the row and re-check matched INSIDE the transaction
                    // so two concurrent apply-rules runs (or a race with a manual
                    // match) can't both post a journal for the same line.
                    $lockStmt = $DB->prepare("SELECT matched FROM gl_bank_transactions WHERE bank_tx_id = ? AND company_id = ? FOR UPDATE");
                    $lockStmt->execute([$tx['bank_tx_id'], $companyId]);
                    $lockedMatched = $lockStmt->fetchColumn();
                    if ($lockedMatched === false || (int)$lockedMatched === 1) {
                        $DB->rollBack();
                        break; // already matched by a concurrent run
                    }
                    // Create journal entry for this match (status=posted for SARS compliance).
                    // ref_type MUST be 'bank_tx' — the payments-basis VAT201
                    // (VatCalculator) and the VAT audit file (VatAuditFile) select
                    // bank VAT legs by je.ref_type = 'bank_tx' only. Posting these
                    // rule matches as 'bank_rule' hid their output/input VAT from
                    // the return and the substantiation CSV. source_type stays
                    // 'bank_rule' so the origin (auto-rule vs manual match) is still
                    // traceable.
                    $stmtJ = $DB->prepare(
                        "INSERT INTO journal_entries (
                            company_id, entry_date, reference, description, module, ref_type, ref_id,
                            source_type, source_id, created_by, created_at, status, posted_by, posted_at
                        ) VALUES (?, ?, ?, ?, 'fin', 'bank_tx', ?, 'bank_rule', ?, ?, NOW(), 'posted', ?, NOW())"
                    );
                    $stmtJ->execute([
                        $companyId,
                        $tx['tx_date'],
                        $reference,
                        $description,
                        $tx['bank_tx_id'],
                        $tx['bank_tx_id'],
                        $userId,
                        $userId
                    ]);
                    $journalId = (int)$DB->lastInsertId();
                    // Insert journal lines with tax code for SARS VAT tracking
                    $lineStmt = $DB->prepare(
                        "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, tax_code_id, supplier_id, customer_id, reference) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?)"
                    );
                    if ($isMoneyIn) {
                        // Money IN: Dr bank (gross), Cr rule account (net), Cr VAT output (vat)
                        $lineStmt->execute([$journalId, $bankAccountCode, $description, $fmt($amountC), 0.0, null, $reference]);
                        $lineStmt->execute([$journalId, $ruleAccountCode, $description, 0.0, $fmt($netC), $ruleTaxCodeId, $reference]);
                        if ($vatC > 0 && $vatLegCode) {
                            $lineStmt->execute([$journalId, $vatLegCode, 'VAT on ' . $description, 0.0, $fmt($vatC), $ruleTaxCodeId, $reference]);
                        }
                    } else {
                        // Money OUT: Dr rule account (net), Dr VAT input (vat), Cr bank (gross)
                        $lineStmt->execute([$journalId, $ruleAccountCode, $description, $fmt($netC), 0.0, $ruleTaxCodeId, $reference]);
                        if ($vatC > 0 && $vatLegCode) {
                            $lineStmt->execute([$journalId, $vatLegCode, 'VAT on ' . $description, $fmt($vatC), 0.0, $ruleTaxCodeId, $reference]);
                        }
                        $lineStmt->execute([$journalId, $bankAccountCode, $description, 0.0, $fmt($amountC), null, $reference]);
                    }
                    // Mark transaction as matched. The matched=0 predicate +
                    // rowCount guard backstops the FOR UPDATE check above.
                    $stmtU = $DB->prepare(
                        "UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ? AND matched = 0"
                    );
                    $stmtU->execute([$journalId, $tx['bank_tx_id'], $companyId]);
                    if ($stmtU->rowCount() === 0) {
                        throw new Exception('Transaction already matched (concurrent run)');
                    }
                    $DB->commit();
                    $matchCount++;
                } catch (Exception $postEx) {
                    if ($DB->inTransaction()) {
                        $DB->rollBack();
                    }
                    $skippedCount++;
                    $warnings[] = 'Skipped bank tx #' . $tx['bank_tx_id'] . ': posting failed';
                    error_log('Bank apply rules: posting failed for tx ' . $tx['bank_tx_id'] . ': ' . $postEx->getMessage());
                }
                break; // Stop checking further rules for this transaction
            }
        }
    }
    // Audit log for bulk rule application
    if ($matchCount > 0 || $skippedCount > 0) {
        $audit = $DB->prepare(
            "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'bank_rules_applied', ?, ?, NOW())"
        );
        $audit->execute([
            $companyId,
            $userId,
            json_encode(['matched_count' => $matchCount, 'skipped_count' => $skippedCount]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }
    echo json_encode(['ok' => true, 'matched' => $matchCount, 'skipped' => $skippedCount, 'warnings' => $warnings]);
} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }
    error_log('Bank apply rules error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to apply rules']);
}