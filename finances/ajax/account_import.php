<?php
// /finances/ajax/account_import.php — CSV bulk import (admin only)
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../lib/CoaSchema.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();

$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// Accept multipart file upload
if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No CSV file uploaded']);
    exit;
}

// Reject files larger than 2 MB
if ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'CSV file too large (max 2 MB)']);
    exit;
}

$handle = fopen($_FILES['csv']['tmp_name'], 'r');
if (!$handle) {
    echo json_encode(['ok' => false, 'error' => 'Cannot read uploaded file']);
    exit;
}

// Expected header: code,name,type,subtype,normal_balance,parent_code,tax_code,afs_line_code,description
// Spaces in headers are normalised to underscores (e.g. "Normal Balance" → "normal_balance")
$header = fgetcsv($handle);
if (!$header) {
    fclose($handle);
    echo json_encode(['ok' => false, 'error' => 'Empty CSV file']);
    exit;
}

// Normalize header: lowercase, trim, and convert spaces to underscores
// so exported CSVs ("Normal Balance") map to expected keys ("normal_balance")
$header = array_map(fn($h) => str_replace(' ', '_', strtolower(trim($h))), $header);
$requiredCols = ['code', 'name', 'type'];
foreach ($requiredCols as $col) {
    if (!in_array($col, $header)) {
        fclose($handle);
        echo json_encode(['ok' => false, 'error' => "Missing required CSV column: $col"]);
        exit;
    }
}

$rows = [];
$lineNum = 1;
$errors = [];

while (($data = fgetcsv($handle)) !== false) {
    $lineNum++;
    if (count($data) !== count($header)) {
        $errors[] = "Row $lineNum: column count mismatch (expected " . count($header) . ", got " . count($data) . ")";
        continue;
    }
    $row = array_combine($header, $data);

    $code     = trim($row['code'] ?? '');
    $name     = trim($row['name'] ?? '');
    $type     = strtolower(trim($row['type'] ?? ''));
    $subtype  = strtolower(trim($row['subtype'] ?? ''));
    $normal   = strtolower(trim($row['normal_balance'] ?? ''));
    $parentCode = trim($row['parent_code'] ?? '');
    $taxCode    = trim($row['tax_code'] ?? '');
    $afsCode    = trim($row['afs_line_code'] ?? '');
    $desc     = trim($row['description'] ?? '');

    if (!$code || !$name || !$type) {
        $errors[] = "Row $lineNum: code, name, type are required";
        continue;
    }
    if (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
        $errors[] = "Row $lineNum: invalid type '$type'";
        continue;
    }
    if (!$normal) $normal = CoaSchema::defaultNormalBalance($type);
    if (!in_array($normal, ['debit', 'credit'], true)) {
        $errors[] = "Row $lineNum: invalid normal_balance '$normal'";
        continue;
    }
    if ($subtype && !CoaSchema::isValidSubtype($type, $subtype)) {
        $errors[] = "Row $lineNum: invalid subtype '$subtype' for type '$type'";
        continue;
    }
    if (!CoaSchema::isCodeInBand($type, $code)) {
        $errors[] = "Row $lineNum: code '$code' outside valid range for type '$type'";
        continue;
    }

    $rows[] = [
        'code' => $code, 'name' => $name, 'type' => $type,
        'subtype' => $subtype, 'normal' => $normal,
        'parent_code' => $parentCode, 'tax_code' => $taxCode,
        'afs_line_code' => $afsCode, 'desc' => $desc,
    ];
}
fclose($handle);

if (!empty($errors)) {
    echo json_encode(['ok' => false, 'error' => 'Validation failed', 'details' => $errors]);
    exit;
}

if (empty($rows)) {
    echo json_encode(['ok' => false, 'error' => 'No data rows found']);
    exit;
}

try {
    $DB->beginTransaction();

    $inserted = 0;
    foreach ($rows as $row) {
        // Check duplicate
        $stmt = $DB->prepare("SELECT COUNT(*) FROM gl_accounts WHERE company_id = ? AND account_code = ?");
        $stmt->execute([$companyId, $row['code']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Account code '{$row['code']}' already exists");
        }

        // Resolve parent
        $parentId = null;
        if ($row['parent_code']) {
            $stmt = $DB->prepare("SELECT account_id, account_type FROM gl_accounts WHERE company_id = ? AND account_code = ?");
            $stmt->execute([$companyId, $row['parent_code']]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) throw new Exception("Row '{$row['code']}': parent code '{$row['parent_code']}' not found");
            if ($parent['account_type'] !== $row['type']) throw new Exception("Row '{$row['code']}': parent type mismatch");
            $parentId = $parent['account_id'];
        }

        // Resolve tax code
        $taxCodeId = null;
        if ($row['tax_code']) {
            $stmt = $DB->prepare("SELECT tax_code_id FROM gl_tax_codes WHERE company_id = ? AND code = ? AND is_active = 1");
            $stmt->execute([$companyId, $row['tax_code']]);
            $tcId = $stmt->fetchColumn();
            if (!$tcId) throw new Exception("Row '{$row['code']}': tax code '{$row['tax_code']}' not found or inactive");
            $taxCodeId = (int)$tcId;
        }

        $stmt = $DB->prepare("
            INSERT INTO gl_accounts (
                company_id, account_code, account_name, description,
                account_type, normal_balance, account_subtype,
                parent_id, tax_code_id, afs_line_code,
                is_system, is_control, is_locked, allow_manual_journal,
                is_active, currency,
                created_by, updated_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 1, 1, 'ZAR', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $companyId, $row['code'], $row['name'], $row['desc'],
            $row['type'], $row['normal'], $row['subtype'],
            $parentId, $taxCodeId, $row['afs_line_code'] ?: null,
            $userId, $userId
        ]);
        $inserted++;
    }

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'accounts_imported', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['count' => $inserted]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();
    echo json_encode(['ok' => true, 'data' => ['imported' => $inserted]]);

} catch (Throwable $e) {
    if ($DB->inTransaction()) { $DB->rollBack(); }
    error_log("Account import error: " . $e->getMessage());
    // Hide PDO/engine detail from the client; business messages pass through.
    json_exception($e);
}
