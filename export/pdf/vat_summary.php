<?php
// PDF VAT Summary Report
//
// Produces a plain text PDF detailing the company's output VAT, input VAT
// and net VAT due. Uses the AccountsMap helper to resolve the relevant
// account codes from company settings.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/lib/AccountsMap.php';

$companyId = $_SESSION['company_id'] ?? null;
if (!$companyId) {
    http_response_code(403);
    echo 'Not authenticated';
    exit;
}

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

$lines = [];
$lines[] = 'VAT Summary Report';
$lines[] = 'As of ' . (new DateTimeImmutable('today'))->format('Y-m-d');
$lines[] = '';
$lines[] = sprintf("%-20s %15s", 'Output VAT', number_format($outputVat, 2, '.', ''));
$lines[] = sprintf("%-20s %15s", 'Input VAT', number_format($inputVat, 2, '.', ''));
$lines[] = str_repeat('-', 40);
$lines[] = sprintf("%-20s %15s", 'Net VAT Due', number_format($netVat, 2, '.', ''));

function pdf_escape($str)
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $str);
}

function generate_simple_pdf_content(array $lines): string
{
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $offsets[] = strlen($pdf);
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>\nendobj\n";
    $offsets[] = strlen($pdf);
    $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>\nendobj\n";
    // Content
    $yPos = 812;
    $content = "BT\n/F1 12 Tf\n";
    foreach ($lines as $line) {
        $content .= sprintf("36 %.2f Td (%s) Tj\n", $yPos, pdf_escape($line));
        $yPos -= 14;
    }
    $content .= "ET";
    $offsets[] = strlen($pdf);
    $pdf .= "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n";
    $pdf .= $content . "\nendstream\nendobj\n";
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    return $pdf;
}

$pdfContent = generate_simple_pdf_content($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="vat_summary.pdf"');
echo $pdfContent;
exit;