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

    // Calculate VAT breakdown
    $vatData = VatCalculator::calculate(
        $DB, $companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode
    );

    // Add change-in-use adjustments (Box 4 output, Box 6 input)
    // These come from VAT adjustment journals posted via vat_adjust_post.php
    $vatCtrlCode = $accounts->code('finance_vat_control_account_id');

    $stmt = $DB->prepare(
        "SELECT SUM(jl.debit) AS adj_debit, SUM(jl.credit) AS adj_credit
         FROM journal_lines jl
         JOIN journal_entries je ON jl.journal_id = je.id
         WHERE je.company_id = ? AND je.status = 'posted'
           AND je.entry_date BETWEEN ? AND ?
           AND je.module = 'vat_adjust'
           AND jl.account_code = ?"
    );
    $stmt->execute([$companyId, $period['period_start'], $period['period_end'], $vatCtrlCode]);
    $adj = $stmt->fetch(PDO::FETCH_ASSOC);
    $adjDebit  = (float)($adj['adj_debit'] ?? 0);
    $adjCredit = (float)($adj['adj_credit'] ?? 0);

    // Output adjustments increase output tax (credit to control = output increase)
    // Input adjustments increase input tax (debit to control = input increase)
    $vatData['change_in_use_output_cents'] = (int)round($adjCredit * 100);
    $vatData['change_in_use_input_cents']  = (int)round($adjDebit * 100);

    echo json_encode(['ok' => true, 'data' => $vatData]);

} catch (Exception $e) {
    error_log("VAT201 data error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
