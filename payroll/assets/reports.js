(function() {
  'use strict';

  window.PayrollReports = {
    init: function() {
      this.loadPayslipRuns();
    },

    loadPayslipRuns: function() {
      fetch('/payroll/ajax/run_list.php?status=locked,posted')
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            const select = document.getElementById('payslipRunSelect');
            if (select) {
              select.innerHTML = '<option value="">Select Pay Run</option>';
              data.runs.forEach(run => {
                const option = document.createElement('option');
                option.value = run.id;
                option.textContent = run.name + ' (' + this.formatDate(run.pay_date) + ')';
                select.appendChild(option);
              });
            }
          }
        })
        .catch(err => console.error(err));
    },

    formatDate: function(dateStr) {
      if (!dateStr) return '';
      const d = new Date(dateStr);
      return d.toLocaleDateString('en-ZA', {day: '2-digit', month: 'short', year: 'numeric'});
    },

    generateEMP201: function(event) {
      event.preventDefault();
      const form = event.target;
      const period = form.period.value;

      if (!period) {
        alert('Please select a period');
        return;
      }

      const output = document.getElementById('reportOutput');
      output.innerHTML = '<div class="fw-payroll__loading">Generating EMP201...</div>';

      fetch('/payroll/ajax/report_emp201.php?period=' + period)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderEMP201(data.report);
          } else {
            output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Report failed') + '</div>';
          }
        })
        .catch(err => {
          output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
          console.error(err);
        });
    },

    renderEMP201: function(report) {
      const output = document.getElementById('reportOutput');
      
      const html = `
        <div class="fw-payroll__report-output">
          <div class="fw-payroll__report-output-header">
            <h3>EMP201 Monthly Return</h3>
            <p>Period: ${report.period_label}</p>
          </div>

          <div class="fw-payroll__report-table">
            <table>
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Source Code</th>
                  <th class="fw-payroll__report-table-amount">Amount (R)</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Employees' Tax (PAYE)</td>
                  <td>3601</td>
                  <td class="fw-payroll__report-table-amount">${this.formatMoney(report.paye)}</td>
                </tr>
                <tr>
                  <td>Unemployment Insurance Fund (UIF)</td>
                  <td>3701</td>
                  <td class="fw-payroll__report-table-amount">${this.formatMoney(report.uif)}</td>
                </tr>
                <tr>
                  <td>Skills Development Levy (SDL)</td>
                  <td>3801</td>
                  <td class="fw-payroll__report-table-amount">${this.formatMoney(report.sdl)}</td>
                </tr>
                <tr class="fw-payroll__report-table-total">
                  <td colspan="2"><strong>Total Liability</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(report.total)}</strong></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="fw-payroll__report-meta">
            <p><strong>Pay Runs Included:</strong> ${report.run_count}</p>
            <p><strong>Total Employees:</strong> ${report.employee_count}</p>
            <p><strong>Gross Pay:</strong> R ${this.formatMoney(report.gross)}</p>
          </div>

          <div class="fw-payroll__report-actions">
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.downloadEMP201('${report.period}')">
              Download CSV
            </button>
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.printReport()">
              Print
            </button>
          </div>
        </div>
      `;

      output.innerHTML = html;
      output.scrollIntoView({behavior: 'smooth'});
    },

    generateEMP501: function(event) {
      event.preventDefault();
      const form = event.target;
      const period = form.period.value;

      if (!period) {
        alert('Please select a period');
        return;
      }

      const output = document.getElementById('reportOutput');
      output.innerHTML = '<div class="fw-payroll__loading">Generating EMP501...</div>';

      fetch('/payroll/ajax/report_emp501.php?period=' + period)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderEMP501(data.report);
          } else {
            output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Report failed') + '</div>';
          }
        })
        .catch(err => {
          output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
          console.error(err);
        });
    },

    renderEMP501: function(report) {
      const output = document.getElementById('reportOutput');
      
      const html = `
        <div class="fw-payroll__report-output">
          <div class="fw-payroll__report-output-header">
            <h3>EMP501 Reconciliation</h3>
            <p>Period: ${report.period_label}</p>
          </div>

          <div class="fw-payroll__report-table">
            <table>
              <thead>
                <tr>
                  <th>Month</th>
                  <th class="fw-payroll__report-table-amount">PAYE</th>
                  <th class="fw-payroll__report-table-amount">UIF</th>
                  <th class="fw-payroll__report-table-amount">SDL</th>
                  <th class="fw-payroll__report-table-amount">Total</th>
                </tr>
              </thead>
              <tbody>
                ${report.months.map(month => `
                  <tr>
                    <td>${month.label}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(month.paye)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(month.uif)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(month.sdl)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(month.total)}</td>
                  </tr>
                `).join('')}
                <tr class="fw-payroll__report-table-total">
                  <td><strong>Period Total</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(report.total_paye)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(report.total_uif)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(report.total_sdl)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(report.grand_total)}</strong></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="fw-payroll__report-actions">
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.downloadEMP501('${report.period}')">
              Download CSV
            </button>
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.printReport()">
              Print
            </button>
          </div>
        </div>
      `;

      output.innerHTML = html;
      output.scrollIntoView({behavior: 'smooth'});
    },

    exportIRP5: function(event) {
      event.preventDefault();
      const form = event.target;
      const taxYear = form.tax_year.value;

      if (!taxYear) {
        alert('Please select a tax year');
        return;
      }

      window.location.href = '/payroll/ajax/export_irp5.php?tax_year=' + taxYear;
    },

    payrollSummary: function(event) {
      event.preventDefault();
      const form = event.target;
      const period = form.period.value;

      if (!period) {
        alert('Please select a period');
        return;
      }

      const output = document.getElementById('reportOutput');
      output.innerHTML = '<div class="fw-payroll__loading">Loading payroll summary...</div>';

      fetch('/payroll/ajax/report_summary.php?period=' + period)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderSummary(data.summary);
          } else {
            output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Report failed') + '</div>';
          }
        })
        .catch(err => {
          output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
          console.error(err);
        });
    },

    renderSummary: function(summary) {
      const output = document.getElementById('reportOutput');
      
      const html = `
        <div class="fw-payroll__report-output">
          <div class="fw-payroll__report-output-header">
            <h3>Payroll Summary</h3>
            <p>Period: ${summary.period_label}</p>
          </div>

          <div class="fw-payroll__report-table">
            <table>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th class="fw-payroll__report-table-amount">Gross</th>
                  <th class="fw-payroll__report-table-amount">PAYE</th>
                  <th class="fw-payroll__report-table-amount">UIF</th>
                  <th class="fw-payroll__report-table-amount">Deductions</th>
                  <th class="fw-payroll__report-table-amount">Net</th>
                </tr>
              </thead>
              <tbody>
                ${summary.employees.map(emp => `
                  <tr>
                    <td>${emp.name}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(emp.gross)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(emp.paye)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(emp.uif)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(emp.deductions)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(emp.net)}</td>
                  </tr>
                `).join('')}
                <tr class="fw-payroll__report-table-total">
                  <td><strong>Total</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(summary.total_gross)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(summary.total_paye)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(summary.total_uif)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(summary.total_deductions)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(summary.total_net)}</strong></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="fw-payroll__report-actions">
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.downloadSummary('${summary.period}')">
              Download CSV
            </button>
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.printReport()">
              Print
            </button>
          </div>
        </div>
      `;

      output.innerHTML = html;
      output.scrollIntoView({behavior: 'smooth'});
    },

    costAllocation: function(event) {
      event.preventDefault();
      const form = event.target;
      const period = form.period.value;

      if (!period) {
        alert('Please select a period');
        return;
      }

      const output = document.getElementById('reportOutput');
      output.innerHTML = '<div class="fw-payroll__loading">Loading cost allocation...</div>';

      fetch('/payroll/ajax/report_cost_allocation.php?period=' + period)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderCostAllocation(data.allocation);
          } else {
            output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Report failed') + '</div>';
          }
        })
        .catch(err => {
          output.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
          console.error(err);
        });
    },

    renderCostAllocation: function(allocation) {
      const output = document.getElementById('reportOutput');
      
      const html = `
        <div class="fw-payroll__report-output">
          <div class="fw-payroll__report-output-header">
            <h3>Project Cost Allocation</h3>
            <p>Period: ${allocation.period_label}</p>
          </div>

          <div class="fw-payroll__report-table">
            <table>
              <thead>
                <tr>
                  <th>Project</th>
                  <th class="fw-payroll__report-table-amount">Wage Cost</th>
                  <th class="fw-payroll__report-table-amount">Employer Cost</th>
                  <th class="fw-payroll__report-table-amount">Total</th>
                </tr>
              </thead>
              <tbody>
                ${allocation.projects.map(proj => `
                  <tr>
                    <td>${proj.name}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(proj.wage_cost)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(proj.employer_cost)}</td>
                    <td class="fw-payroll__report-table-amount">${this.formatMoney(proj.total_cost)}</td>
                  </tr>
                `).join('')}
                <tr class="fw-payroll__report-table-total">
                  <td><strong>Total</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(allocation.total_wage)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(allocation.total_employer)}</strong></td>
                  <td class="fw-payroll__report-table-amount"><strong>${this.formatMoney(allocation.grand_total)}</strong></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="fw-payroll__report-actions">
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.downloadCostAllocation('${allocation.period}')">
              Download CSV
            </button>
            <button class="fw-payroll__btn fw-payroll__btn--secondary" onclick="PayrollReports.printReport()">
              Print
            </button>
          </div>
        </div>
      `;

      output.innerHTML = html;
      output.scrollIntoView({behavior: 'smooth'});
    },

    viewPayslips: function(event) {
      event.preventDefault();
      const form = event.target;
      const runId = form.run_id.value;

      if (!runId) {
        alert('Please select a pay run');
        return;
      }

      window.location.href = '/payroll/payslips.php?run_id=' + runId;
    },

    formatMoney: function(cents) {
      const amount = parseFloat(cents) / 100;
      return amount.toLocaleString('en-ZA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    },

    downloadEMP201: function(period) {
      window.location.href = '/payroll/ajax/report_emp201.php?period=' + period + '&format=csv';
    },

    downloadEMP501: function(period) {
      window.location.href = '/payroll/ajax/report_emp501.php?period=' + period + '&format=csv';
    },

    downloadSummary: function(period) {
      window.location.href = '/payroll/ajax/report_summary.php?period=' + period + '&format=csv';
    },

    downloadCostAllocation: function(period) {
      window.location.href = '/payroll/ajax/report_cost_allocation.php?period=' + period + '&format=csv';
    },

    printReport: function() {
      window.print();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => PayrollReports.init());
  } else {
    PayrollReports.init();
  }
})();