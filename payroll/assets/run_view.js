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
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount" data-label="Gross">R ${gross.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount" data-label="PAYE">R ${paye.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount" data-label="UIF">R ${uif.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount" data-label="Deductions">R ${deductions.toFixed(2)}</div>
            <div class="fw-payroll__run-emp-col fw-payroll__run-emp-col--amount fw-payroll__run-emp-net" data-label="Net Pay">R ${net.toFixed(2)}</div>
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

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

      fetch('/payroll/ajax/run_calculate.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken
        },
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
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
      // Send request to new endpoint that handles GL posting
      fetch('/payroll/ajax/run.post_gl.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken
        },
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

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

      fetch('/payroll/ajax/run_update_status.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken
        },
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
      const modal = document.getElementById('addEmployeeModal');
      const body = document.getElementById('addEmployeeModalBody');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      body.innerHTML = '<div class="fw-payroll__loading">Loading employees...</div>';

      fetch('/payroll/ajax/run_employees_available.php?run_id=' + this.runId)
        .then(res => res.json())
        .then(data => {
          if (!data.ok) {
            body.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">' + (data.error || 'Load failed') + '</div>';
            return;
          }

          if (data.employees.length === 0) {
            body.innerHTML = '<div class="fw-payroll__empty-state">No active ' + data.frequency + ' employees available to add.</div>';
            return;
          }

          let html = `
            <p class="fw-payroll__page-subtitle" style="margin-bottom:12px">
              Showing active <strong>${data.frequency}</strong> employees not yet in this run.
            </p>
            <label class="fw-payroll__checkbox-wrapper" style="margin-bottom:12px;font-weight:600">
              <input type="checkbox" class="fw-payroll__checkbox" id="addEmpSelectAll" onchange="PayrollRunView.toggleAllAvailable(this.checked)">
              Select all
            </label>
            <div class="fw-payroll__add-emp-list">
          `;

          data.employees.forEach(emp => {
            const salary = (parseFloat(emp.base_salary_cents || 0) / 100).toFixed(2);
            html += `
              <label class="fw-payroll__checkbox-wrapper fw-payroll__add-emp-row">
                <input type="checkbox" class="fw-payroll__checkbox fw-payroll__add-emp-checkbox" value="${emp.id}">
                <span class="fw-payroll__add-emp-name">${emp.first_name} ${emp.last_name}</span>
                <span class="fw-payroll__add-emp-meta">${emp.employee_no} &middot; R ${salary}</span>
              </label>
            `;
          });

          html += '</div>';
          body.innerHTML = html;
        })
        .catch(err => {
          body.innerHTML = '<div class="fw-payroll__alert fw-payroll__alert--error">Network error</div>';
          console.error(err);
        });
    },

    toggleAllAvailable: function(checked) {
      document.querySelectorAll('.fw-payroll__add-emp-checkbox').forEach(cb => {
        cb.checked = checked;
      });
    },

    closeAddEmployeeModal: function() {
      document.getElementById('addEmployeeModal').setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    },

    confirmAddEmployees: function() {
      const checked = Array.from(document.querySelectorAll('.fw-payroll__add-emp-checkbox:checked'));
      if (checked.length === 0) {
        alert('Select at least one employee to add.');
        return;
      }

      const btn = document.getElementById('addEmployeeConfirmBtn');
      btn.disabled = true;
      btn.textContent = 'Adding...';

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const params = new URLSearchParams();
      params.append('run_id', this.runId);
      checked.forEach(cb => params.append('employee_ids[]', cb.value));

      fetch('/payroll/ajax/run_employee_add.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrfToken
        },
        body: params.toString()
      })
      .then(res => res.json())
      .then(data => {
        btn.disabled = false;
        btn.textContent = 'Add Selected';
        if (data.ok) {
          this.closeAddEmployeeModal();
          location.reload();
        } else {
          alert(data.error || 'Add failed');
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.textContent = 'Add Selected';
        alert('Network error');
        console.error(err);
      });
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