(function() {
  'use strict';

  window.PayrollRunView = {
    runId: window.RUN_ID,
    status: window.RUN_STATUS,
    canEdit: window.CAN_EDIT,

    init: function() {
      this.loadEmployees();
    },

    loadEmployees: function() {
      const container = document.getElementById('employeesList');
      if (!container) return;

      container.innerHTML = '<div class="fw-payroll__loading">Loading employees...</div>';

      fetch('/payroll/ajax/run_employees.php?run_id=' + this.runId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderEmployees(data.employees);
          } else {
            container.innerHTML = '<div class="fw-payroll__loading">Error: ' + (data.error || 'Unknown') + '</div>';
          }
        })
        .catch(err => {
          container.innerHTML = '<div class="fw-payroll__loading">Network error</div>';
          console.error(err);
        });
    },

    renderEmployees: function(employees) {
      const container = document.getElementById('employeesList');
      
      if (employees.length === 0) {
        container.innerHTML = `
          <div class="fw-payroll__empty-state">
            No employees in this run yet.
            ${this.canEdit ? '<br><button class="fw-payroll__btn fw-payroll__btn--primary" style="margin-top:16px" onclick="PayrollRunView.addEmployee()">+ Add Employees</button>' : ''}
          </div>
        `;
        return;
      }

      let html = '<div class="fw-payroll__run-employees-table">';
      html += `
        <div class="fw-payroll__run-employees-header">
          <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--name">Employee</div>
          <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">Gross</div>
          <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">PAYE</div>
          <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">UIF</div>
          <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">Deductions</div>
          <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">Net Pay</div>
          <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--actions"></div>
        </div>
      `;

      employees.forEach(emp => {
        const gross = parseFloat(emp.gross_cents || 0) / 100;
        const paye = parseFloat(emp.paye_cents || 0) / 100;
        const uif = parseFloat(emp.uif_employee_cents || 0) / 100;
        const deductions = parseFloat(emp.other_deductions_cents || 0) / 100;
        const net = parseFloat(emp.net_cents || 0) / 100;

        html += `
          <div class="fw-payroll__run-employees-row" onclick="PayrollRunView.viewEmployee(${emp.employee_id})">
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--name">
              <div class="fw-payroll__run-emp-avatar">${emp.initials}</div>
              <div>
                <div class="fw-payroll__run-emp-name">${emp.employee_name}</div>
                <div class="fw-payroll__run-emp-no">${emp.employee_no}</div>
              </div>
            </div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">R ${gross.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">R ${paye.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">R ${uif.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount">R ${deductions.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount fw-payroll__run-emp-net">R ${net.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--actions">
              <button class="fw-payroll__icon-btn" onclick="event.stopPropagation(); PayrollRunView.viewEmployee(${emp.employee_id})" title="View Details">
                <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2"/>
                  <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                </svg>
              </button>
            </div>
          </div>
        `;
      });

      html += '</div>';
      container.innerHTML = html;
    },

    calculate: function() {
      if (!confirm('Calculate payroll for all employees in this run?')) return;

      const msgDiv = document.getElementById('actionMessage');
      msgDiv.innerHTML = '<div class="fw-payroll__loading">Calculating payroll... This may take a moment.</div>';

      fetch('/payroll/ajax/run_calculate.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'run_id=' + this.runId
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--success">Payroll calculated successfully! Processed ' + data.count + ' employees.</div>';
          setTimeout(() => {
            location.reload();
          }, 2000);
        } else {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Calculation failed') + '</div>';
        }
      })
      .catch(err => {
        msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
        console.error(err);
      });
    },

    moveToReview: function() {
      this.updateStatus('review');
    },

    approve: function() {
      if (!confirm('Approve this pay run? You will be able to lock and post after approval.')) return;
      this.updateStatus('approved');
    },

    lock: function() {
      if (!confirm('Lock this pay run and generate payslips? You cannot edit after locking.')) return;
      this.updateStatus('locked');
    },

    post: function() {
      // Confirm posting to general ledger
      if (!confirm('Post this pay run to Finance? This will create journal entries.')) return;
      const msgDiv = document.getElementById('actionMessage');
      msgDiv.innerHTML = '<div class="fw-payroll__loading">Posting to finance...</div>';
      // Send request to new endpoint that handles GL posting
      fetch('/payroll/ajax/run.post_gl.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'run_id=' + this.runId
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--success">Pay run posted successfully! Reloading...</div>';
          setTimeout(() => location.reload(), 1500);
        } else {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Posting failed') + '</div>';
        }
      })
      .catch(err => {
        msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
        console.error(err);
      });
    },

    updateStatus: function(newStatus) {
      const msgDiv = document.getElementById('actionMessage');
      msgDiv.innerHTML = '<div class="fw-payroll__loading">Updating status...</div>';

      fetch('/payroll/ajax/run_update_status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'run_id=' + this.runId + '&status=' + newStatus
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--success">Status updated! Reloading...</div>';
          setTimeout(() => location.reload(), 1500);
        } else {
          msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Update failed') + '</div>';
        }
      })
      .catch(err => {
        msgDiv.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
        console.error(err);
      });
    },

    addEmployee: function() {
      alert('Add Employee feature coming in next batch!');
      // Will implement: modal to select employees not yet in run
    },

    viewEmployee: function(employeeId) {
      document.getElementById('employeeModal').setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      const body = document.getElementById('employeeModalBody');
      body.innerHTML = '<div class="fw-payroll__loading">Loading employee details...</div>';

      fetch('/payroll/ajax/run_employee_detail.php?run_id=' + this.runId + '&employee_id=' + employeeId)
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            this.renderEmployeeDetail(data.employee, data.lines);
          } else {
            body.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Load failed') + '</div>';
          }
        })
        .catch(err => {
          body.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
          console.error(err);
        });
    },

    renderEmployeeDetail: function(emp, lines) {
      const body = document.getElementById('employeeModalBody');
      
      document.getElementById('employeeModalTitle').textContent = emp.employee_name;

      const gross = parseFloat(emp.gross_cents || 0) / 100;
      const taxable = parseFloat(emp.taxable_income_cents || 0) / 100;
      const paye = parseFloat(emp.paye_cents || 0) / 100;
      const uifEmp = parseFloat(emp.uif_employee_cents || 0) / 100;
      const uifEmpr = parseFloat(emp.uif_employer_cents || 0) / 100;
      const sdl = parseFloat(emp.sdl_cents || 0) / 100;
      const deductions = parseFloat(emp.other_deductions_cents || 0) / 100;
      const net = parseFloat(emp.net_cents || 0) / 100;
      const employerCost = parseFloat(emp.employer_cost_cents || 0) / 100;

      let html = `
        <div class="fw-payroll__emp-detail">
          <div class="fw-payroll__emp-detail-section">
            <h4 class="fw-payroll__emp-detail-title">Summary</h4>
            <div class="fw-payroll__emp-detail-grid">
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">Gross Pay</span>
                <span class="fw-payroll__emp-detail-value">R ${gross.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">Taxable Income</span>
                <span class="fw-payroll__emp-detail-value">R ${taxable.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">PAYE</span>
                <span class="fw-payroll__emp-detail-value">R ${paye.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">UIF Employee</span>
                <span class="fw-payroll__emp-detail-value">R ${uifEmp.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">UIF Employer</span>
                <span class="fw-payroll__emp-detail-value">R ${uifEmpr.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">SDL</span>
                <span class="fw-payroll__emp-detail-value">R ${sdl.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">Other Deductions</span>
                <span class="fw-payroll__emp-detail-value">R ${deductions.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item fw-payroll__emp-detail-item--highlight">
                <span class="fw-payroll__emp-detail-label">Net Pay</span>
                <span class="fw-payroll__emp-detail-value">R ${net.toFixed(2)}</span>
              </div>
              <div class="fw-payroll__emp-detail-item">
                <span class="fw-payroll__emp-detail-label">Employer Cost</span>
                <span class="fw-payroll__emp-detail-value">R ${employerCost.toFixed(2)}</span>
              </div>
            </div>
          </div>

          <div class="fw-payroll__emp-detail-section">
            <h4 class="fw-payroll__emp-detail-title">Line Items</h4>
            <div class="fw-payroll__emp-lines">
      `;

      if (lines.length === 0) {
        html += '<div class="fw-payroll__empty-state">No line items yet</div>';
      } else {
        lines.forEach(line => {
          const amount = parseFloat(line.amount_cents || 0) / 100;
          const qty = parseFloat(line.qty || 0);
          const rate = parseFloat(line.rate_cents || 0) / 100;

          html += `
            <div class="fw-payroll__emp-line">
              <div class="fw-payroll__emp-line-desc">
                <strong>${line.payitem_name || line.description}</strong>
                ${line.payitem_code ? '<span class="fw-payroll__emp-line-code">' + line.payitem_code + '</span>' : ''}
              </div>
              <div class="fw-payroll__emp-line-calc">
                ${qty} × R ${rate.toFixed(2)}
              </div>
              <div class="fw-payroll__emp-line-amount">
                R ${amount.toFixed(2)}
              </div>
            </div>
          `;
        });
      }

      html += `
            </div>
          </div>
        </div>
      `;

      body.innerHTML = html;
    },

    closeEmployeeModal: function() {
      document.getElementById('employeeModal').setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    },

    exportBank: function() {
      window.location.href = '/payroll/ajax/run_export_bank.php?run_id=' + this.runId;
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => PayrollRunView.init());
  } else {
    PayrollRunView.init();
  }
})();