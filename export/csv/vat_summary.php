<?php
// CSV VAT Summary Report
//
// Outputs a CSV summarising output VAT, input VAT and net VAT due for the
// current company. VAT accounts are resolved using the AccountsMap helper,
// which reads company settings to determine the VAT output and input account
// codes. The report aggregates journal line debits and credits across all
// entries regardless of date.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/lib/AccountsMap.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vat_summary.csv"');

// Resolve VAT output and input account codes
$accountsMap = new AccountsMap($DB, (int)$companyId);
$vatOutCode = $accountsMap->get('finance_vat_output_account_id', '2120');
$vatInCode  = $accountsMap->get('finance_vat_input_account_id', '2130');

// Aggregate VAT balances
$stmt = $DB->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN ga.account_code = ? THEN (COALESCE(jl.credit, jl.credit_cents/100) - COALESCE(jl.debit, jl.debit_cents/100)) ELSE 0 END),0) AS output_vat,
        COALESCE(SUM(CASE WHEN ga.account_code = ? THEN (COALESCE(jl.debit, jl.debit_cents/100) - COALESCE(jl.credit, jl.credit_cents/100)) ELSE 0 END),0) AS input_vat
     FROM journal_lines jl
     JOIN journal_entries je ON je.id = jl.journal_id
     JOIN gl_accounts ga ON ga.company_id = je.company_id AND (ga.account_code = jl.account_code OR ga.account_id = jl.account_id)
     WHERE je.company_id = ?"
);
$stmt->execute([$vatOutCode, $vatInCode, $companyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$outputVat = (float)($row['output_vat'] ?? 0);
$inputVat  = (float)($row['input_vat'] ?? 0);
$netVat    = $outputVat - $inputVat;

$out = fopen('php://output', 'w');
fputcsv($out, ['Description', 'Amount']);
fputcsv($out, ['Output VAT', number_format($outputVat, 2, '.', '')]);
fputcsv($out, ['Input VAT', number_format($inputVat, 2, '.', '')]);
fputcsv($out, ['Net VAT Due', number_format($netVat, 2, '.', '')]);
fclose($out);
exit;