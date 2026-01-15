<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_vat_summary.php
// Generate a VAT summary report for a given date range grouped by month.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../finances/lib/AccountsMap.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

try {
    // Validate dates
    $start = new DateTime($startDate);
    $end   = new DateTime($endDate);
    if ($end < $start) {
        throw new Exception('End date must be on or after start date');
    }
    // Resolve VAT account codes
    $accountsMap = new \Finances\AccountsMap($DB, $companyId);
    $vatOutputId = $accountsMap->getAccountId('finance_vat_output_account_id');
    $vatInputId  = $accountsMap->getAccountId('finance_vat_input_account_id');
    $vatOutputCode = $accountsMap->getAccountCodeById($vatOutputId);
    $vatInputCode  = $accountsMap->getAccountCodeById($vatInputId);
    if (!$vatOutputCode && !$vatOutputId) {
        throw new Exception('VAT Output account not configured');
    }
    if (!$vatInputCode && !$vatInputId) {
        throw new Exception('VAT Input account not configured');
    }
    $conditionsOut = [];
    $paramsOut = [];
    if ($vatOutputCode) {
        $conditionsOut[] = 'jl.account_code = ?';
        $paramsOut[] = $vatOutputCode;
    }
    if ($vatOutputId) {
        $conditionsOut[] = 'jl.account_id = ?';
        $paramsOut[] = $vatOutputId;
    }
    $sqlOutCond = implode(' OR ', $conditionsOut);
    $conditionsIn = [];
    $paramsIn = [];
    if ($vatInputCode) {
        $conditionsIn[] = 'jl.account_code = ?';
        $paramsIn[] = $vatInputCode;
    }
    if ($vatInputId) {
        $conditionsIn[] = 'jl.account_id = ?';
        $paramsIn[] = $vatInputId;
    }
    $sqlInCond = implode(' OR ', $conditionsIn);
    // Build query
    $sql = "SELECT DATE_FORMAT(je.entry_date, '%Y-%m') AS period,\n            SUM(CASE WHEN $sqlOutCond THEN (jl.credit - jl.debit) ELSE 0 END) AS output_vat,\n            SUM(CASE WHEN $sqlInCond THEN (jl.debit - jl.credit) ELSE 0 END) AS input_vat\n        FROM journal_lines jl\n        JOIN journal_entries je ON jl.journal_id = je.id\n        WHERE je.company_id = ? AND je.entry_date BETWEEN ? AND ?\n        GROUP BY period\n        ORDER BY period";
    $params = array_merge($paramsOut, $paramsIn, [$companyId, $startDate, $endDate]);
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = [];
    $totalOut  = 0.0;
    $totalIn   = 0.0;
    $totalNet  = 0.0;
    foreach ($rows as $row) {
        $out  = floatval($row['output_vat']);
        $in   = floatval($row['input_vat']);
        $net  = $out - $in;
        $totalOut += $out;
        $totalIn  += $in;
        $totalNet += $net;
        $data[] = [
            'period' => $row['period'],
            'output_vat_cents' => intval(round($out * 100)),
            'input_vat_cents'  => intval(round($in * 100)),
            'net_vat_cents'    => intval(round($net * 100))
        ];
    }
    $totals = [
        'output_vat_cents' => intval(round($totalOut * 100)),
        'input_vat_cents'  => intval(round($totalIn * 100)),
        'net_vat_cents'    => intval(round($totalNet * 100))
    ];
    echo json_encode(['ok' => true, 'data' => ['periods' => $data, 'totals' => $totals]]);
} catch (Exception $e) {
    error_log('VAT summary report error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}