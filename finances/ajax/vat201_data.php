<?php
// /finances/ajax/vat201_data.php
//
// Returns VAT201 box data for a given VAT period. Uses VatCalculator
// and adds change-in-use adjustment fields (Box 4 / Box 6).

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

require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/VatCalculator.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

requireRoles(['admin', 'bookkeeper', 'viewer']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$periodId = $input['period_id'] ?? null;

if (!$periodId) {
    echo json_encode(['ok' => false, 'error' => 'Period ID required']);
    exit;
}

try {
    // Get period
    $stmt = $DB->prepare("SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ?");
    $stmt->execute([$periodId, $companyId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        throw new Exception('VAT period not found');
    }

    // Resolve VAT account codes
    $accounts = new AccountsMap($DB, $companyId);
    $vatOutputCode = $accounts->code('finance_vat_output_account_id');
    $vatInputCode  = $accounts->code('finance_vat_input_account_id');

    // Full VAT201 box set from the single shared computation. (The previous
    // implementation read the adjustment CONTRA leg off the control account
    // with inverted signs while the adjustment also stayed inside the
    // supplies totals — double-counted and mislabelled — and Box 5/9/10
    // were left to each consumer to assemble, mostly wrongly.)
    $vatData = VatCalculator::vat201Boxes(
        $DB, $companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode,
        VatCalculator::periodBasis($DB, (int)$companyId, $period)
    );

    echo json_encode(['ok' => true, 'data' => $vatData]);

} catch (Exception $e) {
    error_log("VAT201 data error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
