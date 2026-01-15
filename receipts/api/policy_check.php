<?php
// Policy check for a draft bill. Evaluates company settings to determine blocks and warnings.

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
$header = isset($input['header']) ? $input['header'] : [];
$lines = isset($input['lines']) ? $input['lines'] : [];

// Fetch receipt settings
$stmt = $DB->prepare("SELECT * FROM receipt_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$settings = $stmt->fetch();

$blocks = [];
$warnings = [];

// Rule: PO required over threshold
if ($settings && isset($settings['require_po_over_amount']) && $settings['require_po_over_amount'] > 0) {
    $threshold = (float)$settings['require_po_over_amount'];
    $total = isset($header['total']) ? (float)$header['total'] : 0;
    if ($total >= $threshold) {
        // If user has not linked a PO (not supported yet), block
        $blocks[] = 'Purchase Order required for total over threshold.';
    }
}

// Rule: Variance tolerance between header subtotal and sum of lines
if ($settings && isset($settings['variance_tolerance_pct']) && !empty($lines)) {
    $tolerancePct = (float)$settings['variance_tolerance_pct'];
    if ($tolerancePct > 0) {
        $subtotalHeader = isset($header['subtotal']) ? (float)$header['subtotal'] : 0;
        // Compute sum of line nets (qty * unit_price)
        $sumLines = 0;
        foreach ($lines as $ln) {
            $qty = isset($ln['qty']) ? (float)$ln['qty'] : 0;
            $price = isset($ln['unit_price']) ? (float)$ln['unit_price'] : 0;
            $sumLines += $qty * $price;
        }
        if ($subtotalHeader > 0) {
            $diff = abs($sumLines - $subtotalHeader);
            $allowed = $subtotalHeader * ($tolerancePct / 100.0);
            if ($diff > $allowed) {
                $warnings[] = 'Line items total differs from header subtotal by more than the allowed variance (' . number_format($tolerancePct, 2) . '%).';
            }
        }
    }
}

// Rule: VAT number required
if ($settings && isset($settings['require_vat_number']) && $settings['require_vat_number']) {
    $tax = isset($header['tax']) ? (float)$header['tax'] : 0;
    if ($tax > 0) {
        // Try to fetch vendor VAT number from receipt_ocr
        $supplierId = isset($header['supplier_id']) ? (int)$header['supplier_id'] : 0;
        if ($supplierId > 0) {
            // Look up supplier VAT in CRM accounts
            $stmt = $DB->prepare("SELECT vat_number FROM crm_accounts WHERE id = ? AND company_id = ?");
            $stmt->execute([$supplierId, $companyId]);
            $acc = $stmt->fetch();
            $vatNum = $acc && !empty($acc['vat_number']) ? $acc['vat_number'] : null;
            if (!$vatNum) {
                $warnings[] = 'VAT number missing for supplier.';
            }
        }
    }
}

// Return results
echo json_encode([
    'ok' => true,
    'blocks' => $blocks,
    'warnings' => $warnings
]);