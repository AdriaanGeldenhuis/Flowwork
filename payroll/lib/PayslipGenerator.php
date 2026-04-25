<?php
// payroll/lib/PayslipGenerator.php
//
// Generates self-contained PDF payslips (one per employee per run) under
// /storage/payroll/{company_id}/run_{run_id}/payslip_{employee_id}.pdf
// and stores the relative path on pay_run_employees.payslip_path.
//
// PDF layout mirrors the HTML payslip exactly so the on-disk file, the
// browser preview and the emailed attachment all show the same document.

require_once __DIR__ . '/PayslipPdf.php';

/**
 * Generate payslips for a payroll run.
 * @return int number of payslips generated
 */
function generatePayslips(PDO $db, int $companyId, int $runId): int
{
    $stmt = $db->prepare("SELECT * FROM pay_runs WHERE id = ? AND company_id = ?");
    $stmt->execute([$runId, $companyId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        throw new Exception('Pay run not found');
    }

    $stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $baseDir = __DIR__ . '/../../storage/payroll/' . $companyId . '/run_' . $runId;
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }

    $stmt = $db->prepare(
        "SELECT pre.*, e.first_name, e.last_name, e.employee_no, e.id_number,
                e.tax_number, e.hire_date, e.employment_type, e.pay_frequency,
                e.bank_name, e.bank_account_no, e.branch_code, e.email
         FROM pay_run_employees pre
         JOIN employees e ON e.id = pre.employee_id
         WHERE pre.run_id = ? AND pre.company_id = ?"
    );
    $stmt->execute([$runId, $companyId]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $linesStmt = $db->prepare(
        "SELECT prl.qty, prl.rate_cents, prl.amount_cents, prl.description,
                pi.name AS item_name, pi.code AS item_code, pi.type
         FROM pay_run_lines prl
         LEFT JOIN payitems pi ON pi.id = prl.payitem_id
         WHERE prl.run_id = ? AND prl.employee_id = ? AND prl.company_id = ?
         ORDER BY pi.type ASC, prl.id ASC"
    );

    $taxYearStart = fw_payslip_tax_year_start($run['pay_date']);
    $ytdStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(pre.gross_cents), 0)            AS gross,
            COALESCE(SUM(pre.taxable_income_cents), 0)   AS taxable,
            COALESCE(SUM(pre.paye_cents), 0)             AS paye,
            COALESCE(SUM(pre.uif_employee_cents), 0)     AS uif_emp,
            COALESCE(SUM(pre.uif_employer_cents), 0)     AS uif_empr,
            COALESCE(SUM(pre.sdl_cents), 0)              AS sdl,
            COALESCE(SUM(pre.other_deductions_cents), 0) AS other_ded,
            COALESCE(SUM(pre.net_cents), 0)              AS net
         FROM pay_run_employees pre
         JOIN pay_runs pr ON pr.id = pre.run_id
         WHERE pre.company_id = ?
           AND pre.employee_id = ?
           AND pr.pay_date >= ?
           AND pr.pay_date <= ?
           AND pr.status IN ('locked','posted')"
    );

    $generated = 0;
    foreach ($employees as $emp) {
        $linesStmt->execute([$runId, (int)$emp['employee_id'], $companyId]);
        $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

        $ytdStmt->execute([
            $companyId,
            (int)$emp['employee_id'],
            $taxYearStart,
            $run['pay_date'],
        ]);
        $ytd = $ytdStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdfBytes = renderPayslipPdf($company, $run, $emp, $lines, $ytd, $taxYearStart);

        // Clean up the previous .html file if it still exists from a prior run.
        $oldHtml = $baseDir . '/payslip_' . (int)$emp['employee_id'] . '.html';
        if (is_file($oldHtml)) @unlink($oldHtml);

        $fileName = 'payslip_' . (int)$emp['employee_id'] . '.pdf';
        $absPath  = $baseDir . '/' . $fileName;
        file_put_contents($absPath, $pdfBytes);

        $relPath = '/storage/payroll/' . $companyId . '/run_' . $runId . '/' . $fileName;
        $upd = $db->prepare(
            "UPDATE pay_run_employees
             SET payslip_path = ?, payslip_generated_at = NOW()
             WHERE company_id = ? AND run_id = ? AND employee_id = ?"
        );
        $upd->execute([$relPath, $companyId, $runId, (int)$emp['employee_id']]);
        $generated++;
    }

    return $generated;
}

/** SA tax year starts 1 March. Returns the start date for the year covering $payDate. */
function fw_payslip_tax_year_start(string $payDate): string
{
    $ts = strtotime($payDate);
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    if ($month < 3) {
        $year--;
    }
    return $year . '-03-01';
}

