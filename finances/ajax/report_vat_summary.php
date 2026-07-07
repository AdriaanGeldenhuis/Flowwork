<?php

require_once __DIR__ . '/../lib/http.php';
require_method('GET');
// /finances/ajax/report_vat_summary.php
// Generate a VAT summary report for a given date range grouped by month.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../permissions.php';
requireRoles(['admin', 'bookkeeper', 'viewer']);
require_once __DIR__ . '/../../finances/lib/AccountsMap.php';
require_once __DIR__ . '/report_helpers.php';

header('Content-Type: application/json');

$companyId = (int)($_SESSION['company_id'] ?? 0);
$userId    = (int)($_SESSION['user_id'] ?? 0);
if (!$companyId) { json_error('Not authorised', 403); }
$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate   = $_GET['end_date'] ?? date('Y-m-d');

// Validate dates strictly
$dtStart = DateTime::createFromFormat('Y-m-d', $startDate);
$dtEnd   = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$dtStart || $dtStart->format('Y-m-d') !== $startDate) {
    json_error('Invalid start_date');
}
if (!$dtEnd || $dtEnd->format('Y-m-d') !== $endDate) {
    json_error('Invalid end_date');
}

try {
    // Validate range
    if ($dtEnd < $dtStart) {
        throw new Exception('End date must be on or after start date');
    }
    // Resolve VAT account codes (settings override; fall back to the seeded
    // SARS chart defaults, consistent with the other VAT endpoints)
    $accountsMap = new AccountsMap($DB, $companyId);
    $vatOutputCode = $accountsMap->get('finance_vat_output_account_id', '2110');
    $vatInputCode  = $accountsMap->get('finance_vat_input_account_id', '2120');
    if (!$vatOutputCode) {
        throw new Exception('VAT Output account not configured');
    }
    if (!$vatInputCode) {
        throw new Exception('VAT Input account not configured');
    }
    // Build query
    $sql = "SELECT DATE_FORMAT(je.entry_date, '%Y-%m') AS period,
            SUM(CASE WHEN jl.account_code = ? THEN (jl.credit - jl.debit) ELSE 0 END) AS output_vat,
            SUM(CASE WHEN jl.account_code = ? THEN (jl.debit - jl.credit) ELSE 0 END) AS input_vat
        FROM journal_lines jl
        JOIN journal_entries je ON jl.journal_id = je.id
        WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
          AND COALESCE(je.module,'') NOT IN ('vat_settle','bad_debt','vat_adjust')
        GROUP BY period
        ORDER BY period";
    $params = [$vatOutputCode, $vatInputCode, $companyId, $startDate, $endDate];
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
    $meta = getReportMeta($DB, $companyId, $userId);
    echo json_encode(['ok' => true, 'data' => [
        'report_meta' => $meta,
        'vat_number'  => $meta['vat_number'],
        'periods'     => $data,
        'totals'      => $totals,
    ]]);
} catch (Exception $e) {
    error_log('VAT summary report error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to generate VAT summary']);
}