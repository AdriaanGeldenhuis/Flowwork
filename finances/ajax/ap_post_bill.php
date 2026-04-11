<?php
// /finances/ajax/ap_post_bill.php
// Posts an existing AP bill to the general ledger using the PostingService.
// Only finance users (admin or bookkeeper) are permitted to perform this action.

// Dynamically require init and auth. Compute project root two levels above.
$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
}
require_once __DIR__ . '/../lib/PostingService.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/Csrf.php';
require_once __DIR__ . '/../permissions.php';

require_method('POST');
Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

header('Content-Type: application/json');

// Company and user context from session
$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId || !$userId) {
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit;
}

// Decode the JSON payload to obtain the bill ID
$input  = json_decode(file_get_contents('php://input'), true);
$billId = isset($input['bill_id']) ? (int)$input['bill_id'] : 0;

if (!$billId) {
    echo json_encode(['ok' => false, 'error' => 'Missing bill_id']);
    exit;
}

try {
    // SARS compliance: Check vendor VAT number before posting
    $sarsWarnings = [];
    $stmt = $DB->prepare(
        "SELECT b.supplier_id, b.tax AS bill_tax, s.name AS supplier_name, s.vat_no
         FROM ap_bills b
         LEFT JOIN crm_accounts s ON s.id = b.supplier_id
         WHERE b.id = ? AND b.company_id = ?"
    );
    $stmt->execute([$billId, $companyId]);
    $billInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($billInfo) {
        $billTax = (float)($billInfo['bill_tax'] ?? 0);
        if ($billTax > 0 && empty($billInfo['vat_no'])) {
            $sarsWarnings[] = 'Supplier "' . ($billInfo['supplier_name'] ?? 'Unknown') . '" has no VAT number on file. Input VAT may not be claimable (SARS requirement for input tax deduction).';
        }
    }

    // Post the bill using the central PostingService
    $posting = new PostingService($DB, $companyId, $userId);
    $posting->postApBill($billId);
    // Retrieve the journal ID that was created
    $stmt = $DB->prepare("SELECT journal_id FROM ap_bills WHERE id = ? AND company_id = ?");
    $stmt->execute([$billId, $companyId]);
    $journalId = $stmt->fetchColumn();
    $result = ['ok' => true, 'journal_id' => $journalId];
    if (!empty($sarsWarnings)) {
        $result['sars_warnings'] = $sarsWarnings;
    }
    echo json_encode($result);
} catch (Exception $e) {
    // Log the error and return a generic message
    error_log('AP bill post error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to post bill']);
}