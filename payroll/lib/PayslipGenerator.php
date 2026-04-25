<?php
// payroll/lib/PayslipGenerator.php
//
// Generates self-contained HTML payslips (one per employee per run) under
// /storage/payroll/{company_id}/run_{run_id}/payslip_{employee_id}.html
// and stores the relative path on pay_run_employees.payslip_path.
//
// The HTML inlines all CSS so the file renders correctly when opened
// directly, when emailed, and when printed (A4 + colour-preserving).
// Layout follows the SARS-suggested payslip structure: employer block,
// employee block, earnings, statutory & other deductions, employer
// contributions, banking details, year-to-date totals.

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

        $html = fw_render_payslip($company, $run, $emp, $lines, $ytd, $taxYearStart);

        $fileName = 'payslip_' . (int)$emp['employee_id'] . '.html';
        $absPath  = $baseDir . '/' . $fileName;
        file_put_contents($absPath, $html);

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

function fw_money(int $cents): string
{
    return 'R ' . number_format($cents / 100, 2, '.', ' ');
}

function fw_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fw_render_payslip(array $company, array $run, array $emp, array $lines, array $ytd, string $taxYearStart): string
{
    $primary = $company['primary_color'] ?? '#f97316';

    $companyName    = $company['name'] ?? 'Company';
    $companyAddress = array_filter([
        $company['address_line1'] ?? '',
        $company['address_line2'] ?? '',
        trim(($company['city'] ?? '') . ' ' . ($company['postal'] ?? '')),
        $company['region'] ?? '',
    ]);
    $companyContact = array_filter([
        !empty($company['phone']) ? 'Tel: ' . $company['phone'] : '',
        !empty($company['email']) ? $company['email'] : '',
        !empty($company['website']) ? $company['website'] : '',
    ]);
    $companyRefs = array_filter([
        !empty($company['reg_number']) ? 'Reg: ' . $company['reg_number'] : '',
        !empty($company['tax_number']) ? 'PAYE Ref: ' . $company['tax_number'] : '',
        !empty($company['vat_number']) ? 'VAT: ' . $company['vat_number'] : '',
    ]);

    $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));

    $earningLines  = [];
    $deductionLines = [];
    foreach ($lines as $li) {
        $type = strtolower((string)($li['type'] ?? 'earning'));
        if ($type === 'deduction') {
            $deductionLines[] = $li;
        } else {
            $earningLines[] = $li;
        }
    }

    $gross      = (int)($emp['gross_cents'] ?? 0);
    $taxable    = (int)($emp['taxable_income_cents'] ?? 0);
    $paye       = (int)($emp['paye_cents'] ?? 0);
    $uifEmp     = (int)($emp['uif_employee_cents'] ?? 0);
    $uifEmpr    = (int)($emp['uif_employer_cents'] ?? 0);
    $sdl        = (int)($emp['sdl_cents'] ?? 0);
    $otherDed   = (int)($emp['other_deductions_cents'] ?? 0);
    $reimburse  = (int)($emp['reimbursements_cents'] ?? 0);
    $net        = (int)($emp['net_cents'] ?? 0);
    $emprCost   = (int)($emp['employer_cost_cents'] ?? 0);
    $bankAmt    = (int)($emp['bank_amount_cents'] ?? 0);

    $statutoryDeductions = $paye + $uifEmp;
    $totalDeductions     = $statutoryDeductions + $otherDed;

    $bankLine = trim(implode(' · ', array_filter([
        $emp['bank_name'] ?? '',
        !empty($emp['bank_account_no']) ? 'Acc ' . $emp['bank_account_no'] : '',
        !empty($emp['branch_code']) ? 'Branch ' . $emp['branch_code'] : '',
    ])));

    $taxYearLabel = date('Y', strtotime($taxYearStart)) . '/' . (date('Y', strtotime($taxYearStart)) + 1);

    ob_start();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip – <?= fw_h($empName) ?> – <?= fw_h($run['name']) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: #f4f5f7;
    color: #1f2937;
    font-size: 13px;
    line-height: 1.45;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .payslip {
    max-width: 820px;
    margin: 24px auto;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,0.06);
  }
  .ps-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    padding: 24px 28px;
    background: <?= fw_h($primary) ?>;
    color: #ffffff;
  }
  .ps-header__company { flex: 1; min-width: 0; }
  .ps-header__company h1 {
    margin: 0 0 6px;
    font-size: 20px;
    font-weight: 700;
    letter-spacing: 0.2px;
  }
  .ps-header__company .ps-meta {
    font-size: 12px;
    opacity: 0.92;
  }
  .ps-header__company .ps-meta div { margin-bottom: 2px; }
  .ps-header__title {
    text-align: right;
    flex-shrink: 0;
  }
  .ps-header__title .ps-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    opacity: 0.85;
  }
  .ps-header__title h2 {
    margin: 4px 0 8px;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: 0.5px;
  }
  .ps-header__title .ps-runno {
    font-size: 12px;
    opacity: 0.92;
  }

  .ps-section { padding: 18px 28px; }
  .ps-section + .ps-section { border-top: 1px solid #eef0f3; }

  .ps-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px 24px;
  }
  .ps-field { display: flex; flex-direction: column; gap: 2px; }
  .ps-field__label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6b7280;
  }
  .ps-field__value {
    font-size: 13px;
    font-weight: 500;
    color: #111827;
  }

  .ps-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
  }
  .ps-col + .ps-col { border-left: 1px solid #eef0f3; }
  .ps-col { padding: 18px 28px; }

  .ps-block-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: <?= fw_h($primary) ?>;
    margin: 0 0 10px;
  }

  table.ps-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
  }
  table.ps-table th, table.ps-table td {
    padding: 7px 0;
    text-align: left;
    border-bottom: 1px solid #f1f2f4;
  }
  table.ps-table th {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6b7280;
    border-bottom: 1px solid #e5e7eb;
  }
  table.ps-table td.num,
  table.ps-table th.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  table.ps-table tr.ps-total td {
    border-top: 2px solid #e5e7eb;
    border-bottom: none;
    padding-top: 10px;
    font-weight: 700;
    color: #111827;
  }

  .ps-net {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 28px;
    background: <?= fw_h($primary) ?>;
    color: #ffffff;
  }
  .ps-net__label {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.12em;
  }
  .ps-net__amount {
    font-size: 24px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
  }

  .ps-employer {
    background: #fafbfc;
    font-size: 12px;
  }
  .ps-employer .ps-block-title { color: #6b7280; }
  .ps-employer p { margin: 6px 0 0; color: #6b7280; font-style: italic; }

  .ps-ytd table.ps-table th { background: #fafbfc; }

  .ps-footer {
    padding: 14px 28px 22px;
    text-align: center;
    font-size: 11px;
    color: #9ca3af;
    border-top: 1px solid #eef0f3;
  }

  @media (max-width: 640px) {
    .payslip { margin: 0; border-radius: 0; border-left: none; border-right: none; }
    .ps-header { flex-direction: column; }
    .ps-header__title { text-align: left; }
    .ps-cols { grid-template-columns: 1fr; }
    .ps-col + .ps-col { border-left: none; border-top: 1px solid #eef0f3; }
  }

  @media print {
    @page { size: A4; margin: 14mm; }
    body { background: #ffffff; font-size: 12px; }
    .payslip {
      max-width: none;
      margin: 0;
      border: none;
      border-radius: 0;
      box-shadow: none;
    }
    .ps-net, .ps-header { box-shadow: none; }
  }
</style>
</head>
<body>
  <article class="payslip">
    <header class="ps-header">
      <div class="ps-header__company">
        <h1><?= fw_h($companyName) ?></h1>
        <div class="ps-meta">
          <?php foreach ($companyAddress as $line): ?>
            <div><?= fw_h($line) ?></div>
          <?php endforeach; ?>
          <?php foreach ($companyContact as $line): ?>
            <div><?= fw_h($line) ?></div>
          <?php endforeach; ?>
          <?php if ($companyRefs): ?>
            <div style="margin-top:6px"><?= fw_h(implode('  ·  ', $companyRefs)) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="ps-header__title">
        <div class="ps-label">Payslip</div>
        <h2>PAYSLIP</h2>
        <div class="ps-runno">
          <?= fw_h($run['run_number'] ?? '') ?><br>
          <?= fw_h(date('d M Y', strtotime($run['period_start']))) ?>
          – <?= fw_h(date('d M Y', strtotime($run['period_end']))) ?>
        </div>
      </div>
    </header>

    <section class="ps-section">
      <div class="ps-grid">
        <div class="ps-field">
          <span class="ps-field__label">Employee</span>
          <span class="ps-field__value"><?= fw_h($empName) ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Employee No.</span>
          <span class="ps-field__value"><?= fw_h($emp['employee_no'] ?? '—') ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">ID Number</span>
          <span class="ps-field__value"><?= fw_h($emp['id_number'] ?? '—') ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Tax Number</span>
          <span class="ps-field__value"><?= fw_h($emp['tax_number'] ?? '—') ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Hire Date</span>
          <span class="ps-field__value">
            <?= !empty($emp['hire_date']) ? fw_h(date('d M Y', strtotime($emp['hire_date']))) : '—' ?>
          </span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Pay Frequency</span>
          <span class="ps-field__value"><?= fw_h(ucfirst((string)($emp['pay_frequency'] ?? ''))) ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Pay Date</span>
          <span class="ps-field__value"><?= fw_h(date('d M Y', strtotime($run['pay_date']))) ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Tax Year</span>
          <span class="ps-field__value"><?= fw_h($taxYearLabel) ?></span>
        </div>
      </div>
    </section>

    <section class="ps-cols">
      <div class="ps-col">
        <h3 class="ps-block-title">Earnings</h3>
        <table class="ps-table">
          <thead>
            <tr>
              <th>Description</th>
              <th class="num">Qty</th>
              <th class="num">Rate</th>
              <th class="num">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$earningLines): ?>
              <tr><td colspan="4" style="color:#9ca3af;font-style:italic">No earning lines</td></tr>
            <?php else: foreach ($earningLines as $li):
                $qty   = (float)($li['qty'] ?? 1);
                $rate  = (int)($li['rate_cents'] ?? 0);
                $amt   = (int)($li['amount_cents'] ?? 0);
                $name  = $li['item_name'] ?: $li['description'];
            ?>
              <tr>
                <td><?= fw_h($name) ?></td>
                <td class="num"><?= $qty == floor($qty) ? (int)$qty : number_format($qty, 2) ?></td>
                <td class="num"><?= fw_money($rate) ?></td>
                <td class="num"><?= fw_money($amt) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            <?php if ($reimburse > 0): ?>
              <tr>
                <td>Reimbursements</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num"><?= fw_money($reimburse) ?></td>
              </tr>
            <?php endif; ?>
            <tr class="ps-total">
              <td colspan="3">Gross Earnings</td>
              <td class="num"><?= fw_money($gross + $reimburse) ?></td>
            </tr>
            <tr>
              <td colspan="3" style="color:#6b7280;font-size:11px">Taxable Income</td>
              <td class="num" style="color:#6b7280;font-size:11px"><?= fw_money($taxable) ?></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ps-col">
        <h3 class="ps-block-title">Deductions</h3>
        <table class="ps-table">
          <thead>
            <tr>
              <th>Description</th>
              <th class="num">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>PAYE (Income Tax)</td>
              <td class="num"><?= fw_money($paye) ?></td>
            </tr>
            <tr>
              <td>UIF (Employee 1%)</td>
              <td class="num"><?= fw_money($uifEmp) ?></td>
            </tr>
            <?php foreach ($deductionLines as $li):
                $amt  = (int)($li['amount_cents'] ?? 0);
                $name = $li['item_name'] ?: $li['description'];
            ?>
              <tr>
                <td><?= fw_h($name) ?></td>
                <td class="num"><?= fw_money($amt) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($otherDed > 0 && !$deductionLines): ?>
              <tr>
                <td>Other Deductions</td>
                <td class="num"><?= fw_money($otherDed) ?></td>
              </tr>
            <?php endif; ?>
            <tr class="ps-total">
              <td>Total Deductions</td>
              <td class="num"><?= fw_money($totalDeductions) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div class="ps-net">
      <div class="ps-net__label">Net Pay</div>
      <div class="ps-net__amount"><?= fw_money($net) ?></div>
    </div>

    <section class="ps-section ps-employer">
      <h3 class="ps-block-title">Employer Contributions <small style="font-weight:500;letter-spacing:0;text-transform:none;color:#9ca3af">(not deducted from net pay)</small></h3>
      <div class="ps-grid">
        <div class="ps-field">
          <span class="ps-field__label">UIF (Employer 1%)</span>
          <span class="ps-field__value"><?= fw_money($uifEmpr) ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">SDL (1%)</span>
          <span class="ps-field__value"><?= fw_money($sdl) ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Total Cost to Company</span>
          <span class="ps-field__value"><?= fw_money($emprCost ?: ($gross + $uifEmpr + $sdl)) ?></span>
        </div>
      </div>
    </section>

    <?php if ($bankLine !== ''): ?>
    <section class="ps-section">
      <h3 class="ps-block-title">Payment Details</h3>
      <div class="ps-grid">
        <div class="ps-field">
          <span class="ps-field__label">Method</span>
          <span class="ps-field__value">EFT</span>
        </div>
        <div class="ps-field" style="grid-column: span 2">
          <span class="ps-field__label">Bank Account</span>
          <span class="ps-field__value"><?= fw_h($bankLine) ?></span>
        </div>
        <div class="ps-field">
          <span class="ps-field__label">Amount Paid</span>
          <span class="ps-field__value"><?= fw_money($bankAmt ?: $net) ?></span>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <section class="ps-section ps-ytd">
      <h3 class="ps-block-title">Year to Date (<?= fw_h($taxYearLabel) ?>)</h3>
      <table class="ps-table">
        <thead>
          <tr>
            <th>Item</th>
            <th class="num">YTD Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>Gross Earnings</td><td class="num"><?= fw_money((int)($ytd['gross'] ?? 0)) ?></td></tr>
          <tr><td>Taxable Income</td><td class="num"><?= fw_money((int)($ytd['taxable'] ?? 0)) ?></td></tr>
          <tr><td>PAYE</td><td class="num"><?= fw_money((int)($ytd['paye'] ?? 0)) ?></td></tr>
          <tr><td>UIF (Employee)</td><td class="num"><?= fw_money((int)($ytd['uif_emp'] ?? 0)) ?></td></tr>
          <tr><td>UIF (Employer)</td><td class="num"><?= fw_money((int)($ytd['uif_empr'] ?? 0)) ?></td></tr>
          <tr><td>SDL</td><td class="num"><?= fw_money((int)($ytd['sdl'] ?? 0)) ?></td></tr>
          <tr class="ps-total"><td>Net Paid</td><td class="num"><?= fw_money((int)($ytd['net'] ?? 0)) ?></td></tr>
        </tbody>
      </table>
    </section>

    <footer class="ps-footer">
      This is a computer-generated payslip and is valid without a signature.
      Generated <?= fw_h(date('d M Y H:i')) ?>.
    </footer>
  </article>
</body>
</html><?php
    return ob_get_clean();
}
