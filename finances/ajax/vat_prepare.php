<?php
// /finances/ajax/vat_prepare.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/VatCalculator.php';
require_once __DIR__ . '/../lib/Csrf.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

Csrf::validate();
requireRoles(['admin', 'bookkeeper']);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$periodId = $input['period_id'] ?? null;

if (!$periodId) {
    echo json_encode(['ok' => false, 'error' => 'Period ID required']);
    exit;
}

try {
    // Get period
    $stmt = $DB->prepare(
        "SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        throw new Exception('VAT period not found');
    }
    // Only allow preparing when the period is still open
    if ($period['status'] !== 'open') {
        throw new Exception('Period is already ' . $period['status']);
    }

    // Resolve VAT account codes via AccountsMap
    $accounts = new AccountsMap($DB, $companyId);
    $vatOutputCode = $accounts->code('finance_vat_output_account_id');
    $vatInputCode  = $accounts->code('finance_vat_input_account_id');

    if (!$vatOutputCode || !$vatInputCode) {
        throw new Exception('Please configure VAT accounts in Finance Settings');
    }

    // Full VAT201 box set via the single shared computation (includes
    // Box 4/6 adjustments and the Box 5/9/10 arithmetic).
    $vatData = VatCalculator::vat201Boxes(
        $DB, $companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode,
        VatCalculator::companyBasis($DB, (int)$companyId)
    );

    // Fetch company details for VAT201 header
    $stmt = $DB->prepare("SELECT name, vat_number, reg_number FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'data' => array_merge($vatData, [
            'period_id' => $period['id'],
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'status' => $period['status'],
            'company_name' => $company['name'] ?? '',
            'vat_number' => $company['vat_number'] ?? '',
            'reg_number' => $company['reg_number'] ?? '',
            'tax_period' => date('M Y', strtotime($period['period_start'])) . ' - ' . date('M Y', strtotime($period['period_end'])),
        ])
    ]);

} catch (Exception $e) {
    error_log("VAT prepare error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to prepare VAT return'
    ]);
}