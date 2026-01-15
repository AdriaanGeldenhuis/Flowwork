<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/ap_from_receipt.php
//
// Build a draft AP bill from an uploaded receipt using OCR data.
// This endpoint accepts a receipt file ID and returns structured
// information that can pre-fill a new AP bill (vendor, invoice number,
// date, and line items). It does not create any records in the database.

// Dynamically load init and auth from either /app or root.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'member';

// Only finance admins and bookkeepers may build bills from receipts
if (!in_array($role, ['admin', 'bookkeeper'])) {
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions']);
    exit;
}

// Accept file_id from either GET query or JSON body
$fileId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['file_id'])) {
        $fileId = (int)$input['file_id'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : null;
}

if (!$fileId) {
    echo json_encode(['ok' => false, 'error' => 'Missing file_id']);
    exit;
}

try {
    // Verify receipt file belongs to this company and is parsed
    $stmt = $DB->prepare("SELECT * FROM receipt_file WHERE file_id = ? AND company_id = ? AND ocr_status = 'parsed' LIMIT 1");
    $stmt->execute([$fileId, $companyId]);
    $fileRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fileRow) {
        echo json_encode(['ok' => false, 'error' => 'Receipt not found or not parsed']);
        exit;
    }
    // Fetch OCR data
    $stmt = $DB->prepare("SELECT * FROM receipt_ocr WHERE file_id = ? LIMIT 1");
    $stmt->execute([$fileId]);
    $ocr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ocr) {
        echo json_encode(['ok' => false, 'error' => 'OCR data not available']);
        exit;
    }
    // Decode line items JSON
    $linesData = [];
    if (!empty($ocr['line_items_json'])) {
        $decoded = json_decode($ocr['line_items_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $li) {
                $desc  = isset($li['description']) ? trim($li['description']) : '';
                $qty   = isset($li['qty']) && is_numeric($li['qty']) ? (float)$li['qty'] : 1.0;
                $unit  = isset($li['unit']) ? trim($li['unit']) : 'ea';
                $price = isset($li['unit_price']) && is_numeric($li['unit_price']) ? (float)$li['unit_price'] : 0.0;
                $taxRate = isset($li['tax_rate']) && is_numeric($li['tax_rate']) ? (float)$li['tax_rate'] : 15.0;
                $linesData[] = [
                    'description' => $desc,
                    'qty'         => $qty,
                    'unit'        => $unit,
                    'unit_price'  => $price,
                    'tax_rate'    => $taxRate
                ];
            }
        }
    }
    // If no lines found, create one line item from subtotal
    if (empty($linesData)) {
        $sub = isset($ocr['subtotal']) ? (float)$ocr['subtotal'] : 0.0;
        $tax = isset($ocr['tax']) ? (float)$ocr['tax'] : 0.0;
        $price = $sub;
        $taxRate = ($sub > 0) ? ($tax / $sub * 100.0) : 15.0;
        $linesData[] = [
            'description' => 'Expense',
            'qty'         => 1.0,
            'unit'        => 'ea',
            'unit_price'  => $price,
            'tax_rate'    => $taxRate
        ];
    }
    // Determine supplier by exact match on vendor_name (case insensitive)
    $supplierId = null;
    $vendorName = $ocr['vendor_name'] ?? '';
    if ($vendorName) {
        // Try exact match
        $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$companyId, $vendorName]);
        $supp = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supp) {
            $supplierId = (int)$supp['id'];
        }
    }
    // Build response
    $invoiceNumber = $ocr['invoice_number'] ?? '';
    $invoiceDate   = $ocr['invoice_date'] ?? date('Y-m-d');
    $currency      = $ocr['currency'] ?? 'ZAR';
    // Compute subtotals
    $subtotal = 0.0;
    $taxTotal = 0.0;
    $total    = 0.0;
    foreach ($linesData as $l) {
        $lineTotal = $l['qty'] * $l['unit_price'];
        $lineTax   = $lineTotal * ($l['tax_rate'] / 100.0);
        $subtotal += $lineTotal;
        $taxTotal += $lineTax;
        $total    += $lineTotal + $lineTax;
    }
    echo json_encode([
        'ok'   => true,
        'data' => [
            'supplier_id'   => $supplierId,
            'supplier_name' => $vendorName,
            'invoice_number' => $invoiceNumber,
            'invoice_date'   => $invoiceDate,
            'currency'       => $currency,
            'subtotal'       => round($subtotal, 2),
            'tax'            => round($taxTotal, 2),
            'total'          => round($total, 2),
            'lines'          => $linesData,
            'file_id'        => $fileId
        ]
    ]);
} catch (Exception $e) {
    error_log('AP from receipt error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to build bill from receipt'
    ]);
}
