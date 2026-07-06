<?php
// finances/export/vat_audit_file.php
//
// SARS VAT audit file export: one CSV row per contributing transaction line
// for a VAT period (GET period_id = gl_vat_periods.id), honouring the
// period's accounting basis. The trailing TOTAL rows foot to the VAT201
// Box 5 / Box 9 produced by VatCalculator::vat201Boxes for the same period.
// All heavy lifting lives in finances/lib/VatAuditFile.php so the test suite
// asserts the same rows this endpoint streams.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
require_once __DIR__ . '/../lib/AccountsMap.php';
require_once __DIR__ . '/../lib/VatCalculator.php';
require_once __DIR__ . '/../lib/VatAuditFile.php';

requireRoles(['admin', 'bookkeeper', 'viewer']);

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
if ($periodId <= 0) {
    http_response_code(400);
    echo 'period_id is required';
    exit;
}

// Company-scoped period lookup.
$stmt = $DB->prepare("SELECT * FROM gl_vat_periods WHERE id = ? AND company_id = ? LIMIT 1");
$stmt->execute([$periodId, $companyId]);
$period = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$period) {
    http_response_code(404);
    echo 'VAT period not found';
    exit;
}

$basis = VatCalculator::periodBasis($DB, (int)$companyId, $period);

$accountsMap = new AccountsMap($DB, (int)$companyId);
$vatOutCode = $accountsMap->code('finance_vat_output_account_id');
$vatInCode  = $accountsMap->code('finance_vat_input_account_id');

$rows = VatAuditFile::rows(
    $DB, (int)$companyId,
    $period['period_start'], $period['period_end'],
    $vatOutCode, $vatInCode, $basis
);
$totals = VatAuditFile::totals($rows);

$filename = 'vat_audit_' . $period['period_start'] . '_' . $period['period_end'] . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$fmt = fn(?int $cents): string => $cents === null ? '' : number_format($cents / 100, 2, '.', '');

$out = fopen('php://output', 'w');
fputcsv($out, ['VAT audit file', $period['period_start'] . ' to ' . $period['period_end'],
    'Basis: ' . ($basis === 'payments' ? 'payments (cash)' : 'invoice (accrual)')], ',', '"', '\\');

if ($basis === 'payments') {
    fputcsv($out, ['entry_date', 'journal_id', 'reference', 'source_type', 'description',
        'tax_code', 'is_capital_goods', 'allocation_amount', 'apportioned_net', 'apportioned_vat', 'section'], ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['entry_date'],
            $r['journal_id'],
            $r['reference'],
            $r['source_type'],
            $r['description'],
            $r['tax_code'],
            $r['is_capital_goods'],
            $r['allocation'] !== null ? number_format((float)$r['allocation'], 2, '.', '') : '',
            $fmt($r['net_cents']),
            $fmt($r['vat_cents']),
            $r['section'],
        ], ',', '"', '\\');
    }
} else {
    fputcsv($out, ['entry_date', 'journal_id', 'reference', 'source_type', 'description',
        'account_code', 'tax_code', 'rate_percent', 'is_capital_goods', 'debit', 'credit', 'net_effect'], ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['entry_date'],
            $r['journal_id'],
            $r['reference'],
            $r['source_type'],
            $r['description'],
            $r['account_code'],
            $r['tax_code'],
            $r['rate_percent'] !== null ? number_format((float)$r['rate_percent'], 2, '.', '') : '',
            $r['is_capital_goods'],
            number_format((float)$r['debit'], 2, '.', ''),
            number_format((float)$r['credit'], 2, '.', ''),
            $fmt($r['net_cents']),
        ], ',', '"', '\\');
    }
}

fputcsv($out, [], ',', '"', '\\');
fputcsv($out, ['TOTAL output VAT (foots to VAT201 Box 5)', $fmt($totals['output_vat_cents'])], ',', '"', '\\');
fputcsv($out, ['TOTAL input VAT (foots to VAT201 Box 9)', $fmt($totals['input_vat_cents'])], ',', '"', '\\');
fclose($out);
exit;
