<?php
// /finances/ajax/vat_summary.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
// Include AccountsMap to resolve VAT account codes
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    // Resolve VAT account codes via AccountsMap
    $accounts = new AccountsMap($DB, $companyId);
    $stmt = $DB->prepare(
        "SELECT setting_key, setting_value FROM company_settings
         WHERE company_id = ? AND setting_key IN ('finance_vat_output_account_id', 'finance_vat_input_account_id')"
    );
    $stmt->execute([$companyId]);
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $rawOutput = $settings['finance_vat_output_account_id'] ?? null;
    $rawInput  = $settings['finance_vat_input_account_id'] ?? null;
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2130');
    $vatOutputId = (is_numeric($rawOutput) ? (int)$rawOutput : null);
    $vatInputId  = (is_numeric($rawInput) ? (int)$rawInput : null);

    // Compute balances for VAT accounts. We join journal_entries to enforce company scope
    // and use COALESCE to support both decimal and cent columns. Output VAT is credit minus debit.
    $outputVat = 0.0;
    $inputVat  = 0.0;
    if ($vatOutputCode) {
        $stmt = $DB->prepare(
            "SELECT COALESCE(SUM(
                COALESCE(jl.credit, jl.credit_cents/100.0) - COALESCE(jl.debit, jl.debit_cents/100.0)
            ), 0) AS balance
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ?
               AND (
                    jl.account_code = ?
                 OR (? IS NOT NULL AND jl.account_id = ?)
               )"
        );
        $stmt->execute([$companyId, $vatOutputCode, $vatOutputId, $vatOutputId]);
        $outputVat = (float)$stmt->fetchColumn();
    }
    if ($vatInputCode) {
        // Input VAT is debit minus credit
        $stmt = $DB->prepare(
            "SELECT COALESCE(SUM(
                COALESCE(jl.debit, jl.debit_cents/100.0) - COALESCE(jl.credit, jl.credit_cents/100.0)
            ), 0) AS balance
             FROM journal_lines jl
             JOIN journal_entries je ON jl.journal_id = je.id
             WHERE je.company_id = ?
               AND (
                    jl.account_code = ?
                 OR (? IS NOT NULL AND jl.account_id = ?)
               )"
        );
        $stmt->execute([$companyId, $vatInputCode, $vatInputId, $vatInputId]);
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