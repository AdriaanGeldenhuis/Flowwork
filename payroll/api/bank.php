<?php
// /payroll/api/bank.php — the bank payments file for a finalised run.
//   GET ?run=          → CSV: one row per person with net pay and bank details
//   GET ?run=&check=1  → JSON: who is in it, who is missing bank details, the total
// A generic CSV that online banking bulk-payment imports can map; the
// reference on each payment is the run name. It pays into the bank details
// as they were when the run was approved. Every download is recorded.
require_once __DIR__ . '/_bootstrap.php';

$C = $PAY_COMPANY_ID;
$d = PayRuns::bankRows($DB, $C, pay_int($_GET['run'] ?? 0));
$total = array_sum(array_map(fn($r) => (int)$r['bank_amount_cents'], $d['rows']));
if (!empty($_GET['check'])) pay_json(['people' => count($d['rows']), 'missing' => $d['missing'], 'changed' => $d['changed'], 'total' => $total]);
if (!$d['rows']) pay_error('Nobody in this run has bank details yet.', 409);

pay_rate_limit('pay-bank', 30, 600);
$run = $d['run'];
$ref = mb_substr(preg_replace('/[^A-Za-z0-9 ]+/', ' ', 'Salary ' . $run['name']), 0, 20);
$rows = array_map(fn($r) => [
    trim($r['first_name'] . ' ' . $r['last_name']), $r['employee_no'], $r['bank_name'], $r['branch_code'], $r['bank_account_no'],
    PayKit::ACCOUNT_TYPES[$r['bank_account_type'] ?? ''] ?? '', number_format((int)$r['bank_amount_cents'] / 100, 2, '.', ''), trim($ref),
], $d['rows']);
try {
    $DB->prepare("INSERT INTO bank_file_exports (company_id, run_id, format, file_path, total_amount_cents, employee_count, created_by, created_at) VALUES (?, ?, 'csv', ?, ?, ?, ?, NOW())")
       ->execute([$C, $run['id'], 'download', $total, count($rows), $PAY_USER_ID]);
} catch (PDOException $e) {
    error_log('[payroll] bank file log: ' . $e->getMessage());
}
PayKit::audit($DB, $C, $PAY_USER_ID, 'payrun_bank_export', array_filter(['run' => $run['run_number'] . ' · ' . $run['name'], 'people' => count($rows), 'total' => PayKit::money($total),
    'bank details changed since approval' => $d['changed'] ? implode(', ', $d['changed']) : null]), 'pay_run', (int)$run['id']);
header_remove('Content-Type');
PayKit::csv('Salaries-' . $run['run_number'] . '.csv', ['Name', 'Employee no', 'Bank', 'Branch code', 'Account number', 'Account type', 'Amount', 'Reference'], $rows);
