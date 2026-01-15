<?php
// /payroll/ajax/export_irp5.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

$companyId = $_SESSION['company_id'];
$taxYear = $_GET['tax_year'] ?? '';

if (!$taxYear || !preg_match('/^\d{4}$/', $taxYear)) {
    die('Invalid tax year');
}

try {
    // Tax year runs Mar 1 to Feb 28/29
    $periodStart = ($taxYear - 1) . '-03-01';
    $periodEnd = $taxYear . '-02-28';

    // Get all employees paid in this tax year
    $stmt = $DB->prepare("
        SELECT 
            e.id,
            e.employee_no,
            e.first_name,
            e.last_name,
            e.id_number,
            e.tax_number,
            SUM(pre.gross_cents) as total_gross,
            SUM(pre.taxable_income_cents) as total_taxable,
            SUM(pre.paye_cents) as total_paye,
            SUM(pre.uif_employee_cents) as total_uif,
            SUM(pre.reimbursements_cents) as total_reimbursements
        FROM employees e
        JOIN pay_run_employees pre ON pre.employee_id = e.id
        JOIN pay_runs pr ON pr.id = pre.run_id
        WHERE e.company_id = ?
        AND pr.pay_date >= ? AND pr.pay_date <= ?
        AND pr.status IN ('locked', 'posted')
        GROUP BY e.id
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([$companyId, $periodStart, $periodEnd]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV
    $filename = 'IRP5_' . $taxYear . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, [
        'Employee No',
        'First Name',
        'Last Name',
        'ID Number',
        'Tax Number',
        'Gross Income (cents)',
        'Taxable Income (cents)',
        'PAYE (cents)',
        'UIF (cents)',
        'Non-Taxable Income (cents)'
    ]);

    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['employee_no'],
            $emp['first_name'],
            $emp['last_name'],
            $emp['id_number'] ?? '',
            $emp['tax_number'] ?? '',
            $emp['total_gross'],
            $emp['total_taxable'],
            $emp['total_paye'],
            $emp['total_uif'],
            $emp['total_reimbursements']
        ]);
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    error_log("IRP5 export error: " . $e->getMessage());
    die('Export failed');
}