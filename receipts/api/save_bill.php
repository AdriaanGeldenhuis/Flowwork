<?php
// Save a draft AP bill from header and lines. Creates ap_bills and ap_bill_lines entries.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];

// Permission gating: only allow admins or bookkeepers to save
$userRole = $_SESSION['role'] ?? 'member';
if (!in_array($userRole, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

// Expect JSON POST body
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$header = isset($input['header']) ? $input['header'] : [];
$lines = isset($input['lines']) ? $input['lines'] : [];

// Validate header
if (empty($header['supplier_id']) || empty($header['invoice_number']) || empty($header['invoice_date']) || !isset($header['total'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing required header fields']);
    exit;
}

$supplierId = (int)$header['supplier_id'];
$projectBoardId = isset($header['project_board_id']) ? (int)$header['project_board_id'] : null;
$invoiceNumber = trim($header['invoice_number']);
$invoiceDate = $header['invoice_date'];
$currency = $header['currency'] ?? 'ZAR';
$subtotal = isset($header['subtotal']) ? (float)$header['subtotal'] : 0;
$tax = isset($header['tax']) ? (float)$header['tax'] : 0;
$total = (float)$header['total'];
$fileId = isset($header['file_id']) ? (int)$header['file_id'] : 0;

// Compute fingerprint for duplicate detection
$hash = sha1($invoiceNumber . '|' . $invoiceDate . '|' . $total . '|' . $supplierId);

// Check for duplicates
$stmt = $DB->prepare("SELECT id FROM ap_bills WHERE company_id = ? AND hash_fingerprint = ? LIMIT 1");
$stmt->execute([$companyId, $hash]);
$duplicateRow = $stmt->fetch();
if ($duplicateRow) {
    // Return structured error for duplicate detection
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => 'DUPLICATE',
            'message' => 'This bill looks like a duplicate.'
        ]
    ]);
    exit;
}

// Determine ocr_id from receipt_ocr
$ocrId = null;
if ($fileId) {
    $stmt = $DB->prepare("SELECT ocr_id FROM receipt_ocr WHERE file_id = ?");
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();
    if ($row && isset($row['ocr_id'])) {
        $ocrId = (int)$row['ocr_id'];
    }
}

try {
    $DB->beginTransaction();
    // Insert into ap_bills
    $stmt = $DB->prepare(
        "INSERT INTO ap_bills (
            company_id, supplier_id, vendor_invoice_number, vendor_vat, issue_date, due_date,
            currency, subtotal, tax, total, status, ocr_id, file_id, hash_fingerprint, created_by, created_at
        ) VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, 'review', ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $supplierId,
        $invoiceNumber,
        $invoiceDate,
        $currency,
        $subtotal,
        $tax,
        $total,
        $ocrId,
        $fileId,
        $hash,
        $userId
    ]);
    $billId = (int)$DB->lastInsertId();
    // Insert lines
    $sort = 0;
    foreach ($lines as $line) {
        $desc = isset($line['description']) ? trim($line['description']) : '';
        $qty = isset($line['qty']) ? (float)$line['qty'] : 1;
        $unit = isset($line['unit']) ? trim($line['unit']) : 'ea';
        $price = isset($line['unit_price']) ? (float)$line['unit_price'] : 0;
        $lineTotal = $qty * $price;
        $glAccountId = isset($line['gl_account_id']) && $line['gl_account_id'] ? (int)$line['gl_account_id'] : null;
        $projBoardId = $projectBoardId; // default to header project
        $projItemId = isset($line['project_item_id']) && $line['project_item_id'] ? (int)$line['project_item_id'] : null;
        $taxRate = 15.00;
        $stmt2 = $DB->prepare(
            "INSERT INTO ap_bill_lines (
                bill_id, item_description, quantity, unit, unit_price, discount, tax_rate,
                line_total, sort_order, gl_account_id, project_board_id, project_item_id
            ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)"
        );
        $stmt2->execute([
            $billId,
            $desc,
            $qty,
            $unit,
            $price,
            $taxRate,
            $lineTotal,
            $sort,
            $glAccountId,
            $projBoardId,
            $projItemId
        ]);
        $sort++;
    }
    // Update receipt_file with bill_id
    if ($fileId) {
        $stmt = $DB->prepare("UPDATE receipt_file SET bill_id = ? WHERE file_id = ? AND company_id = ?");
        $stmt->execute([$billId, $fileId, $companyId]);
    }
    $DB->commit();

    // === Section 14: Update AI mapping for supplier and description tokens ===
    try {
        // Load existing map
        $stmt = $DB->prepare(
            "SELECT id, setting_value FROM company_settings WHERE company_id = ? AND setting_key = 'receipts_ai_map' LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $row = $stmt->fetch();
        $map = ['supplier_default_gl' => [], 'token_map' => []];
        if ($row && $row['setting_value']) {
            $decoded = json_decode($row['setting_value'], true);
            if (is_array($decoded)) {
                $decoded += ['supplier_default_gl' => [], 'token_map' => []];
                $map = $decoded;
            }
        }
        // Update supplier default GL
        if ($supplierId && !empty($lines)) {
            // Use the first line's gl_account_id as default if available
            foreach ($lines as $l) {
                if (isset($l['gl_account_id']) && $l['gl_account_id']) {
                    $map['supplier_default_gl'][$supplierId] = (int)$l['gl_account_id'];
                    break;
                }
            }
        }
        // Update token map
        foreach ($lines as $l) {
            if (isset($l['gl_account_id']) && $l['gl_account_id'] && !empty($l['description'])) {
                $descLower = strtolower($l['description']);
                $tokens = preg_split('/[^a-z0-9]+/', $descLower, -1, PREG_SPLIT_NO_EMPTY);
                if ($tokens) {
                    foreach ($tokens as $tok) {
                        $map['token_map'][$tok] = (int)$l['gl_account_id'];
                    }
                }
            }
        }
        $mapJson = json_encode($map);
        if ($row && $row['id']) {
            // Update existing
            $stmt = $DB->prepare("UPDATE company_settings SET setting_value = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$mapJson, $row['id']]);
        } else {
            // Insert new
            $stmt = $DB->prepare("INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at) VALUES (?, 'receipts_ai_map', ?, NOW())");
            $stmt->execute([$companyId, $mapJson]);
        }
    } catch (Exception $e) {
        // If mapping update fails, log but do not block the response
        error_log('AI mapping update error: ' . $e->getMessage());
    }

    // === Section 17: Audit logging for bill save/approve ===
    try {
        $auditDetails = json_encode([
            'bill_id' => $billId,
            'invoice_number' => $invoiceNumber,
            'total' => $total
        ]);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmtAudit = $DB->prepare(
            "INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, 'ap_bill_saved', 'ap_bill', ?, ?, ?)"
        );
        $stmtAudit->execute([$companyId, $userId, $billId, $auditDetails, $ip]);
    } catch (Exception $ex) {
        // If audit insert fails, log but do not block the response
        error_log('Audit log error (save_bill): ' . $ex->getMessage());
    }

    echo json_encode(['ok' => true, 'bill_id' => $billId]);
} catch (Exception $e) {
    $DB->rollBack();
    error_log('Save bill error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save bill']);
}