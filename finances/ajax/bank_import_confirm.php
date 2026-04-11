<?php
// /finances/ajax/bank_import_confirm.php
// Receives user-confirmed transaction data (from PDF preview or edited)
// and inserts them into gl_bank_transactions. This is the second step
// after bank_import_parse_pdf.php extracts and the user reviews.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

Csrf::validate();

$role = strtolower($_SESSION['role'] ?? 'member');
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$bankAccountId = isset($input['bank_account_id']) ? (int)$input['bank_account_id'] : 0;
$transactions = $input['transactions'] ?? [];
$sourceFile = $input['source_file'] ?? 'pdf_import.pdf';

if (!$bankAccountId) {
    echo json_encode(['ok' => false, 'error' => 'Bank account ID required']);
    exit;
}

if (empty($transactions)) {
    echo json_encode(['ok' => false, 'error' => 'No transactions to import']);
    exit;
}

try {
    $DB->beginTransaction();

    // Verify bank account belongs to company
    $stmt = $DB->prepare("SELECT name FROM gl_bank_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$bankAccountId, $companyId]);
    if (!$stmt->fetch()) {
        throw new Exception('Bank account not found');
    }

    // Create import batch
    $stmt = $DB->prepare("
        INSERT INTO bank_import_batches (
            company_id, bank_account_id, file_name, imported_by
        ) VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$companyId, $bankAccountId, $sourceFile, $userId]);
    $batchId = (string)$DB->lastInsertId();

    $importCount = 0;
    $skippedCount = 0;

    foreach ($transactions as $tx) {
        $txDate = $tx['tx_date'] ?? null;
        $description = trim($tx['description'] ?? '');
        $amountCents = (int)($tx['amount_cents'] ?? 0);
        $reference = isset($tx['reference']) ? trim($tx['reference']) : null;

        // Validate date
        if (!$txDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txDate)) {
            $skippedCount++;
            continue;
        }

        // Skip zero amounts
        if ($amountCents === 0) {
            $skippedCount++;
            continue;
        }

        // Check for duplicates
        $stmt = $DB->prepare("
            SELECT COUNT(*) FROM gl_bank_transactions
            WHERE company_id = ? AND bank_account_id = ?
            AND tx_date = ? AND description = ? AND amount_cents = ?
        ");
        $stmt->execute([$companyId, $bankAccountId, $txDate, $description, $amountCents]);
        if ($stmt->fetchColumn() > 0) {
            $skippedCount++;
            continue;
        }

        // Insert transaction
        $stmt = $DB->prepare("
            INSERT INTO gl_bank_transactions (
                company_id, bank_account_id, tx_date, description,
                amount_cents, reference, matched, import_batch_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW())
        ");
        $stmt->execute([
            $companyId,
            $bankAccountId,
            $txDate,
            $description,
            $amountCents,
            $reference,
            $batchId
        ]);
        $importCount++;
    }

    // Update bank account balance
    $stmt = $DB->prepare("
        UPDATE gl_bank_accounts
        SET current_balance_cents = opening_balance_cents + (
            SELECT COALESCE(SUM(amount_cents), 0)
            FROM gl_bank_transactions
            WHERE bank_account_id = ?
        )
        WHERE id = ?
    ");
    $stmt->execute([$bankAccountId, $bankAccountId]);

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'bank_pdf_imported', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode([
            'bank_account_id' => $bankAccountId,
            'source' => $sourceFile,
            'count' => $importCount,
            'skipped' => $skippedCount
        ]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => [
            'count' => $importCount,
            'skipped' => $skippedCount
        ]
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Bank PDF import confirm error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to confirm import']);
}
