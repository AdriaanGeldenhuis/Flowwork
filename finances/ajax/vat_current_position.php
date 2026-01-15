<?php
// /finances/ajax/vat_current_position.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
// Include AccountsMap for resolving VAT account codes
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];

try {
    // Resolve VAT account codes via AccountsMap
    $accounts = new AccountsMap($DB, $companyId);
    // Fetch raw setting values
    $stmt = $DB->prepare(
        "SELECT setting_key, setting_value FROM company_settings
         WHERE company_id = ?
           AND setting_key IN ('finance_vat_output_account_id', 'finance_vat_input_account_id')"
    );
    $stmt->execute([$companyId]);
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $rawOutput = $settings['finance_vat_output_account_id'] ?? null;
    $rawInput  = $settings['finance_vat_input_account_id'] ?? null;
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2130');
    $vatOutputId = (is_numeric($rawOutput) ? (int)$rawOutput : null);
    $vatInputId  = (is_numeric($rawInput) ? (int)$rawInput : null);

    // Get unfiled VAT transactions. Use COALESCE to support both decimal and cent columns.
    $stmt = $DB->prepare(
        "SELECT 
            COALESCE(SUM(
                CASE WHEN (jl.account_code = ? OR jl.account_id = ?) THEN
                    COALESCE(jl.credit, jl.credit_cents/100.0) - COALESCE(jl.debit, jl.debit_cents/100.0)
                ELSE 0 END
            ), 0) AS output_vat,
            COALESCE(SUM(
                CASE WHEN (jl.account_code = ? OR jl.account_id = ?) THEN
                    COALESCE(jl.debit, jl.debit_cents/100.0) - COALESCE(jl.credit, jl.credit_cents/100.0)
                ELSE 0 END
            ), 0) AS input_vat,
            COUNT(DISTINCT CASE WHEN (jl.account_code = ? OR jl.account_id = ?) THEN jl.journal_id END) AS output_count,
            COUNT(DISTINCT CASE WHEN (jl.account_code = ? OR jl.account_id = ?) THEN jl.journal_id END) AS input_count,
            MIN(je.entry_date) AS earliest_date,
            MAX(je.entry_date) AS latest_date
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_id = je.id
        LEFT JOIN gl_vat_periods vp ON je.entry_date BETWEEN vp.period_start AND vp.period_end
            AND vp.company_id = je.company_id
            AND vp.status != 'open'
        WHERE je.company_id = ?
          AND (
                jl.account_code IN (?, ?) 
             OR jl.account_id IN (?, ?)
          )
          AND vp.id IS NULL
    "
    );
    $stmt->execute([
        // Output VAT: match output code/id
        $vatOutputCode, $vatOutputId,
        // Input VAT: match input code/id
        $vatInputCode, $vatInputId,
        // Output count: code/id
        $vatOutputCode, $vatOutputId,
        // Input count: code/id
        $vatInputCode, $vatInputId,
        // Company ID
        $companyId,
        // Filter codes list (for both output and input)
        $vatOutputCode, $vatInputCode,
        // Filter ids list (for both output and input)
        $vatOutputId, $vatInputId
    ]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    // Convert to cents
    $outputVatCents = (int)round(((float)$data['output_vat']) * 100);
    $inputVatCents  = (int)round(((float)$data['input_vat']) * 100);
    $netVatCents    = $outputVatCents - $inputVatCents;

    echo json_encode([
        'ok' => true,
        'data' => [
            'output_vat_cents' => $outputVatCents,
            'input_vat_cents' => $inputVatCents,
            'net_vat_cents' => $netVatCents,
            'output_count' => (int)$data['output_count'],
            'input_count' => (int)$data['input_count'],
            'earliest_date' => $data['earliest_date'],
            'latest_date' => $data['latest_date']
        ]
    ]);

} catch (Exception $e) {
    error_log("VAT current position error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load VAT position'
    ]);
}