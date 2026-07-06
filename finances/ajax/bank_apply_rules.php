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
         ORDER BY r.priority ASC"
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
                $amount = abs(intval($tx['amount_cents'])) / 100;
                $ruleTaxCodeId = !empty($rule['tax_code_id']) ? (int)$rule['tax_code_id'] : null;
                // Atomically create header + lines + matched flag for this transaction
                try {
                    $DB->beginTransaction();
                    // Create journal entry for this match (status=posted for SARS compliance)
                    $stmtJ = $DB->prepare(
                        "INSERT INTO journal_entries (
                            company_id, entry_date, reference, description, module, ref_type, ref_id,
                            source_type, source_id, created_by, created_at, status, posted_by, posted_at
                        ) VALUES (?, ?, ?, ?, 'fin', 'bank_rule', ?, 'bank_rule', ?, ?, NOW(), 'posted', ?, NOW())"
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
                    if (intval($tx['amount_cents']) > 0) {
                        // Money IN: Dr bank, Cr rule account
                        $lineStmt->execute([$journalId, $bankAccountCode, $description, number_format($amount, 2, '.', ''), 0.0, null, $reference]);
                        $lineStmt->execute([$journalId, $ruleAccountCode, $description, 0.0, number_format($amount, 2, '.', ''), $ruleTaxCodeId, $reference]);
                    } else {
                        // Money OUT: Dr rule account, Cr bank
                        $lineStmt->execute([$journalId, $ruleAccountCode, $description, number_format($amount, 2, '.', ''), 0.0, $ruleTaxCodeId, $reference]);
                        $lineStmt->execute([$journalId, $bankAccountCode, $description, 0.0, number_format($amount, 2, '.', ''), null, $reference]);
                    }
                    // Mark transaction as matched
                    $stmtU = $DB->prepare(
                        "UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ?"
                    );
                    $stmtU->execute([$journalId, $tx['bank_tx_id'], $companyId]);
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