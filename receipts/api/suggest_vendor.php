<?php
// Suggest a supplier based on OCR vendor name.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Ensure user is authenticated
if (!isset($_SESSION['company_id']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = (int)$_SESSION['company_id'];

// Expect JSON POST body
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$fileId = isset($input['file_id']) ? (int)$input['file_id'] : 0;

if (!$fileId) {
    echo json_encode(['ok' => false, 'error' => 'Missing file_id']);
    exit;
}

// Fetch OCR vendor name for this file
$stmt = $DB->prepare(
    "SELECT ro.vendor_name
     FROM receipt_file rf
     LEFT JOIN receipt_ocr ro ON ro.file_id = rf.file_id
     WHERE rf.file_id = ? AND rf.company_id = ?"
);
$stmt->execute([$fileId, $companyId]);
$row = $stmt->fetch();

$vendorName = $row && $row['vendor_name'] ? trim($row['vendor_name']) : '';

$bestSupplier = null;
$alternatives = [];
$compliance = ['ok' => true, 'missing' => []];
// Confidence level for suggested supplier (0–1).  Default to 0 (unknown)
$supplierConfidence = 0.0;

if ($vendorName !== '') {
    // 1. Check for existing hint mapping
    $stmt = $DB->prepare(
        "SELECT hint_value, hits
         FROM ai_hints
         WHERE company_id = ? AND hint_type = 'vendor' AND hint_key = ?
         ORDER BY hits DESC LIMIT 1"
    );
    $stmt->execute([$companyId, strtolower($vendorName)]);
    $hint = $stmt->fetch();
    if ($hint) {
        // Attempt to fetch supplier by hint_value
        $stmt2 = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND id = ? LIMIT 1");
        $stmt2->execute([$companyId, (int)$hint['hint_value']]);
        $hintSupplier = $stmt2->fetch();
        if ($hintSupplier) {
            $bestSupplier = $hintSupplier;
            $supplierConfidence = 0.95;
            // Update hits and last_used_at for this hint
            $upd = $DB->prepare("UPDATE ai_hints SET hits = hits + 1, last_used_at = NOW() WHERE company_id = ? AND hint_type = 'vendor' AND hint_key = ?");
            $upd->execute([$companyId, strtolower($vendorName)]);
        }
    }
    // 2. If no hint used or supplier not found via hint, try exact match
    if (!$bestSupplier) {
        $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND name = ? LIMIT 1");
        $stmt->execute([$companyId, $vendorName]);
        $bestSupplier = $stmt->fetch();
        if ($bestSupplier) {
            $supplierConfidence = 0.90;
        }
    }
    // 3. If still no match, try partial matches
    if (!$bestSupplier) {
        $like = '%' . $vendorName . '%';
        $stmt = $DB->prepare("SELECT id, name FROM crm_accounts WHERE company_id = ? AND type = 'supplier' AND name LIKE ? ORDER BY name LIMIT 5");
        $stmt->execute([$companyId, $like]);
        $alternatives = $stmt->fetchAll();
        if (count($alternatives)) {
            // Choose the first as a guess
            $bestSupplier = $alternatives[0];
            $supplierConfidence = 0.60;
            // Remove it from alternatives list
            array_shift($alternatives);
        }
    }
    // 4. Determine compliance for bestSupplier if found
    if ($bestSupplier) {
        // Determine required compliance types for this company
        $stmt = $DB->prepare("SELECT id, name, days_valid FROM crm_compliance_types WHERE company_id = ? ORDER BY name");
        $stmt->execute([$companyId]);
        $types = $stmt->fetchAll();
        $missing = [];
        foreach ($types as $type) {
            // Look for most recent doc for this supplier and type
            $stmt2 = $DB->prepare(
                "SELECT expiry_date FROM crm_compliance_docs
                 WHERE company_id = ? AND account_id = ? AND compliance_type_id = ?
                 ORDER BY expiry_date DESC LIMIT 1"
            );
            $stmt2->execute([$companyId, $bestSupplier['id'], $type['id']]);
            $doc = $stmt2->fetch();
            if (!$doc) {
                $missing[] = $type['name'];
            } else {
                $exp = $doc['expiry_date'];
                if ($exp && strtotime($exp) < time()) {
                    $missing[] = $type['name'];
                }
            }
        }
        if (!empty($missing)) {
            $compliance['ok'] = false;
            $compliance['missing'] = $missing;
        }
    }
}

// Prepare output
// Prepare output including confidence value
$resp = [
    'ok' => true,
    'supplier' => $bestSupplier ? [
        'id' => (int)$bestSupplier['id'],
        'name' => $bestSupplier['name'],
        'confidence' => $supplierConfidence
    ] : null,
    'compliance' => $compliance,
    'alternatives' => []
];
// Return simplified alternatives list (ID and name)
if (!empty($alternatives)) {
    $alt = [];
    foreach ($alternatives as $sup) {
        $alt[] = ['id' => (int)$sup['id'], 'name' => $sup['name']];
    }
    $resp['alternatives'] = $alt;
}

echo json_encode($resp);