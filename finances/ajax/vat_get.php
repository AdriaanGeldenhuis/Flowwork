<?php
// /finances/ajax/vat_get.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/VatCalculator.php';

require_method('GET');

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$periodId = $_GET['period_id'] ?? null;

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

    // Resolve VAT account codes via AccountsMap
    $accounts = new AccountsMap($DB, $companyId);
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2130');

    if (!$vatOutputCode || !$vatInputCode) {
        throw new Exception('Please configure VAT accounts in Finance Settings');
    }

    // Calculate VAT breakdown using centralised helper
    $vatData = VatCalculator::calculate(
        $DB, $companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode
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
    error_log("VAT get error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load VAT period'
    ]);
}
