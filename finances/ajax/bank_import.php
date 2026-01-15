<?php

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_method('POST');
Csrf::validate();
// /finances/ajax/bank_import.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$bankAccountId = $_POST['bank_account_id'] ?? null;

if (!$bankAccountId) {
    echo json_encode(['ok' => false, 'error' => 'Bank account ID required']);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

try {
    $DB->beginTransaction();

    // Verify bank account belongs to company
    $stmt = $DB->prepare("SELECT name FROM gl_bank_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$bankAccountId, $companyId]);
    $bankAccount = $stmt->fetch();

    if (!$bankAccount) {
        throw new Exception('Bank account not found');
    }

    // Record an import batch. Use the new bank_import_batches table to
    // track this upload. We treat the auto-increment id as the batch
    // identifier. Use the uploaded file's name for the file_name
    // column.
    $stmt = $DB->prepare("
        INSERT INTO bank_import_batches (
            company_id, bank_account_id, file_name, imported_by
        ) VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $bankAccountId,
        $_FILES['csv_file']['name'] ?? 'import.csv',
        $userId
    ]);
    $batchId = (string)$DB->lastInsertId();

    // Parse CSV
    $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $importCount = 0;

    // Skip header row
    fgetcsv($file);

    while (($row = fgetcsv($file)) !== false) {
        if (count($row) < 3) continue; // Need at least date, description, amount

        $txDate = $row[0];
        $description = $row[1];
        $amount = floatval($row[2]);
        $reference = $row[3] ?? null;

        // Convert amount to cents
        $amountCents = round($amount * 100);

        // Check for duplicates
        $stmt = $DB->prepare("
            SELECT COUNT(*) FROM gl_bank_transactions 
            WHERE company_id = ? 
            AND bank_account_id = ? 
            AND tx_date = ? 
            AND description = ? 
            AND amount_cents = ?
        ");
        $stmt->execute([$companyId, $bankAccountId, $txDate, $description, $amountCents]);
        
        if ($stmt->fetchColumn() > 0) {
            continue; // Skip duplicate
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

    fclose($file);

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
        VALUES (?, ?, 'bank_statement_imported', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['bank_account_id' => $bankAccountId, 'count' => $importCount]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => ['count' => $importCount]
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Bank import error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}