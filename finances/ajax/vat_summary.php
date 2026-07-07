<?php
// /finances/ajax/vat_summary.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
// Include AccountsMap to resolve VAT account codes
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

requireRoles(['admin', 'bookkeeper', 'viewer']);

$companyId = $_SESSION['company_id'];

try {
    // Resolve VAT account codes via AccountsMap
    $accounts = new AccountsMap($DB, $companyId);
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2110');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2120');

    // Compute balances for VAT accounts. Output VAT is credit minus debit.
    $outputVat = 0.0;
    $inputVat  = 0.0;
    if ($vatOutputCode) {
        $stmt = $DB->prepare(
            "SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND jl.account_code = ?"
        );
        $stmt->execute([$companyId, $vatOutputCode]);
        $outputVat = (float)$stmt->fetchColumn();
    }
    if ($vatInputCode) {
        // Input VAT is debit minus credit
        $stmt = $DB->prepare(
            "SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND jl.account_code = ?"
        );
        $stmt->execute([$companyId, $vatInputCode]);
        $inputVat = (float)$stmt->fetchColumn();
    }
    $outputVatCents = (int)round($outputVat * 100);
    $inputVatCents  = (int)round($inputVat * 100);
    $netVatCents    = $outputVatCents - $inputVatCents;

    echo json_encode([
        'ok' => true,
        'data' => [
            'output_vat_cents' => $outputVatCents,
            'input_vat_cents' => $inputVatCents,
            'net_vat_cents' => $netVatCents
        ]
    ]);

} catch (Exception $e) {
    error_log("VAT summary error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load VAT summary'
    ]);
}
