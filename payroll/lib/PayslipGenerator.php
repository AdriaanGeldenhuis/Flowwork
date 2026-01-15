<?php
// payroll/lib/PayslipGenerator.php
//
// Provides a helper function to generate simple HTML payslips for all employees
// in a payroll run. Each payslip will be stored under /storage/payroll/{company_id}/run_{run_id}/
// with a filename of payslip_{employee_id}.html. The relative path will be saved
// to the pay_run_employees.payslip_path column. This generator does not
// produce PDFs; it outputs basic HTML slips which can be viewed in the browser
// or converted to PDF by a later utility.

/**
 * Generate payslips for a payroll run.
 *
 * @param PDO $db Database connection
 * @param int $companyId Company ID
 * @param int $runId Pay run ID
 * @return int The number of payslips generated
 * @throws Exception if the run cannot be found
 */
function generatePayslips(PDO $db, int $companyId, int $runId): int
{
    // Fetch run details to obtain period dates
    $stmt = $db->prepare(
        "SELECT period_start, period_end, pay_date FROM pay_runs WHERE id = ? AND company_id = ?"
    );
    $stmt->execute([$runId, $companyId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        throw new Exception('Pay run not found');
    }
    $periodStart = $run['period_start'];
    $periodEnd   = $run['period_end'];
    $payDate     = $run['pay_date'];

    // Create directory structure for payslips
    $baseDir = __DIR__ . '/../../storage/payroll/' . $companyId . '/run_' . $runId;
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }

    // Fetch employees in this run along with their names
    $stmt = $db->prepare(
        "SELECT pre.*, e.first_name, e.last_name, e.employee_no
         FROM pay_run_employees pre
         JOIN employees e ON e.id = pre.employee_id
         WHERE pre.run_id = ? AND pre.company_id = ?"
    );
    $stmt->execute([$runId, $companyId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $generated = 0;

    foreach ($employees as $emp) {
        $empId   = (int)$emp['employee_id'];
        $empName = trim($emp['first_name'] . ' ' . $emp['last_name']);
        $empNo   = $emp['employee_no'] ?? '';

        // Fetch pay lines for this employee
        $stmtLines = $db->prepare(
            "SELECT prl.qty, prl.rate_cents, prl.amount_cents, pi.name as item_name, pi.type
             FROM pay_run_lines prl
             LEFT JOIN payitems pi ON pi.id = prl.payitem_id
             WHERE prl.run_id = ? AND prl.employee_id = ? AND prl.company_id = ?
             ORDER BY pi.type ASC, prl.id ASC"
        );
        $stmtLines->execute([$runId, $empId, $companyId]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        // Build HTML slip
        $html  = '<!DOCTYPE html>';
        $html .= '<html><head><meta charset="utf-8">';
        $html .= '<title>Payslip</title>';
        // Minimal inline styles to improve readability when viewing in browser
        $html .= '<style>body{font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;}';
        $html .= 'table{border-collapse:collapse;width:100%;margin-top:10px;}';
        $html .= 'th,td{border:1px solid #ccc;padding:4px;text-align:left;}';
        $html .= 'th{background:#f5f5f5;}';
        $html .= '</style>';
        $html .= '</head><body>';
        $html .= '<h2>Payslip for ' . htmlspecialchars($empName) . '</h2>';
        if ($empNo !== '') {
            $html .= '<p>Employee Number: ' . htmlspecialchars($empNo) . '</p>';
        }
        $html .= '<p>Period: ' . htmlspecialchars($periodStart) . ' to ' . htmlspecialchars($periodEnd) . '<br>';
        $html .= 'Pay Date: ' . htmlspecialchars($payDate) . '</p>';

        $html .= '<table><thead><tr><th>Item</th><th>Type</th><th>Quantity</th><th>Rate</th><th>Amount</th></tr></thead><tbody>';
        $totalEarnings = 0.0;
        $totalDeductions = 0.0;
        foreach ($lines as $li) {
            $qty       = (float)($li['qty'] ?? 1);
            $rateCents = (int)($li['rate_cents'] ?? 0);
            $amountCts = (int)($li['amount_cents'] ?? 0);
            $rate = $rateCents / 100;
            $amount = $amountCts / 100;
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($li['item_name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($li['type'] ?? '') . '</td>';
            $html .= '<td style="text-align:right">' . ($qty == floor($qty) ? intval($qty) : number_format($qty, 2)) . '</td>';
            $html .= '<td style="text-align:right">R ' . number_format($rate, 2) . '</td>';
            $html .= '<td style="text-align:right">R ' . number_format($amount, 2) . '</td>';
            $html .= '</tr>';
            // Sum totals by type
            if (isset($li['type']) && strtolower($li['type']) === 'deduction') {
                $totalDeductions += $amount;
            } else {
                $totalEarnings += $amount;
            }
        }
        $net = $totalEarnings - $totalDeductions;
        $html .= '</tbody></table>';
        $html .= '<p><strong>Total Earnings:</strong> R ' . number_format($totalEarnings, 2) . '<br>';
        $html .= '<strong>Total Deductions:</strong> R ' . number_format($totalDeductions, 2) . '<br>';
        $html .= '<strong>Net Pay:</strong> R ' . number_format($net, 2) . '</p>';
        $html .= '</body></html>';

        // Determine file paths
        $fileName = 'payslip_' . $empId . '.html';
        $absPath  = $baseDir . '/' . $fileName;
        // Save HTML slip to disk
        file_put_contents($absPath, $html);
        // Store relative path for serving via web
        $relPath  = '/storage/payroll/' . $companyId . '/run_' . $runId . '/' . $fileName;
        // Update pay_run_employees record
        $upd = $db->prepare("UPDATE pay_run_employees SET payslip_path = ?, payslip_generated_at = NOW() WHERE company_id = ? AND run_id = ? AND employee_id = ?");
        $upd->execute([$relPath, $companyId, $runId, $empId]);
        $generated++;
    }

    return $generated;
}