<?php
// /finances/ajax/bank_apply_rules.php
// Applies active bank matching rules to unmatched bank transactions. Each
// unmatched transaction is evaluated against rule criteria, and a journal entry
// is created for the first matching rule. Once matched, the transaction is
// flagged so subsequent runs do not create duplicate entries. Transactions
// dated in locked periods are skipped. Only admin or bookkeeper roles may
// invoke this endpoint.

// Dynamically load initialization and authentication. Detect whether an `/app`
// folder exists and adjust accordingly.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}
require_once __DIR__ . '/../lib/PeriodService.php';

header('Content-Type: application/json');

// Ensure POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Check user role
$role = $_SESSION['role'] ?? 'member';
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);

try {
    $DB->beginTransaction();
    // Instantiate period service once
    $periodService = new PeriodService($DB, $companyId);
    // Load active bank rules with resolved account codes
    $stmt = $DB->prepare(
        "SELECT r.*, a.account_code AS rule_account_code
         FROM gl_bank_rules r
         LEFT JOIN gl_accounts a ON r.gl_account_id = a.account_id
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
                // Create journal entry for this match
                $description = $rule['description_template'] ?: ($tx['description'] ?: 'Bank transaction');
                $stmtJ = $DB->prepare(
                    "INSERT INTO journal_entries (
                        company_id, entry_date, reference, description, module, ref_type, ref_id,
                        source_type, source_id, created_by, created_at
                    ) VALUES (?, ?, ?, ?, 'fin', 'bank_rule', ?, 'bank_rule', ?, ?, NOW())"
                );
                $reference = 'BANKTX' . $tx['bank_tx_id'];
                $stmtJ->execute([
                    $companyId,
                    $tx['tx_date'],
                    $reference,
                    $description,
                    $tx['bank_tx_id'],
                    $tx['bank_tx_id'],
                    $userId
                ]);
                $journalId = (int)$DB->lastInsertId();
                // Calculate amount in decimal
                $amount = abs(intval($tx['amount_cents'])) / 100;
                // Determine bank account code if available
                $bankAccountCode = null;
                if (!empty($tx['bank_gl_account_id'])) {
                    $lookup = $DB->prepare("SELECT account_code FROM gl_accounts WHERE account_id = ? AND company_id = ?");
                    $lookup->execute([$tx['bank_gl_account_id'], $companyId]);
                    $bankAccountCode = $lookup->fetchColumn() ?: null;
                }
                // Determine rule account code
                $ruleAccountCode = $rule['rule_account_code'];
                if (!$ruleAccountCode) {
                    throw new Exception('GL account code not found for rule ' . $rule['id']);
                }
                // Insert journal lines
                $lineStmt = $DB->prepare(
                    "INSERT INTO journal_lines (journal_id, account_code, description, debit, credit, supplier_id, customer_id, reference) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)"
                );
                if (intval($tx['amount_cents']) > 0) {
                    // Money IN: Dr bank, Cr rule account
                    if ($bankAccountCode) {
                        $lineStmt->execute([$journalId, $bankAccountCode, $description, number_format($amount, 2, '.', ''), 0.0, $reference]);
                    }
                    $lineStmt->execute([$journalId, $ruleAccountCode, $description, 0.0, number_format($amount, 2, '.', ''), $reference]);
                } else {
                    // Money OUT: Dr rule account, Cr bank
                    $lineStmt->execute([$journalId, $ruleAccountCode, $description, number_format($amount, 2, '.', ''), 0.0, $reference]);
                    if ($bankAccountCode) {
                        $lineStmt->execute([$journalId, $bankAccountCode, $description, 0.0, number_format($amount, 2, '.', ''), $reference]);
                    }
                }
                // Mark transaction as matched
                $stmtU = $DB->prepare(
                    "UPDATE gl_bank_transactions SET matched = 1, journal_id = ? WHERE bank_tx_id = ? AND company_id = ?"
                );
                $stmtU->execute([$journalId, $tx['bank_tx_id'], $companyId]);
                $matchCount++;
                break; // Stop checking further rules for this transaction
            }
        }
    }
    $DB->commit();
    echo json_encode(['ok' => true, 'matched' => $matchCount]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Bank apply rules error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}