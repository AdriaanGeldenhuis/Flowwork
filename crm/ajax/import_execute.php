<?php
// /crm/ajax/import_execute.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

// Disable buffering for streaming
ob_implicit_flush(true);
ob_end_flush();

header('Content-Type: application/json');
header('X-Accel-Buffering: no');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

function streamProgress($percent, $message) {
    echo json_encode(['progress' => $percent, 'message' => $message]) . "\n";
    flush();
}

function streamComplete($total, $successful, $failed, $errors, $dryRun) {
    echo json_encode([
        'complete' => true,
        'total' => $total,
        'successful' => $successful,
        'failed' => $failed,
        'errors' => $errors,
        'dry_run' => $dryRun
    ]) . "\n";
    flush();
}

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['file'];
    $type = $_POST['type'] ?? 'accounts';
    $mapping = json_decode($_POST['mapping'] ?? '{}', true);
    $dryRun = ($_POST['dry_run'] ?? '0') === '1';
    $skipDuplicates = ($_POST['skip_duplicates'] ?? '1') === '1';

    // Create import history record
    $stmt = $DB->prepare("
        INSERT INTO crm_import_history 
        (company_id, user_id, filename, type, total_rows, status, started_at, created_at)
        VALUES (?, ?, ?, ?, 0, 'processing', NOW(), NOW())
    ");
    $stmt->execute([$companyId, $userId, $file['name'], $type]);
    $historyId = $DB->lastInsertId();

    streamProgress(5, 'Opening file...');

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        throw new Exception('Could not open file');
    }

    // Read headers
    $fileHeaders = fgetcsv($handle);
    
    // Create reverse mapping (file column -> CRM field)
    $fieldMapping = [];
    foreach ($mapping as $fileCol => $crmField) {
        if ($crmField) {
            $colIndex = array_search($fileCol, $fileHeaders);
            if ($colIndex !== false) {
                $fieldMapping[$colIndex] = $crmField;
            }
        }
    }

    streamProgress(10, 'Mapping columns...');

    $totalRows = 0;
    $successful = 0;
    $failed = 0;
    $errors = [];

    if (!$dryRun) {
        $DB->beginTransaction();
    }

    streamProgress(15, 'Processing rows...');

    while (($row = fgetcsv($handle)) !== false) {
        $totalRows++;
        $rowData = [];

        // Map row data to CRM fields
        foreach ($fieldMapping as $fileColIndex => $crmField) {
            $rowData[$crmField] = trim($row[$fileColIndex] ?? '');
        }

        // Calculate progress (15% to 90%)
        $progressPercent = 15 + (($totalRows / max($totalRows + 1, 1)) * 75);
        if ($totalRows % 10 === 0) {
            streamProgress($progressPercent, "Processing row {$totalRows}...");
        }

        try {
            if ($type === 'accounts') {
                importAccount($rowData, $companyId, $userId, $skipDuplicates, $dryRun);
            } elseif ($type === 'contacts') {
                importContact($rowData, $companyId, $userId, $skipDuplicates, $dryRun);
            } elseif ($type === 'addresses') {
                importAddress($rowData, $companyId, $userId, $dryRun);
            }
            $successful++;
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Row {$totalRows}: " . $e->getMessage();
        }
    }

    fclose($handle);

    streamProgress(95, 'Finalizing...');

    if (!$dryRun) {
        $DB->commit();
    }

    // Update history
    $stmt = $DB->prepare("
        UPDATE crm_import_history SET
            total_rows = ?,
            successful_rows = ?,
            failed_rows = ?,
            status = 'completed',
            error_log = ?,
            completed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $totalRows,
        $successful,
        $failed,
        implode("\n", $errors),
        $historyId
    ]);

    streamProgress(100, 'Import complete!');
    streamComplete($totalRows, $successful, $failed, $errors, $dryRun);

} catch (Exception $e) {
    if ($DB->inTransaction()) {
        $DB->rollBack();
    }

    if (isset($historyId)) {
        $stmt = $DB->prepare("
            UPDATE crm_import_history SET
                status = 'failed',
                error_log = ?,
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $historyId]);
    }

    error_log("Import execute error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]) . "\n";
}

// ========== IMPORT FUNCTIONS ==========

function importAccount($data, $companyId, $userId, $skipDuplicates, $dryRun) {
    global $DB;

    $name = $data['name'] ?? '';
    if (!$name) {
        throw new Exception('Name is required');
    }

    // Check for duplicates
    if ($skipDuplicates) {
        $stmt = $DB->prepare("
            SELECT id FROM crm_accounts 
            WHERE company_id = ? AND (
                name = ? 
                OR (email IS NOT NULL AND email = ?)
                OR (vat_no IS NOT NULL AND vat_no = ?)
            )
        ");
        $stmt->execute([
            $companyId,
            $name,
            $data['email'] ?? null,
            $data['vat_no'] ?? null
        ]);
        if ($stmt->fetch()) {
            throw new Exception('Duplicate account found (skipped)');
        }
    }

    if ($dryRun) {
        return; // Validation passed, don't insert
    }

    // Normalize phone
    $phone = $data['phone'] ?? '';
    if ($phone && !preg_match('/^\+/', $phone)) {
        $phone = '+27' . ltrim($phone, '0');
    }

    // Insert account
    $stmt = $DB->prepare("
        INSERT INTO crm_accounts (
            company_id, type, name, legal_name, reg_no, vat_no,
            phone, email, website, status, notes, created_by, created_at
        ) VALUES (?, 'supplier', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $companyId,
        $name,
        $data['legal_name'] ?? null,
        $data['reg_no'] ?? null,
        $data['vat_no'] ?? null,
        $phone ?: null,
        $data['email'] ?? null,
        $data['website'] ?? null,
        $data['status'] ?? 'active',
        $data['notes'] ?? null,
        $userId
    ]);
}

function importContact($data, $companyId, $userId, $skipDuplicates, $dryRun) {
    global $DB;

    $accountName = $data['account_name'] ?? '';
    if (!$accountName) {
        throw new Exception('Account name is required');
    }

    // Find account
    $stmt = $DB->prepare("
        SELECT id FROM crm_accounts 
        WHERE company_id = ? AND name = ?
    ");
    $stmt->execute([$companyId, $accountName]);
    $account = $stmt->fetch();

    if (!$account) {
        throw new Exception("Account '{$accountName}' not found");
    }

    $accountId = $account['id'];

    // Check for duplicates
    if ($skipDuplicates && !empty($data['email'])) {
        $stmt = $DB->prepare("
            SELECT id FROM crm_contacts 
            WHERE company_id = ? AND account_id = ? AND email = ?
        ");
        $stmt->execute([$companyId, $accountId, $data['email']]);
        if ($stmt->fetch()) {
            throw new Exception('Duplicate contact found (skipped)');
        }
    }

    if ($dryRun) {
        return;
    }

    $phone = $data['phone'] ?? '';
    if ($phone && !preg_match('/^\+/', $phone)) {
        $phone = '+27' . ltrim($phone, '0');
    }

    $isPrimary = (isset($data['is_primary']) && in_array(strtolower($data['is_primary']), ['1', 'yes', 'true'])) ? 1 : 0;

    $stmt = $DB->prepare("
        INSERT INTO crm_contacts (
            company_id, account_id, first_name, last_name, role_title,
            phone, email, is_primary, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $companyId,
        $accountId,
        $data['first_name'] ?? null,
        $data['last_name'] ?? null,
        $data['role_title'] ?? null,
        $phone ?: null,
        $data['email'] ?? null,
        $isPrimary,
        $userId
    ]);
}

function importAddress($data, $companyId, $userId, $dryRun) {
    global $DB;

    $accountName = $data['account_name'] ?? '';
    $line1 = $data['line1'] ?? '';

    if (!$accountName || !$line1) {
        throw new Exception('Account name and address line 1 are required');
    }

    // Find account
    $stmt = $DB->prepare("
        SELECT id FROM crm_accounts 
        WHERE company_id = ? AND name = ?
    ");
    $stmt->execute([$companyId, $accountName]);
    $account = $stmt->fetch();

    if (!$account) {
        throw new Exception("Account '{$accountName}' not found");
    }

    if ($dryRun) {
        return;
    }

    $stmt = $DB->prepare("
        INSERT INTO crm_addresses (
            company_id, account_id, type, line1, line2,
            city, region, postal_code, country, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $companyId,
        $account['id'],
        $data['type'] ?? 'head_office',
        $line1,
        $data['line2'] ?? null,
        $data['city'] ?? null,
        $data['region'] ?? null,
        $data['postal_code'] ?? null,
        strtoupper($data['country'] ?? 'ZA')
    ]);
}