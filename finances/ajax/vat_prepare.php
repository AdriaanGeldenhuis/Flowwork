<?php
// /finances/ajax/vat_prepare.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
// Include AccountsMap to resolve VAT account codes
require_once __DIR__ . '/../lib/AccountsMap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$periodId = $input['period_id'] ?? null;

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
    // Only allow preparing when the period is still open
    if ($period['status'] !== 'open') {
        throw new Exception('Period is already ' . $period['status']);
    }

    // Resolve VAT account codes via AccountsMap; fallback codes for SA VAT
    $accounts = new AccountsMap($DB, $companyId);
    // Get raw setting values (could be id or code)
    $stmt = $DB->prepare(
        "SELECT setting_key, setting_value FROM company_settings
         WHERE company_id = ?
         AND setting_key IN ('finance_vat_output_account_id', 'finance_vat_input_account_id')"
    );
    $stmt->execute([$companyId]);
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $rawOutput = $settings['finance_vat_output_account_id'] ?? null;
    $rawInput  = $settings['finance_vat_input_account_id'] ?? null;
    // Determine account codes (e.g. '2120' and '2130')
    $vatOutputCode = $accounts->get('finance_vat_output_account_id', '2120');
    $vatInputCode  = $accounts->get('finance_vat_input_account_id', '2130');
    // Determine account ids if numeric
    $vatOutputId = (is_numeric($rawOutput) ? (int)$rawOutput : null);
    $vatInputId  = (is_numeric($rawInput) ? (int)$rawInput : null);

    if (!$vatOutputCode || !$vatInputCode) {
        throw new Exception('Please configure VAT accounts in Finance Settings');
    }

    // Calculate Output VAT (credit minus debit) over the period. We support both
    // new decimal columns (debit/credit) and legacy cent columns (debit_cents/credit_cents).
    $stmt = $DB->prepare(
        "SELECT
            COALESCE(SUM(
                COALESCE(jl.credit, jl.credit_cents/100.0) - COALESCE(jl.debit, jl.debit_cents/100.0)
            ), 0) AS total_output
         FROM journal_lines jl
         JOIN journal_entries je ON jl.journal_id = je.id
         WHERE je.company_id = ?
           AND je.entry_date BETWEEN ? AND ?
           AND (
                jl.account_code = ?
             OR (? IS NOT NULL AND jl.account_id = ?)
           )
    "
    );
    $stmt->execute([
        $companyId,
        $period['period_start'],
        $period['period_end'],
        $vatOutputCode,
        $vatOutputId,
        $vatOutputId
    ]);
    $outputVat = (float)$stmt->fetchColumn();

    // Calculate Input VAT (debit minus credit) over the period
    $stmt = $DB->prepare(
        "SELECT
            COALESCE(SUM(
                COALESCE(jl.debit, jl.debit_cents/100.0) - COALESCE(jl.credit, jl.credit_cents/100.0)
            ), 0) AS total_input
         FROM journal_lines jl
         JOIN journal_entries je ON jl.journal_id = je.id
         WHERE je.company_id = ?
           AND je.entry_date BETWEEN ? AND ?
           AND (
                jl.account_code = ?
             OR (? IS NOT NULL AND jl.account_id = ?)
           )
    "
    );
    $stmt->execute([
        $companyId,
        $period['period_start'],
        $period['period_end'],
        $vatInputCode,
        $vatInputId,
        $vatInputId
    ]);
    $inputVat = (float)$stmt->fetchColumn();

    // Convert to cents for consistent client representation
    $outputVatCents = (int)round($outputVat * 100);
    $inputVatCents  = (int)round($inputVat * 100);
    $netVatCents    = $outputVatCents - $inputVatCents;

    // Calculate base amounts (reverse engineer from 15% VAT). Only
    // standard rated supplies are considered here; zero/exempt remain zero.
    $outputStandardBaseCents = ($outputVat > 0.0) ? (int)round(($outputVat / 0.15) * 100) : 0;
    $outputStandardVatCents  = $outputVatCents;

    echo json_encode([
        'ok' => true,
        'data' => [
            'period_id' => $period['id'],
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'status' => $period['status'],
            'output_standard_base_cents' => $outputStandardBaseCents,
            'output_standard_vat_cents' => $outputStandardVatCents,
            'output_zero_base_cents' => 0,
            'output_exempt_base_cents' => 0,
            'total_output_vat_cents' => $outputVatCents,
            'input_capital_cents' => 0,
            'input_other_cents' => $inputVatCents,
            'total_input_vat_cents' => $inputVatCents,
            'net_vat_cents' => $netVatCents
        ]
    ]);

} catch (Exception $e) {
    error_log("VAT prepare error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}