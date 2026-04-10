<?php
// /finances/ajax/tax_invoice_validate.php
//
// Validates an invoice against SARS Section 20 tax invoice requirements.
// Returns a list of compliance issues (errors and warnings).

$__fin_root = realpath(__DIR__ . '/../../');
if ($__fin_root !== false && file_exists($__fin_root . '/app/init.php')) {
    require_once $__fin_root . '/app/init.php';
    require_once $__fin_root . '/app/auth_gate.php';
    $permPath = $__fin_root . '/app/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
} else {
    require_once $__fin_root . '/init.php';
    require_once $__fin_root . '/auth_gate.php';
    $permPath = $__fin_root . '/finances/permissions.php';
    if (file_exists($permPath)) require_once $permPath;
}

require_once __DIR__ . '/../lib/TaxInvoiceValidator.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$invoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : 0;

if ($invoiceId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invoice ID required']);
    exit;
}

try {
    $validator = new TaxInvoiceValidator($DB, $companyId);
    $issues = $validator->validate($invoiceId);
    $compliant = true;
    foreach ($issues as $issue) {
        if ($issue['severity'] === 'error') {
            $compliant = false;
            break;
        }
    }

    echo json_encode([
        'ok' => true,
        'compliant' => $compliant,
        'issues' => $issues,
        'issue_count' => count($issues)
    ]);
} catch (Exception $e) {
    error_log('Tax invoice validation error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
