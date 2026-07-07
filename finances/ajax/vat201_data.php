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
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2110');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2120');

    // Calculate VAT breakdown
    $vatData = VatCalculator::calculate(
        $DB, $companyId,
        $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode
    );

    // Add change-in-use / manual VAT adjustments (VAT201 field 12 output,
    // field 16 input). vat_adjust_post.php posts adjustment journals
    // (module='vat_adjust') with the adjustment on the VAT output/input
    // accounts themselves (output increase = credit to VAT Output, input
    // increase = debit to VAT Input) and only the balancing contra on the
    // VAT control account, so read the output/input lines back directly.
    $stmt = $DB->prepare(
        "SELECT
            SUM(CASE WHEN jl.account_code = ? THEN jl.credit - jl.debit ELSE 0 END) AS output_adj,
            SUM(CASE WHEN jl.account_code = ? THEN jl.debit - jl.credit ELSE 0 END) AS input_adj
         FROM journal_lines jl
         JOIN journal_entries je ON jl.journal_id = je.id
         WHERE je.company_id = ? AND je.status = 'posted'
           AND je.entry_date BETWEEN ? AND ?
           AND je.module = 'vat_adjust'
           AND jl.account_code IN (?, ?)"
    );
    $stmt->execute([
        $vatOutputCode, $vatInputCode,
        $companyId, $period['period_start'], $period['period_end'],
        $vatOutputCode, $vatInputCode
    ]);
    $adj = $stmt->fetch(PDO::FETCH_ASSOC);
    $outputAdjCents = (int)round(((float)($adj['output_adj'] ?? 0)) * 100);
    $inputAdjCents  = (int)round(((float)($adj['input_adj'] ?? 0)) * 100);

    $vatData['change_in_use_output_cents'] = $outputAdjCents;
    $vatData['change_in_use_input_cents']  = $inputAdjCents;

    // The adjustment journals post to the VAT output/input accounts, so
    // VatCalculator already folds them into total_output_vat_cents,
    // total_input_vat_cents and net_vat_cents. Carve the adjustments out of
    // the per-category figures so the VAT201 fields sum to the totals
    // without double counting (field 4 + field 12 = field 13;
    // fields 14 + 15 + 16 = field 19).
    $vatData['output_standard_vat_cents'] -= $outputAdjCents;
    $vatData['input_other_cents']         -= $inputAdjCents;

    echo json_encode(['ok' => true, 'data' => $vatData]);

} catch (Exception $e) {
    error_log("VAT201 data error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
